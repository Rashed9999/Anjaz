<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\AccountRecoveryRequest;
use App\Services\AccountRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-RECOVERY-001
 *
 * APIs (user side):
 *   POST /api/v1/amial/recovery/initiate-self    — يبدأ تغيير رقم (يملك القديم)
 *   POST /api/v1/amial/recovery/initiate-lost    — يبدأ استرداد (فقد الرقم)
 *   POST /api/v1/amial/recovery/{ulid}/verify-otp — يحقق OTPs
 *   POST /api/v1/amial/recovery/{ulid}/complete   — يكمل بعد OTP + PIN
 *   GET  /api/v1/amial/recovery/{ulid}             — حالة الطلب
 *
 * (admin endpoints في AdminAccountRecoveryController)
 */
class AccountRecoveryController extends Controller
{
    public function __construct(
        private readonly AccountRecoveryService $service,
    ) {}

    /**
     * POST /api/v1/amial/recovery/initiate-self
     */
    public function initiateSelf(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'new_phone' => 'required|string|min:6|max:20',
        ]);
        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid input', $validator->errors(), 422);
        }

        try {
            $req = $this->service->initiateSelfServicePhoneChange(
                user: $user,
                newPhone: $request->input('new_phone'),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (\RuntimeException $e) {
            return $this->error('RECOVERY_NOT_ELIGIBLE', $e->getMessage(), [], 403);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'RECOVERY_OTP_SENT',
            'message' => 'OTP sent to both old and new phones',
            'errors' => (object)[],
            'meta' => [
                'request_ulid' => $req->request_ulid,
                'otp_expires_at' => $req->otp_expires_at?->toIso8601String(),
                'request_expires_at' => $req->expires_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/amial/recovery/initiate-lost
     */
    public function initiateLost(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'new_phone' => 'required|string|min:6|max:20',
            'identification_documents' => 'required|array|min:1|max:5',
            'identification_documents.*' => 'string|max:255', // مسارات (تُرفع عبر endpoint منفصل)
            'user_notes' => 'sometimes|string|max:500',
        ]);
        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid input', $validator->errors(), 422);
        }

        try {
            $req = $this->service->initiateLostPhoneRecovery(
                user: $user,
                newPhone: $request->input('new_phone'),
                identificationDocuments: $request->input('identification_documents'),
                userNotes: $request->input('user_notes'),
                ip: $request->ip(),
                userAgent: $request->userAgent(),
            );
        } catch (\RuntimeException $e) {
            return $this->error('RECOVERY_NOT_ELIGIBLE', $e->getMessage(), [], 403);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'RECOVERY_PENDING_REVIEW',
            'message' => 'Recovery request submitted for admin review',
            'errors' => (object)[],
            'meta' => [
                'request_ulid' => $req->request_ulid,
                'status' => $req->status,
                'expires_at' => $req->expires_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/amial/recovery/{ulid}/verify-otp
     */
    public function verifyOtp(Request $request, string $ulid): JsonResponse
    {
        $req = AccountRecoveryRequest::where('request_ulid', $ulid)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$req) {
            return $this->error('RECOVERY_NOT_FOUND', 'Recovery request not found', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'otp_old' => 'required|string|size:6',
            'otp_new' => 'required|string|size:6',
        ]);
        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid input', $validator->errors(), 422);
        }

        $ok = $this->service->verifyOtp(
            $req,
            $request->input('otp_old'),
            $request->input('otp_new'),
        );

        return new JsonResponse([
            'success' => $ok,
            'code' => $ok ? 'RECOVERY_OTP_VERIFIED' : 'RECOVERY_OTP_INVALID',
            'message' => $ok ? 'OTPs verified' : 'One or both OTPs are invalid or expired',
            'errors' => (object)[],
            'meta' => [
                'request_ulid' => $req->request_ulid,
            ],
        ], $ok ? 200 : 422);
    }

    /**
     * POST /api/v1/amial/recovery/{ulid}/complete
     */
    public function complete(Request $request, string $ulid): JsonResponse
    {
        $req = AccountRecoveryRequest::where('request_ulid', $ulid)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$req) {
            return $this->error('RECOVERY_NOT_FOUND', 'Recovery request not found', [], 404);
        }

        $validator = Validator::make($request->all(), [
            'pin' => 'required|string|min:4|max:6',
        ]);
        if ($validator->fails()) {
            return $this->error('VALIDATION_FAILED', 'Invalid input', $validator->errors(), 422);
        }

        $ok = $this->service->completeSelfServiceChange(
            $req,
            $request->input('pin'),
        );

        if (!$ok) {
            return $this->error(
                'RECOVERY_COMPLETE_FAILED',
                'Could not complete — verify OTP first or PIN is invalid',
                [],
                422,
            );
        }

        $req->refresh();
        return new JsonResponse([
            'success' => true,
            'code' => 'RECOVERY_HOLD_APPLIED',
            'message' => 'Phone change accepted, security hold applied',
            'errors' => (object)[],
            'meta' => [
                'request_ulid' => $req->request_ulid,
                'status' => $req->status,
                'risk_score' => $req->risk_score,
            ],
        ]);
    }

    /**
     * GET /api/v1/amial/recovery/{ulid}
     */
    public function show(Request $request, string $ulid): JsonResponse
    {
        $req = AccountRecoveryRequest::where('request_ulid', $ulid)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$req) {
            return $this->error('RECOVERY_NOT_FOUND', 'Recovery request not found', [], 404);
        }

        return new JsonResponse([
            'success' => true,
            'code' => 'RECOVERY_OK',
            'message' => 'Recovery request',
            'errors' => (object)[],
            'meta' => [
                'request_ulid' => $req->request_ulid,
                'request_type' => $req->request_type,
                'status' => $req->status,
                'risk_score' => $req->risk_score,
                'expires_at' => $req->expires_at?->toIso8601String(),
                'reviewed_at' => $req->reviewed_at?->toIso8601String(),
                'admin_notes_excerpt' => $req->admin_notes
                    ? mb_substr($req->admin_notes, 0, 200)
                    : null,
            ],
        ]);
    }

    private function error(string $code, string $message, $errors, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => is_object($errors) ? $errors : ((array)$errors ?: (object)[]),
            'meta' => (object)[],
        ], $status);
    }
}
