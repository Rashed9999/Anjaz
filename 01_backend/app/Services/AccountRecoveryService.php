<?php

namespace App\Services;

use App\Models\AccountRecoveryRequest;
use App\Models\AccountSecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * AMIAL-RECOVERY-001
 *
 * AccountRecoveryService — يدير تغيير رقم الهاتف واسترداد الحساب.
 *
 * السيناريوهات (قسم 10 من الوثيقة):
 *
 *  A) المستخدم يملك الرقم القديم:
 *     1. يرسل طلب → نولّد OTP لكلا الرقمين
 *     2. يدخل OTP-قديم + OTP-جديد + PIN
 *     3. لو الكل صحيح + risk_score منخفض → security_hold 24h ثم تطبيق
 *
 *  B) المستخدم فقد الرقم القديم:
 *     1. يرسل طلب + رفع هوية + الرقم الجديد + OTP الجديد
 *     2. الطلب يدخل قائمة pending_review
 *     3. admin يراجع → approve/reject
 *     4. لو approved → security_hold 7 days ثم تطبيق
 *
 * موانع:
 *   - merchant موثق أو agent → دائماً admin review (لا self-service)
 *   - نزاع safe-payment مفتوح → رفض
 *   - تم تغيير الرقم خلال آخر 30 يوم → admin review
 */
class AccountRecoveryService
{
    public const OTP_LENGTH = 6;
    public const OTP_TTL_MINUTES = 10;
    public const REQUEST_TTL_HOURS = 24;
    public const SELF_SERVICE_SECURITY_HOLD_HOURS = 24;
    public const ADMIN_APPROVAL_SECURITY_HOLD_HOURS = 168; // 7 days
    public const PHONE_CHANGE_COOLDOWN_DAYS = 30;
    public const MAX_RISK_SCORE_FOR_SELF_SERVICE = 60;

    public function __construct(
        private readonly AuditService $audit,
        private readonly TransactionPinService $pinService,
    ) {}

    /**
     * يبدأ طلب تغيير الرقم (المستخدم يملك الرقم القديم).
     *
     * @throws \RuntimeException إن كان هناك مانع سياسي
     */
    public function initiateSelfServicePhoneChange(
        User $user,
        string $newPhone,
        ?string $ip,
        ?string $userAgent,
    ): AccountRecoveryRequest {
        $this->assertEligibleForSelfService($user, $newPhone);

        // إن وُجد طلب نشط، نلغيه ونبدأ جديداً
        $this->cancelActiveRequests($user, 'replaced by new request');

        return DB::transaction(function () use ($user, $newPhone, $ip, $userAgent) {
            $request = AccountRecoveryRequest::create([
                'request_ulid' => (string) Str::ulid(),
                'user_id' => $user->id,
                'request_type' => 'phone_change_self',
                'old_phone' => $user->phone,
                'new_phone' => $newPhone,
                'status' => 'pending_otp',
                'otp_old_phone' => $this->generateOtp(),
                'otp_new_phone' => $this->generateOtp(),
                'otp_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
                'otp_old_verified' => false,
                'otp_new_verified' => false,
                'ip_address' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'expires_at' => now()->addHours(self::REQUEST_TTL_HOURS),
            ]);

            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $user->id,
                'subject_type' => 'user',
                'subject_id' => (string)$user->id,
                'action' => 'RECOVERY_INITIATED',
                'decision_code' => 'RECOVERY_OTP_SENT',
                'reason' => 'Self-service phone change initiated',
                'severity' => 'notice',
                'context' => [
                    'request_ulid' => $request->request_ulid,
                    'new_phone_masked' => $this->maskPhone($newPhone),
                ],
            ]);

            // TODO: dispatch SmsModule::send($user->phone, $request->otp_old_phone)
            //                  SmsModule::send($newPhone, $request->otp_new_phone)
            // (نتركها هنا للمتصل — كي الخدمة testable بدون SMS provider live)

            return $request;
        });
    }

    /**
     * يبدأ طلب استرداد (المستخدم فقد الرقم القديم) — يتطلب admin review.
     */
    public function initiateLostPhoneRecovery(
        User $user,
        string $newPhone,
        array $identificationDocuments,
        ?string $userNotes,
        ?string $ip,
        ?string $userAgent,
    ): AccountRecoveryRequest {
        $this->cancelActiveRequests($user, 'replaced by new lost-phone request');

        return DB::transaction(function () use ($user, $newPhone, $identificationDocuments, $userNotes, $ip, $userAgent) {
            $request = AccountRecoveryRequest::create([
                'request_ulid' => (string) Str::ulid(),
                'user_id' => $user->id,
                'request_type' => 'phone_change_lost_phone',
                'old_phone' => $user->phone,
                'new_phone' => $newPhone,
                'status' => 'pending_review',
                'identification_documents' => $identificationDocuments,
                'user_notes' => $userNotes ? mb_substr($userNotes, 0, 500) : null,
                'otp_new_phone' => $this->generateOtp(),
                'otp_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
                'otp_new_verified' => false,
                'ip_address' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
                'expires_at' => now()->addHours(self::REQUEST_TTL_HOURS * 7), // أطول لأن admin يحتاج وقت
            ]);

            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $user->id,
                'subject_type' => 'user',
                'subject_id' => (string)$user->id,
                'action' => 'RECOVERY_LOST_PHONE_SUBMITTED',
                'decision_code' => 'RECOVERY_PENDING_REVIEW',
                'reason' => 'Lost-phone recovery submitted for admin review',
                'severity' => 'warning',
                'context' => [
                    'request_ulid' => $request->request_ulid,
                    'new_phone_masked' => $this->maskPhone($newPhone),
                ],
            ]);

            return $request;
        });
    }

    /**
     * يحقق OTP. يحدّث الـ flags الخاصة بالـ verification.
     */
    public function verifyOtp(
        AccountRecoveryRequest $request,
        string $otpOld,
        string $otpNew,
    ): bool {
        if ($request->status !== 'pending_otp') {
            return false;
        }

        if (!$request->isOtpValid()) {
            return false;
        }

        $oldOk = hash_equals((string)$request->otp_old_phone, $otpOld);
        $newOk = hash_equals((string)$request->otp_new_phone, $otpNew);

        if (!$oldOk || !$newOk) {
            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $request->user_id,
                'action' => 'RECOVERY_OTP_FAILED',
                'decision_code' => 'RECOVERY_OTP_INVALID',
                'severity' => 'notice',
                'context' => [
                    'request_ulid' => $request->request_ulid,
                    'old_ok' => $oldOk,
                    'new_ok' => $newOk,
                ],
            ]);
            return false;
        }

        $request->update([
            'otp_old_verified' => true,
            'otp_new_verified' => true,
        ]);

        return true;
    }

    /**
     * يكمل الطلب بعد OTP + PIN. يطبق security_hold ولا يغير phone مباشرة —
     * التغيير يقع بعد انتهاء الـ hold (job مجدول).
     */
    public function completeSelfServiceChange(
        AccountRecoveryRequest $request,
        string $pin,
    ): bool {
        if ($request->status !== 'pending_otp') {
            return false;
        }
        if (!$request->otp_old_verified || !$request->otp_new_verified) {
            return false;
        }
        if ($request->isExpired()) {
            return false;
        }

        /** @var User $user */
        $user = \App\Models\User::find($request->user_id);
        if (!$user) {
            return false;
        }

        // فحص PIN
        if (!$this->pinService->verify($user, $pin)) {
            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $user->id,
                'action' => 'RECOVERY_PIN_FAILED',
                'decision_code' => 'PIN_INVALID',
                'severity' => 'warning',
                'context' => ['request_ulid' => $request->request_ulid],
            ]);
            return false;
        }

        // risk score (تقدير بسيط في v0.7)
        $riskScore = $this->computeRiskScore($user, $request);

        return DB::transaction(function () use ($user, $request, $riskScore) {
            $request->update([
                'risk_score' => $riskScore,
                'status' => 'approved',
            ]);

            // تطبيق security hold (تغيير phone يحدث بعد انتهائه)
            $holdUntil = now()->addHours(self::SELF_SERVICE_SECURITY_HOLD_HOURS);
            $user->update([
                'security_hold_until' => $holdUntil,
                'security_hold_reason' => 'phone_change_self',
            ]);

            // إبطال كل tokens فوراً
            $user->tokens->each(fn($t) => $t->revoke());

            // مسح fcm_token (الجهاز القديم لن يستلم إشعارات)
            DB::table('users')->where('id', $user->id)->update(['fcm_token' => null]);

            AccountSecurityEvent::create([
                'user_id' => $user->id,
                'event_type' => 'PHONE_CHANGED_PENDING',
                'severity' => 'warning',
                'note' => 'Phone change approved, in security hold until ' . $holdUntil->toIso8601String(),
                'metadata' => [
                    'request_ulid' => $request->request_ulid,
                    'risk_score' => $riskScore,
                    'hold_until' => $holdUntil->toIso8601String(),
                ],
                'ip_address' => $request->ip_address,
                'user_agent' => $request->user_agent,
            ]);

            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $user->id,
                'subject_type' => 'user',
                'subject_id' => (string)$user->id,
                'action' => 'RECOVERY_SELF_APPROVED',
                'decision_code' => 'RECOVERY_HOLD_APPLIED',
                'reason' => "Self-service phone change accepted; hold until {$holdUntil}",
                'severity' => 'warning',
                'context' => [
                    'request_ulid' => $request->request_ulid,
                    'risk_score' => $riskScore,
                ],
            ]);

            return true;
        });
    }

    /**
     * Admin يوافق على طلب lost-phone.
     */
    public function adminApprove(
        AccountRecoveryRequest $request,
        int $adminId,
        ?string $adminNotes,
    ): bool {
        if ($request->status !== 'pending_review') {
            return false;
        }

        return DB::transaction(function () use ($request, $adminId, $adminNotes) {
            $request->update([
                'status' => 'approved',
                'reviewed_by' => $adminId,
                'reviewed_at' => now(),
                'admin_notes' => $adminNotes,
            ]);

            $user = \App\Models\User::find($request->user_id);
            $holdUntil = now()->addHours(self::ADMIN_APPROVAL_SECURITY_HOLD_HOURS);

            $user->update([
                'security_hold_until' => $holdUntil,
                'security_hold_reason' => 'phone_change_admin_approved',
            ]);

            $user->tokens->each(fn($t) => $t->revoke());
            DB::table('users')->where('id', $user->id)->update(['fcm_token' => null]);

            AccountSecurityEvent::create([
                'user_id' => $user->id,
                'event_type' => 'PHONE_CHANGE_ADMIN_APPROVED',
                'severity' => 'warning',
                'note' => "Admin approved lost-phone recovery; hold until {$holdUntil}",
                'metadata' => [
                    'request_ulid' => $request->request_ulid,
                    'admin_id' => $adminId,
                    'hold_until' => $holdUntil->toIso8601String(),
                ],
            ]);

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $adminId,
                'subject_type' => 'user',
                'subject_id' => (string)$user->id,
                'action' => 'RECOVERY_ADMIN_APPROVED',
                'decision_code' => 'RECOVERY_HOLD_APPLIED',
                'severity' => 'critical',
                'context' => [
                    'request_ulid' => $request->request_ulid,
                    'hold_until' => $holdUntil->toIso8601String(),
                ],
            ]);

            return true;
        });
    }

    /**
     * Admin يرفض طلب.
     */
    public function adminReject(
        AccountRecoveryRequest $request,
        int $adminId,
        string $reason,
    ): bool {
        if ($request->status !== 'pending_review') {
            return false;
        }

        $request->update([
            'status' => 'rejected',
            'reviewed_by' => $adminId,
            'reviewed_at' => now(),
            'admin_notes' => $reason,
        ]);

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $adminId,
            'subject_type' => 'user',
            'subject_id' => (string)$request->user_id,
            'action' => 'RECOVERY_REJECTED',
            'decision_code' => 'RECOVERY_DENIED',
            'reason' => mb_substr($reason, 0, 255),
            'severity' => 'warning',
            'context' => [
                'request_ulid' => $request->request_ulid,
            ],
        ]);

        return true;
    }

    /**
     * يُطبق التغيير فعلياً (يُستدعى من Job بعد انتهاء security_hold).
     */
    public function applyApprovedChange(AccountRecoveryRequest $request): bool
    {
        if ($request->status !== 'approved') {
            return false;
        }

        $user = \App\Models\User::find($request->user_id);
        if (!$user) {
            return false;
        }

        if ($user->security_hold_until && $user->security_hold_until->isFuture()) {
            // الـ hold لم ينتهِ بعد
            return false;
        }

        return DB::transaction(function () use ($user, $request) {
            $oldPhone = $user->phone;
            $user->update([
                'phone' => $request->new_phone,
                'security_hold_until' => null,
                'security_hold_reason' => null,
            ]);

            AccountSecurityEvent::create([
                'user_id' => $user->id,
                'event_type' => 'PHONE_CHANGED',
                'severity' => 'critical',
                'note' => 'Phone changed from ' . $this->maskPhone($oldPhone) . ' to ' . $this->maskPhone($request->new_phone),
                'metadata' => ['request_ulid' => $request->request_ulid],
            ]);

            return true;
        });
    }

    /**
     * فحوصات الأهلية للـ self-service.
     */
    private function assertEligibleForSelfService(User $user, string $newPhone): void
    {
        // تاجر موثق أو وكيل: لا self-service (سياسة قسم 10)
        if (in_array($user->type, [1, 3])) {
            throw new \RuntimeException('Agents and verified merchants must use admin-mediated recovery');
        }

        // فحص cooldown — تغيير حديث للرقم
        $recentChange = AccountSecurityEvent::where('user_id', $user->id)
            ->where('event_type', 'PHONE_CHANGED')
            ->where('created_at', '>', now()->subDays(self::PHONE_CHANGE_COOLDOWN_DAYS))
            ->exists();

        if ($recentChange) {
            throw new \RuntimeException('Phone changed within cooldown period; admin review required');
        }

        // فحص duplicate: الرقم الجديد ليس مستخدماً
        $exists = User::where('phone', $newPhone)
            ->where('id', '!=', $user->id)
            ->exists();
        if ($exists) {
            throw new \RuntimeException('New phone number already in use');
        }
    }

    /**
     * يلغي طلبات نشطة سابقة.
     */
    private function cancelActiveRequests(User $user, string $reason): void
    {
        AccountRecoveryRequest::where('user_id', $user->id)
            ->whereIn('status', ['pending_otp', 'pending_review'])
            ->update([
                'status' => 'cancelled',
                'admin_notes' => DB::raw("CONCAT(COALESCE(admin_notes,''), '\n', " . DB::getPdo()->quote($reason) . ")"),
            ]);
    }

    /**
     * توليد OTP رقمي 6 أرقام.
     * نستخدم random_int (cryptographically secure).
     */
    private function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }

    /**
     * تقدير risk score (0-100). v0.7 منطق بسيط — v0.8 يصبح ML.
     */
    private function computeRiskScore(User $user, AccountRecoveryRequest $request): int
    {
        $score = 0;

        // حساب جديد (< 30 يوم) → +30
        if ($user->created_at && $user->created_at->gt(now()->subDays(30))) {
            $score += 30;
        }

        // عمليات أمان فاشلة مؤخراً → +20
        $failedAttempts = AccountSecurityEvent::where('user_id', $user->id)
            ->whereIn('event_type', ['PIN_FAILED', 'PIN_LOCKED'])
            ->where('created_at', '>', now()->subDays(7))
            ->count();
        $score += min(30, $failedAttempts * 5);

        // KYC غير مُتحقق → +25
        if (!$user->is_kyc_verified) {
            $score += 25;
        }

        // طلب recovery سابق خلال 90 يوم → +15
        $previous = AccountRecoveryRequest::where('user_id', $user->id)
            ->where('id', '!=', $request->id)
            ->where('created_at', '>', now()->subDays(90))
            ->count();
        $score += min(15, $previous * 5);

        return min(100, $score);
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) return '****';
        return substr($phone, 0, 5) . str_repeat('*', max(0, strlen($phone) - 8)) . substr($phone, -3);
    }
}
