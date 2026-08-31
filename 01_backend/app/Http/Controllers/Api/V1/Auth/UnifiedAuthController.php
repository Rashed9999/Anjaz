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
        // AMIAL-DIAG: تسجيل واضح لكل طلب دخول (يظهر في سجلّ Railway لتشخيص التطبيق)
        \Log::info('🔐 LOGIN REQUEST', [
            'role' => $role,
            'phone' => $request->input('phone'),
            'has_password' => $request->filled('password'),
        ]);

        if (!$role) {
            return $this->error('ROLE_REQUIRED', 'يجب تحديد الدور (role)', 422);
        }

        try {
            $resp = match ($role) {
                'customer' => $this->customerLogin($request),
                'merchant' => $this->merchantLogin($request),
                'agent' => $this->agentLoginStep1($request),
                'admin' => $this->adminLogin($request),
                default => $this->error('INVALID_ROLE', 'دور غير معروف', 422),
            };
            \Log::info('🔐 LOGIN RESULT', ['role' => $role, 'status' => $resp->getStatusCode()]);
            return $resp;
        } catch (\RuntimeException $e) {
            \Log::warning('🔐 LOGIN FAILED', ['role' => $role, 'reason' => $e->getMessage()]);
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
        // AMIAL-FIX: دخول العميل بالهاتف + كلمة السرّ (كأغلب المحافظ) — national_id
        // يخصّ توثيق KYC لا بوّابة الدخول، والتسجيل يجمع الهاتف+كلمة السرّ فقط.
        $v = Validator::make($request->all(), [
            'phone' => 'required|string|min:6|max:30',
            'password' => 'required|string|min:4|max:200',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $result = $this->auth->loginCustomer(
            $request->input('phone'),
            $request->input('password'),
            $request,
        );
        return $this->success($result);
    }

    /**
     * AMIAL-POS-LOGIN-SIMPLE-001 — **الجهازُ يعرف تاجرَه، فلا يُكتبان.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **الثمنُ الذي قِيس:** الموظّفُ يكتب أربعةَ حقولٍ ليدخل، **اثنان
     * منها ليسا له**: رقمُ التاجر وجوّالُ التاجر. أي أنّ الكاشيرَ يحفظ
     * رقمَ هاتف مديره ليبدأ يومَه.
     *
     * **والجهازُ يحملهما أصلاً:** `merchant_pos_devices.merchant_user_id`
     * يُكتب لحظةَ التفعيل بالرمز ذي الأرقام الثمانية. فيُقرآن منه.
     *
     *   قبل : رقم التاجر · جوّال التاجر · رمز الموظّف · كلمة المرور
     *   بعد : رمز الموظّف · كلمة المرور
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولا يُكسَر أحدٌ يعمل الآن — وهذا شرطُ التنفيذ لا زينةٌ عليه:**
     *
     * ① الاشتقاقُ **يملأ الفارغَ ولا يستبدل المكتوب**. فمن أرسل الأربعةَ
     *    يمرّ كما كان حرفاً بحرف، وتطبيقٌ قديمٌ لا يتأثّر.
     * ② وإن تعذّر الاشتقاقُ سقط التحقّقُ كما كان **بالرسالة نفسِها** —
     *    فلا رسالةَ جديدةٌ تُربك من يقرؤها.
     * ③ و**الغموضُ يُرفَض ولا يُخمَّن**: بصمةُ جهازٍ تُطابق أكثرَ من صفّ
     *    لا تُشتقّ منها هويّة. (القاعدة السابعة: «غير معروف» ليس صفراً.)
     *
     * **والأمانُ يزيد لا ينقص:** الدخولُ يصير مربوطاً بجهازٍ **مفعَّلٍ
     * بعينه** بدل أن يكفيَ من يعرف الأرقامَ الأربعة.
     * ══════════════════════════════════════════════════════════════════
     */
    private function fillMerchantIdentityFromDevice(Request $request): void
    {
        // **① لا يُستبدَل مكتوب.**
        if ($request->filled('merchant_number') && $request->filled('phone')) {
            return;
        }

        $raw = trim((string) $request->header(\App\Http\Middleware\EnsurePosDevice::HEADER, ''));

        if ($raw === '') {
            return;
        }

        // البصمةُ الحاليّةُ ومفاتيحُ التدوير السابقة — فجهازٌ سُجّل قبل
        // تدويرٍ لا يفقد بابَه.
        $hashes = [\App\Models\Merchant\PosDevice::hashUuid($raw)];
        foreach (\App\Models\Merchant\PosDevice::previousKeys() as $key) {
            $hashes[] = \App\Models\Merchant\PosDevice::hashUuid($raw, $key);
        }

        $devices = \App\Models\Merchant\PosDevice::whereIn('device_uuid_hash', $hashes)
            ->whereNull('revoked_at')
            ->where('is_active', true)
            ->limit(2)
            ->get();

        // **③ الغموضُ يُرفَض** — صفٌّ واحدٌ بالضبط أو لا اشتقاق.
        if ($devices->count() !== 1) {
            return;
        }

        $ownerId = (int) $devices->first()->merchant_user_id;

        $owner = \App\Models\User::find($ownerId);
        $number = \App\Models\Merchant::where('user_id', $ownerId)->value('merchant_number');

        if ($owner === null || $number === null) {
            return;
        }

        $request->merge(array_filter([
            'merchant_number' => $request->filled('merchant_number') ? null : $number,
            'phone' => $request->filled('phone') ? null : $owner->phone,
        ], fn ($v) => $v !== null));
    }

    private function merchantLogin(Request $request): JsonResponse
    {
        $this->fillMerchantIdentityFromDevice($request);

        $v = Validator::make($request->all(), [
            'merchant_number' => 'required|string|min:3|max:50',
            'phone' => 'required|string|min:6|max:30',
            // كلمة السرّ قد تكون PIN من 4 أرقام (معيار التسجيل الذاتي)
            'password' => 'required|string|min:4|max:200',
            'employee_code' => 'sometimes|nullable|string|max:50',
            // اسم الحقل القديم مدعوم لنسخ التطبيق السابقة فقط.
            'pos_number' => 'sometimes|nullable|string|max:50',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $result = $this->auth->loginMerchant(
            $request->input('merchant_number'),
            $request->input('phone'),
            $request->input('password'),
            $request->input('employee_code', $request->input('pos_number')),
            $request,
        );
        return $this->success($result);
    }

    private function agentLoginStep1(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'agent_number' => 'required|string|min:3|max:50',
            // كلمة السرّ قد تكون PIN من 4 أرقام (معيار التسجيل الذاتي)
            'password' => 'required|string|min:4|max:200',
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
                    // AMIAL-CRASH-001: يربطه التطبيق بتقارير الأعطال، فيُعرف
                    // هل العطل محصور بمنطقة — وهو ما يفرّق عطل الإعداد عن عطل
                    // الشيفرة — بدل أن يبقى «ينهار عند بعض المستخدمين».
                    'zone_code' => $result['user']->zone_code,
                    // AMIAL-VERIFY-GATE: حالة التوثيق ليقرّر التطبيق أي شاشة يفتح.
                    // 0 = قيد المراجعة (لوحة التحقق لم تعتمده بعد) / 1 = موثّق / 2 = مرفوض
                    'is_kyc_verified' => (int) ($result['user']->is_kyc_verified ?? 0),
                    'verification_state' => match ((int) ($result['user']->is_kyc_verified ?? 0)) {
                        1 => 'verified',
                        2 => 'rejected',
                        default => 'pending_review',
                    },
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
