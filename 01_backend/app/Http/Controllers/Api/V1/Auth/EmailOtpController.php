<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Otp\EmailOtpService;
use App\Services\TransactionPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-EMAIL-OTP-001
 *
 * Public email OTP entrypoints used during the pilot. Existing phone/SMS OTP
 * endpoints remain untouched and can be re-enabled by configuration later.
 */
class EmailOtpController extends Controller
{
    public function __construct(
        private readonly EmailOtpService $otp,
        private readonly TransactionPinService $pins,
        private readonly AuditService $audit,
    ) {}

    public function requestCode(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'email' => 'required|email|max:320',
            'purpose' => 'required|in:registration,password_reset,pin_recovery',
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        $email = $this->otp->normalizeEmail((string) $request->input('email'));
        $purpose = (string) $request->input('purpose');

        $user = User::whereRaw('LOWER(email) = ?', [$email])
            ->when(Schema::hasColumn('users', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
            ->first();

        if ($purpose === EmailOtpService::PURPOSE_REGISTRATION && $user) {
            return $this->error('EMAIL_IN_USE', 'البريد الإلكتروني مستخدم في حساب آخر', 422);
        }

        if ($purpose !== EmailOtpService::PURPOSE_REGISTRATION && !$user) {
            // لا نكشف إن كان البريد مسجلاً أم لا. نفس بنية الرد، ولا يُرسل شيء.
            return $this->ok([
                'challenge_id' => (string) Str::ulid(),
                'masked_email' => $this->otp->maskEmail($email),
                'expires_in_seconds' => max(1, (int) config('amial_otp.ttl_minutes', 5)) * 60,
                'resend_after_seconds' => max(30, (int) config('amial_otp.resend_seconds', 60)),
                'delivery_status' => 'accepted',
            ], 'OTP_ACCEPTED', 'إذا كان البريد مسجلاً فسيصلك رمز التحقق');
        }

        try {
            $meta = $this->otp->issue(
                email: $email,
                purpose: $purpose,
                userId: $user?->id,
                requestedByType: 'user',
                requestedById: $user?->id,
                meta: ['ip' => $request->ip()],
            );
        } catch (RuntimeException $e) {
            return $this->otpError($e);
        }

        return $this->ok($meta, 'OTP_SENT', 'تم إرسال رمز التحقق إلى البريد الإلكتروني');
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'challenge_id' => 'required|string|size:26',
            'email' => 'required|email|max:320',
            'purpose' => 'required|in:registration,password_reset,pin_recovery',
            'otp' => 'required|digits:6',
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        try {
            $token = $this->otp->verify(
                (string) $request->input('challenge_id'),
                (string) $request->input('email'),
                (string) $request->input('purpose'),
                (string) $request->input('otp'),
            );
        } catch (RuntimeException $e) {
            return $this->otpError($e);
        }

        return $this->ok([
            'verification_token' => $token,
            'verification_expires_in_seconds' => max(2, (int) config('amial_otp.verification_ttl_minutes', 10)) * 60,
        ], 'OTP_VERIFIED', 'تم التحقق من الرمز');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'challenge_id' => 'required|string|size:26',
            'email' => 'required|email|max:320',
            'verification_token' => 'required|string|min:32|max:200',
            'password' => 'required|string|min:4|max:64|confirmed',
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        try {
            $challenge = $this->otp->consumeVerification(
                (string) $request->input('challenge_id'),
                (string) $request->input('email'),
                EmailOtpService::PURPOSE_PASSWORD_RESET,
                (string) $request->input('verification_token'),
            );
        } catch (RuntimeException $e) {
            return $this->otpError($e);
        }

        $user = $challenge->user_id ? User::find((int) $challenge->user_id) : null;
        if (!$user) {
            return $this->error('USER_NOT_FOUND', 'تعذر إكمال الاستعادة', 404);
        }

        DB::transaction(function () use ($user, $request) {
            $user->password = Hash::make((string) $request->input('password'));
            $user->save();
            $this->revokeSessions($user);
        });

        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'subject_type' => 'password',
            'subject_id' => (string) $user->id,
            'action' => 'PASSWORD_RECOVERED_BY_EMAIL_OTP',
            'decision_code' => 'PASSWORD_RESET_OK',
            'severity' => 'warning',
        ]);

        return $this->ok([], 'PASSWORD_RESET', 'تم تغيير كلمة المرور. سجّل الدخول من جديد.');
    }

    public function resetPin(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'challenge_id' => 'required|string|size:26',
            'email' => 'required|email|max:320',
            'verification_token' => 'required|string|min:32|max:200',
            'new_pin' => 'required|digits_between:4,6|confirmed',
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        try {
            $challenge = $this->otp->consumeVerification(
                (string) $request->input('challenge_id'),
                (string) $request->input('email'),
                EmailOtpService::PURPOSE_PIN_RECOVERY,
                (string) $request->input('verification_token'),
            );
        } catch (RuntimeException $e) {
            return $this->otpError($e);
        }

        $user = $challenge->user_id ? User::find((int) $challenge->user_id) : null;
        if (!$user) {
            return $this->error('USER_NOT_FOUND', 'تعذر إكمال استعادة الرمز', 404);
        }

        try {
            DB::transaction(function () use ($user, $request) {
                $this->pins->setPin($user, (string) $request->input('new_pin'));
                $this->revokeSessions($user);
            });
        } catch (\InvalidArgumentException $e) {
            return $this->error('WEAK_PIN', $e->getMessage(), 422);
        }

        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'subject_type' => 'pin',
            'subject_id' => (string) $user->id,
            'action' => 'PIN_RECOVERED_BY_EMAIL_OTP',
            'decision_code' => 'PIN_RESET_OK',
            'severity' => 'critical',
            'context' => [
                'support_initiated' => $challenge->requested_by_type === 'admin',
                'requested_by_id' => $challenge->requested_by_id,
            ],
        ]);

        return $this->ok([], 'PIN_RESET', 'تم تعيين رمز PIN جديد. سجّل الدخول من جديد.');
    }

    /**
     * Resend/Svix webhook. It updates delivery state only; it cannot verify an OTP.
     */
    public function webhook(Request $request): JsonResponse
    {
        if (!$this->validWebhookSignature($request)) {
            return $this->error('INVALID_WEBHOOK_SIGNATURE', 'Invalid signature', 401);
        }

        $payload = $request->json()->all();
        $this->otp->applyResendEvent($payload);

        return $this->ok([], 'WEBHOOK_ACCEPTED', 'OK');
    }

    private function validWebhookSignature(Request $request): bool
    {
        $secret = (string) config('amial_otp.resend.webhook_secret', '');
        if ($secret === '') {
            return false;
        }

        $id = (string) $request->header('svix-id', '');
        $timestamp = (string) $request->header('svix-timestamp', '');
        $signatureHeader = (string) $request->header('svix-signature', '');
        if ($id === '' || $timestamp === '' || $signatureHeader === '' || !ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $encodedSecret = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $key = base64_decode($encodedSecret, true);
        if ($key === false) {
            return false;
        }

        $signed = $id . '.' . $timestamp . '.' . $request->getContent();
        $expected = base64_encode(hash_hmac('sha256', $signed, $key, true));

        foreach (preg_split('/\s+/', trim($signatureHeader)) ?: [] as $part) {
            if (!str_contains($part, ',')) continue;
            [$version, $sig] = array_pad(explode(',', $part, 2), 2, '');
            if ($version === 'v1' && $sig !== '' && hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    private function revokeSessions(User $user): void
    {
        if (Schema::hasTable('oauth_access_tokens')) {
            DB::table('oauth_access_tokens')->where('user_id', $user->id)->update(['revoked' => true]);
        }
        if (Schema::hasTable('user_log_histories')) {
            DB::table('user_log_histories')->where('user_id', $user->id)->update(['is_active' => 0]);
        }
        $user->forceFill(['fcm_token' => null])->saveQuietly();
    }

    private function otpError(RuntimeException $e): JsonResponse
    {
        $message = $e->getMessage();
        if (str_starts_with($message, 'RESEND_TOO_SOON:')) {
            $seconds = (int) substr($message, strlen('RESEND_TOO_SOON:'));
            return $this->error('RESEND_TOO_SOON', "يمكن إعادة الإرسال بعد {$seconds} ثانية", 429, ['retry_after' => $seconds]);
        }

        return match ($message) {
            'OTP_DELIVERY_FAILED' => $this->error('OTP_DELIVERY_FAILED', 'تعذر إرسال الرمز حالياً. حاول مرة أخرى بعد قليل.', 503),
            'OTP_NOT_FOUND' => $this->error('OTP_NOT_FOUND', 'طلب التحقق غير موجود', 404),
            'OTP_ALREADY_USED' => $this->error('OTP_ALREADY_USED', 'تم استخدام هذا الرمز أو استبداله برمز أحدث', 409),
            'OTP_EXPIRED' => $this->error('OTP_EXPIRED', 'انتهت صلاحية الرمز. اطلب رمزاً جديداً.', 410),
            'OTP_LOCKED' => $this->error('OTP_LOCKED', 'تجاوزت عدد المحاولات. اطلب رمزاً جديداً.', 429),
            'OTP_INVALID' => $this->error('OTP_INVALID', 'رمز التحقق غير صحيح', 422),
            'OTP_NOT_VERIFIED' => $this->error('OTP_NOT_VERIFIED', 'يجب التحقق من الرمز أولاً', 422),
            'VERIFICATION_EXPIRED' => $this->error('VERIFICATION_EXPIRED', 'انتهت جلسة التحقق. اطلب رمزاً جديداً.', 410),
            'VERIFICATION_INVALID' => $this->error('VERIFICATION_INVALID', 'جلسة التحقق غير صحيحة', 422),
            default => $this->error('OTP_ERROR', 'تعذر إكمال عملية التحقق', 422),
        };
    }

    private function ok(array $meta, string $code, string $message): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            'meta' => $meta,
        ]);
    }

    private function error(string $code, string $message, int $status, array $meta = []): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object) [],
            'meta' => $meta,
        ], $status);
    }

    private function validationError($validator): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => 'VALIDATION',
            'message' => (string) $validator->errors()->first(),
            'errors' => $validator->errors(),
            'meta' => (object) [],
        ], 422);
    }
}
