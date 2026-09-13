<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditService;
use App\Services\EmailIdentityService;
use App\Services\Otp\EmailOtpService;
use App\Services\TransactionPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * AMIAL-EMAIL-OTP-001 + AMIAL-EMAIL-IDENTITY-001
 *
 * Public email OTP entrypoints plus the authenticated, OTP-confirmed email
 * replacement flow. Recovery never trusts arbitrary profile text: it resolves
 * exactly one active, verified canonical email owner and binds the challenge to
 * that user's id.
 */
class EmailOtpController extends Controller
{
    public function __construct(
        private readonly EmailOtpService $otp,
        private readonly TransactionPinService $pins,
        private readonly AuditService $audit,
        private readonly EmailIdentityService $identities,
    ) {}

    public function requestCode(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'purpose' => 'required|in:registration,password_reset,pin_recovery',
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        $email = $this->identities->normalize((string) $request->input('email'));
        $purpose = (string) $request->input('purpose');
        if (! $this->channelEnabled($purpose)) {
            return $this->error('EMAIL_OTP_DISABLED', 'التحقق بالبريد غير مفعّل لهذا الإجراء حالياً.', 409);
        }
        $user = null;

        if ($purpose === EmailOtpService::PURPOSE_REGISTRATION) {
            if (! $this->identities->isAvailable($email)) {
                return $this->error('EMAIL_IN_USE', 'البريد الإلكتروني مستخدم في حساب آخر', 422);
            }
        } else {
            // Recovery only trusts one verified canonical owner. A legacy
            // duplicate/unverified email receives the same generic public reply
            // as a missing account, preventing enumeration and wrong-account OTP.
            $user = $this->identities->findVerifiedOwner($email);
            if (! $user) {
                return $this->acceptedRecoveryReply($email);
            }

            $email = $this->identities->normalize((string) $user->email);
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
            if ($user) {
                $latest = DB::table('otp_challenges')->where('identifier', $email)
                    ->where('purpose', $purpose)->where('user_id', $user->id)
                    ->orderByDesc('id')->value('challenge_id');
                return $this->acceptedRecoveryReply($email, $latest);
            }
            return $this->otpError($e);
        }

        if ($user) {
            return $this->acceptedRecoveryReply($email, $meta['challenge_id']);
        }
        return $this->ok($meta, 'OTP_SENT', 'تم إرسال رمز التحقق إلى البريد الإلكتروني');
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            // challenge_id is optional specifically for support-assisted PIN
            // recovery: support never sees or relays it; the customer can enter
            // only the code that arrived in their inbox and we bind it to the
            // latest active challenge for that email + purpose.
            'challenge_id' => 'nullable|string|size:26',
            'email' => 'required|email|max:255',
            'purpose' => 'required|in:registration,password_reset,pin_recovery',
            'otp' => 'required|digits:6',
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        $email = $this->identities->normalize((string) $request->input('email'));
        $purpose = (string) $request->input('purpose');
        if (! $this->channelEnabled($purpose)) {
            return $this->error('EMAIL_OTP_DISABLED', 'التحقق بالبريد غير مفعّل لهذا الإجراء حالياً.', 409);
        }
        $challengeId = trim((string) $request->input('challenge_id', ''));

        if ($challengeId === '') {
            $latest = DB::table('otp_challenges')
                ->where('identifier', $email)
                ->where('purpose', $purpose)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('id')
                ->first(['challenge_id']);
            $challengeId = (string) ($latest->challenge_id ?? '');
        }

        if ($challengeId === '') {
            return $this->error('OTP_NOT_FOUND', 'لا يوجد رمز استعادة صالح. اطلب رمزاً جديداً أو تواصل مع الدعم.', 404);
        }

        try {
            $token = $this->otp->verify(
                $challengeId,
                $email,
                $purpose,
                (string) $request->input('otp'),
            );
        } catch (RuntimeException $e) {
            return $this->otpError($e);
        }

        return $this->ok([
            'challenge_id' => $challengeId,
            'verification_token' => $token,
            'verification_expires_in_seconds' => max(2, (int) config('amial_otp.verification_ttl_minutes', 10)) * 60,
        ], 'OTP_VERIFIED', 'تم التحقق من الرمز');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'challenge_id' => 'required|string|size:26',
            'email' => 'required|email|max:255',
            'verification_token' => 'required|string|min:32|max:200',
            'password' => 'required|string|min:4|max:64|confirmed',
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        return $this->completeRecovery($request, EmailOtpService::PURPOSE_PASSWORD_RESET);
    }

    public function resetPin(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'challenge_id' => 'required|string|size:26',
            'email' => 'required|email|max:255',
            'verification_token' => 'required|string|min:32|max:200',
            'new_pin' => 'required|digits_between:4,6|confirmed',
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        return $this->completeRecovery($request, EmailOtpService::PURPOSE_PIN_RECOVERY);
    }

    private function completeRecovery(Request $request, string $purpose): JsonResponse
    {
        if (! $this->channelEnabled($purpose)) {
            return $this->error('EMAIL_OTP_DISABLED', 'التحقق بالبريد غير مفعّل لهذا الإجراء حالياً.', 409);
        }
        $pin = $purpose === EmailOtpService::PURPOSE_PIN_RECOVERY;
        try {
            DB::transaction(function () use ($request, $purpose, $pin): void {
                $challenge = $this->otp->consumeVerification(
                    (string) $request->input('challenge_id'),
                    (string) $request->input('email'),
                    $purpose,
                    (string) $request->input('verification_token'),
                );
                $user = User::whereKey($challenge->user_id)->lockForUpdate()->first();
                if (! $user || ! $this->identities->matchesVerifiedUser($user, (string) $challenge->identifier)) {
                    throw new RuntimeException('EMAIL_IDENTITY_CHANGED');
                }
                if ($pin) {
                    $this->pins->setPin($user, (string) $request->input('new_pin'));
                } else {
                    $user->password = Hash::make((string) $request->input('password'));
                    $user->save();
                }
                $this->revokeSessions($user);
                $this->audit->record([
                    'actor_type' => 'user', 'actor_user_id' => $user->id,
                    'subject_type' => $pin ? 'pin' : 'password', 'subject_id' => (string) $user->id,
                    'action' => $pin ? 'PIN_RECOVERED_BY_EMAIL_OTP' : 'PASSWORD_RECOVERED_BY_EMAIL_OTP',
                    'decision_code' => $pin ? 'PIN_RESET_OK' : 'PASSWORD_RESET_OK',
                    'severity' => $pin ? 'critical' : 'warning',
                    'context' => [
                        'support_initiated' => $challenge->requested_by_type === 'admin',
                        'requested_by_id' => $challenge->requested_by_id,
                    ],
                ]);
            });
        } catch (\InvalidArgumentException $e) {
            return $this->error('WEAK_PIN', $e->getMessage(), 422);
        } catch (RuntimeException $e) {
            return $this->otpError($e);
        }

        return $pin
            ? $this->ok([], 'PIN_RESET', 'تم تعيين رمز PIN جديد. سجّل الدخول من جديد.')
            : $this->ok([], 'PASSWORD_RESET', 'تم تغيير كلمة المرور. سجّل الدخول من جديد.');
    }

    /**
     * Step 1 of changing the recovery email. The authenticated user proves the
     * current account password, then the OTP is sent only to the NEW address.
     */
    public function requestEmailChange(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'new_email' => 'required|email|max:255',
            'current_password' => 'required|string|max:128',
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        /** @var User|null $user */
        $user = $request->user();
        if (! $user || ! (bool) $user->is_active) {
            return $this->error('UNAUTHENTICATED', 'يجب تسجيل الدخول', 401);
        }

        if (! Hash::check((string) $request->input('current_password'), (string) $user->password)) {
            return $this->error('CURRENT_PASSWORD_INVALID', 'كلمة المرور الحالية غير صحيحة', 422);
        }

        $newEmail = $this->identities->normalize((string) $request->input('new_email'));
        $currentEmail = $this->identities->normalize((string) ($user->email ?? ''));
        if ($newEmail === $currentEmail && $this->identities->matchesVerifiedUser($user, $currentEmail)) {
            return $this->error('EMAIL_UNCHANGED', 'البريد الجديد مطابق للبريد الحالي', 422);
        }
        if (! $this->identities->isAvailable($newEmail, (int) $user->id)) {
            return $this->error('EMAIL_IN_USE', 'البريد الإلكتروني مرتبط بحساب آخر', 422);
        }

        try {
            $meta = $this->otp->issue(
                email: $newEmail,
                purpose: EmailOtpService::PURPOSE_EMAIL_CHANGE,
                userId: (int) $user->id,
                requestedByType: 'user',
                requestedById: (int) $user->id,
                meta: [
                    'ip' => $request->ip(),
                    'old_email_masked' => $this->otp->maskEmail($currentEmail),
                    'old_email_hash' => hash('sha256', $currentEmail),
                ],
            );
        } catch (RuntimeException $e) {
            return $this->otpError($e);
        }

        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'subject_type' => 'email_identity',
            'subject_id' => (string) $user->id,
            'action' => 'EMAIL_CHANGE_OTP_REQUESTED',
            'decision_code' => 'EMAIL_CHANGE_OTP_SENT',
            'severity' => 'warning',
            'context' => [
                'new_email_masked' => $this->otp->maskEmail($newEmail),
            ],
        ]);

        return $this->ok($meta, 'EMAIL_CHANGE_OTP_SENT', 'تم إرسال رمز التحقق إلى البريد الجديد');
    }

    /**
     * Step 2 of changing the recovery email. The challenge is bound to both the
     * logged-in user id and the new address; after success all sessions are
     * revoked so the new recovery identity takes effect from a clean login.
     */
    public function confirmEmailChange(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'challenge_id' => 'required|string|size:26',
            'new_email' => 'required|email|max:255',
            'otp' => 'required|digits:6',
        ]);
        if ($v->fails()) {
            return $this->validationError($v);
        }

        /** @var User|null $user */
        $user = $request->user();
        if (! $user || ! (bool) $user->is_active) {
            return $this->error('UNAUTHENTICATED', 'يجب تسجيل الدخول', 401);
        }

        $challengeId = (string) $request->input('challenge_id');
        $newEmail = $this->identities->normalize((string) $request->input('new_email'));

        $bound = DB::table('otp_challenges')
            ->where('challenge_id', $challengeId)
            ->where('purpose', EmailOtpService::PURPOSE_EMAIL_CHANGE)
            ->where('identifier', $newEmail)
            ->where('user_id', $user->id)
            ->exists();
        if (! $bound) {
            return $this->error('OTP_NOT_FOUND', 'طلب تغيير البريد غير موجود', 404);
        }

        try {
            $this->otp->verifyAndConsume(
                $challengeId,
                $newEmail,
                EmailOtpService::PURPOSE_EMAIL_CHANGE,
                (string) $request->input('otp'),
                function (object $challenge) use ($user, $newEmail): void {
                    $locked = User::whereKey($user->id)->lockForUpdate()->first();
                    if (! $locked || ! (bool) $locked->is_active
                        || (int) $challenge->user_id !== (int) $locked->id) {
                        throw new RuntimeException('EMAIL_IDENTITY_CHANGED');
                    }
                    $oldEmail = $this->identities->normalize((string) ($locked->email ?? ''));
                    $meta = json_decode((string) $challenge->meta, true) ?: [];
                    if (! hash_equals((string) ($meta['old_email_hash'] ?? ''), hash('sha256', $oldEmail))) {
                        throw new RuntimeException('EMAIL_IDENTITY_CHANGED');
                    }
                    $updated = $this->identities->replaceVerifiedEmail($locked, $newEmail);
                    $this->revokeSessions($updated);
                    $this->audit->record([
                        'actor_type' => 'user', 'actor_user_id' => $updated->id,
                        'subject_type' => 'email_identity', 'subject_id' => (string) $updated->id,
                        'action' => 'EMAIL_IDENTITY_CHANGED_BY_OTP', 'decision_code' => 'EMAIL_CHANGE_OK',
                        'severity' => 'critical',
                        'context' => [
                            'old_email_masked' => $this->otp->maskEmail($oldEmail),
                            'new_email_masked' => $this->otp->maskEmail($newEmail),
                        ],
                    ]);
                },
            );
        } catch (ValidationException) {
            return $this->error('EMAIL_IN_USE', 'تعذر ربط البريد الجديد بهذا الحساب', 409);
        } catch (RuntimeException $e) {
            return $this->otpError($e);
        }

        return $this->ok([
            'masked_email' => $this->otp->maskEmail($newEmail),
            'sessions_revoked' => true,
        ], 'EMAIL_CHANGED', 'تم تغيير البريد الإلكتروني وتوثيقه. سجّل الدخول من جديد.');
    }

    /**
     * Resend/Svix webhook. It updates delivery state only; it cannot verify an OTP.
     */
    public function webhook(Request $request): JsonResponse
    {
        if (!$this->validWebhookSignature($request)) {
            return $this->error('INVALID_WEBHOOK_SIGNATURE', 'توقيع إشعار البريد غير صالح.', 401);
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
            // Include already revoked access tokens: their refresh tokens may
            // have survived an older, incomplete revocation implementation.
            if (Schema::hasTable('oauth_refresh_tokens')) {
                DB::table('oauth_refresh_tokens')->whereIn('access_token_id',
                    DB::table('oauth_access_tokens')->where('user_id', $user->id)->select('id')
                )->update(['revoked' => true]);
            }
            DB::table('oauth_access_tokens')->where('user_id', $user->id)->update(['revoked' => true]);
        }
        if (Schema::hasTable('user_log_histories')) {
            DB::table('user_log_histories')->where('user_id', $user->id)->update(['is_active' => 0]);
        }
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        $user->forceFill(['fcm_token' => null, 'remember_token' => Str::random(60)])->saveQuietly();
    }

    private function channelEnabled(string $purpose): bool
    {
        $key = $purpose === EmailOtpService::PURPOSE_REGISTRATION ? 'registration_channel' : $purpose . '_channel';
        return (string) config('amial_otp.' . $key, 'email') === 'email';
    }

    private function acceptedRecoveryReply(string $email, ?string $challengeId = null): JsonResponse
    {
        return $this->ok([
            'challenge_id' => $challengeId ?? (string) Str::ulid(),
            'masked_email' => $this->otp->maskEmail($email),
            'expires_in_seconds' => max(1, (int) config('amial_otp.ttl_minutes', 5)) * 60,
            'resend_after_seconds' => max(30, (int) config('amial_otp.resend_seconds', 60)),
            'delivery_status' => 'accepted',
        ], 'OTP_ACCEPTED', 'إذا كان البريد مسجلاً وموثقاً فسيصلك رمز التحقق');
    }

    private function otpError(RuntimeException $e): JsonResponse
    {
        $message = $e->getMessage();
        if (str_starts_with($message, 'RESEND_TOO_SOON:')) {
            $seconds = (int) substr($message, strlen('RESEND_TOO_SOON:'));
            return $this->error('RESEND_TOO_SOON', "يمكن إعادة الإرسال بعد {$seconds} ثانية", 429, ['retry_after' => $seconds]);
        }

        return match ($message) {
            'EMAIL_IDENTITY_CHANGED' => $this->error('EMAIL_IDENTITY_CHANGED', 'هوية البريد أو حالة الحساب تغيّرت. اطلب رمزاً جديداً.', 409),
            'INVALID_EMAIL' => $this->error('INVALID_EMAIL', 'صيغة البريد الإلكتروني غير صحيحة', 422),
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
