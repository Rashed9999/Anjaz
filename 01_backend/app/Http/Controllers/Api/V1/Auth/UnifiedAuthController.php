<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Services\UnifiedAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-UNIFIED-AUTH-001 (v1.5)
 *
 * UnifiedAuthController — endpoint موحد لكل الأدوار.
 *
 * **Endpoint:**
 *   POST /api/v1/auth/login
 *   {
 *     "role": "customer" | "merchant" | "agent" | "admin",
 *     ...(الحقول حسب الدور)
 *   }
 *
 * **الردود الخاصة:**
 *   - Agent step 1 success → 200 with otp_token
 *   - Admin requires 2FA → 200 with code: TWO_FACTOR_REQUIRED
 */
class UnifiedAuthController extends Controller
{
    public function __construct(
        private readonly UnifiedAuthService $auth,
    ) {}

    /**
     * POST /api/v1/auth/login
     */
    public function login(Request $request): JsonResponse
    {
        $role = $request->input('role');
        if (!$role) {
            return $this->error('ROLE_REQUIRED', 'يجب تحديد الدور (role)', 422);
        }

        try {
            return match ($role) {
                'customer' => $this->customerLogin($request),
                'merchant' => $this->merchantLogin($request),
                'agent' => $this->agentLoginStep1($request),
                'admin' => $this->adminLogin($request),
                default => $this->error('INVALID_ROLE', 'دور غير معروف', 422),
            };
        } catch (\RuntimeException $e) {
            return $this->error('AUTH_FAILED', $e->getMessage(), 401);
        }
    }

    /**
     * POST /api/v1/auth/agent/verify-otp
     */
    public function agentVerifyOtp(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'otp_token' => 'required|string|size:26',
            'otp_code' => 'required|string|size:6',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $result = $this->auth->loginAgentStep2(
                $request->input('otp_token'),
                $request->input('otp_code'),
                $request,
            );
            return $this->success($result);
        } catch (\RuntimeException $e) {
            return $this->error('OTP_FAILED', $e->getMessage(), 401);
        }
    }

    // ============================================================
    // Per-role private handlers
    // ============================================================

    private function customerLogin(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'national_id' => 'required|string|min:5|max:30',
            'phone' => 'required|string|min:6|max:30',
            'password' => 'required|string|min:6|max:200',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $result = $this->auth->loginCustomer(
            $request->input('national_id'),
            $request->input('phone'),
            $request->input('password'),
            $request,
        );
        return $this->success($result);
    }

    private function merchantLogin(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'merchant_number' => 'required|string|min:3|max:50',
            'phone' => 'required|string|min:6|max:30',
            'password' => 'required|string|min:6|max:200',
            'pos_number' => 'sometimes|nullable|string|max:50',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $result = $this->auth->loginMerchant(
            $request->input('merchant_number'),
            $request->input('phone'),
            $request->input('password'),
            $request->input('pos_number'),
            $request,
        );
        return $this->success($result);
    }

    private function agentLoginStep1(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'agent_number' => 'required|string|min:3|max:50',
            'password' => 'required|string|min:6|max:200',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $result = $this->auth->loginAgentStep1(
            $request->input('agent_number'),
            $request->input('password'),
            $request,
        );

        return new JsonResponse([
            'success' => true,
            'code' => 'OTP_SENT',
            'message' => "تم إرسال رمز التحقق إلى {$result['masked_phone']}",
            'errors' => (object)[],
            'meta' => [
                'otp_token' => $result['otp_token'],
                'masked_phone' => $result['masked_phone'],
                'expires_in_seconds' => $result['expires_in_seconds'],
                'next_step' => 'agent_verify_otp',
            ],
        ]);
    }

    private function adminLogin(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'password' => 'required|string|min:6|max:200',
            'two_factor_code' => 'sometimes|nullable|string|size:6',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $result = $this->auth->loginAdmin(
                $request->input('email'),
                $request->input('password'),
                $request->input('two_factor_code'),
                $request,
            );
            return $this->success($result);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'TWO_FACTOR_REQUIRED') {
                return new JsonResponse([
                    'success' => false,
                    'code' => 'TWO_FACTOR_REQUIRED',
                    'message' => 'أدخل رمز التحقق الثاني (TOTP)',
                    'errors' => (object)[],
                    'meta' => [],
                ], 200); // 200 not 401 — partial success
            }
            throw $e;
        }
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function success(array $result): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'code' => 'LOGIN_OK',
            'message' => 'تم تسجيل الدخول بنجاح',
            'errors' => (object)[],
            'meta' => [
                'user' => [
                    'id' => $result['user']->id,
                    'role' => $result['role'],
                    'name' => $this->getUserDisplayName($result['user']),
                ],
                'token' => $result['token'],
                'token_type' => $result['token_type'],
                'role' => $result['role'],
                ...($result['meta'] ?? []),
            ],
        ]);
    }

    private function getUserDisplayName($user): string
    {
        $parts = array_filter([$user->f_name ?? null, $user->l_name ?? null]);
        return !empty($parts) ? implode(' ', $parts) : ($user->phone_masked ?? 'مستخدم');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(),
            'meta' => (object)[],
        ], 422);
    }
}
