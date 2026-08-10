<?php

namespace App\Services\Whatsapp;

use App\CentralLogics\SmsModule;
use App\Models\User;
use App\Models\WhatsappAuditLog;
use App\Models\WhatsappLinkedDevice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-WA-002 — خدمة ربط الحساب (Section 4 من المواصفة).
 *
 * المبدأ: "واتساب قناة تشغيل رسمية" — لا يُسمح بتنفيذ أيّ أمر (ولو عرض
 * الرصيد) دون جلسة موثوقة. الربط يتمّ مرّة واحدة بـ OTP، ثمّ يبقى نشطاً
 * حتى يُلغى (Revoke) من المستخدم أو الإدارة.
 *
 * الفرق عن PIN: الـ OTP هنا يُكتَب مباشرة في محادثة واتساب (مقبول لأنّه
 * one-time ومنخفض الخطورة)، بعكس PIN الذي يُمنَع منعاً باتاً إدخاله في
 * واتساب (يُستخدَم رابط آمن منفصل — مبني مسبقاً في WhatsappBotService).
 */
class WhatsappAccountLinkingService
{
    private const OTP_TTL_MINUTES   = 5;
    private const MAX_OTP_ATTEMPTS  = 3;
    private const LOCKOUT_MINUTES   = 15;
    private const OTP_CACHE_PREFIX  = 'wa_link_otp:';
    private const ATTEMPTS_PREFIX   = 'wa_link_attempts:';
    private const LOCKOUT_PREFIX    = 'wa_link_lockout:';

    /**
     * هل رقم الواتساب هذا مرتبط بحساب نشط؟
     */
    public function isLinked(string $waNumber): bool
    {
        return WhatsappLinkedDevice::where('whatsapp_number', $waNumber)
            ->where('status', WhatsappLinkedDevice::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * يُعيد المستخدم المرتبط برقم واتساب (أو null).
     */
    public function resolveUser(string $waNumber): ?User
    {
        $link = WhatsappLinkedDevice::where('whatsapp_number', $waNumber)
            ->where('status', WhatsappLinkedDevice::STATUS_ACTIVE)
            ->first();

        if (!$link) return null;

        // Section 5: last_activity_at لكل جلسة
        $link->update(['last_activity_at' => now()]);

        return $link->user;
    }

    /**
     * يُعيد سجلّ الربط (لقراءة risk_score مثلاً).
     */
    public function getLink(string $waNumber): ?WhatsappLinkedDevice
    {
        return WhatsappLinkedDevice::where('whatsapp_number', $waNumber)
            ->where('status', WhatsappLinkedDevice::STATUS_ACTIVE)
            ->first();
    }

    /**
     * خطوة 1: المستخدم أدخل رقم الحساب — يبحث عنه ويُرسِل OTP.
     *
     * @return array{success:bool, message:string}
     */
    public function startLinking(string $waNumber, string $accountPhone): array
    {
        if ($this->isLockedOut($waNumber)) {
            $this->audit($waNumber, null, WhatsappAuditLog::EVENT_LINK_FAILED,
                WhatsappAuditLog::OUTCOME_BLOCKED, ['reason' => 'lockout']);
            return ['success' => false, 'message' =>
                "🔒 تجاوزت عدد المحاولات. حاول بعد " . self::LOCKOUT_MINUTES . " دقيقة."];
        }

        $normalized = $this->normalizePhone($accountPhone);
        // AMIAL-REQUEST-DIRECT-002 — كان توحيداً يدويّاً نصفَ تامّ: صيغتان
        // من أربع (تسقط `00967…` و`777…`). والأداةُ الواحدة تغطّيها كلَّها.
        $user = User::whereIn('phone', \App\Support\Phone::variants($normalized))->first();

        if (!$user) {
            $this->audit($waNumber, null, WhatsappAuditLog::EVENT_LINK_FAILED,
                WhatsappAuditLog::OUTCOME_FAILED, ['reason' => 'phone_not_found']);
            return ['success' => false, 'message' =>
                "⚠️ لم يُعثَر على حساب بهذا الرقم. تأكّد من رقم حسابك في أميال باي."];
        }

        // منع ربط رقم واتساب بأكثر من حساب نشط في نفس الوقت
        $existingForOtherUser = WhatsappLinkedDevice::where('whatsapp_number', $waNumber)
            ->where('status', WhatsappLinkedDevice::STATUS_ACTIVE)
            ->where('user_id', '!=', $user->id)
            ->exists();
        if ($existingForOtherUser) {
            return ['success' => false, 'message' =>
                "⚠️ رقم الواتساب هذا مرتبط بحساب آخر. تواصل مع الدعم لفكّ الربط."];
        }

        $otp = (string) random_int(100000, 999999);

        Cache::put(self::OTP_CACHE_PREFIX . $waNumber, [
            'otp'      => $otp,
            'user_id'  => $user->id,
            'phone'    => $normalized,
        ], now()->addMinutes(self::OTP_TTL_MINUTES));

        Cache::forget(self::ATTEMPTS_PREFIX . $waNumber);

        $sent = SmsModule::send($normalized, $otp);

        $this->audit($waNumber, $user->id, WhatsappAuditLog::EVENT_LINK_ATTEMPT,
            $sent === 'success' ? WhatsappAuditLog::OUTCOME_SUCCESS : WhatsappAuditLog::OUTCOME_FAILED,
            ['otp_dispatch' => $sent]);

        if ($sent !== 'success') {
            Log::warning('[WA-LINK] OTP dispatch failed', ['user_id' => $user->id]);
        }

        return ['success' => true, 'message' =>
            "📩 أرسلنا رمز تحقّق إلى رقمك المسجَّل. أدخل الرمز هنا لإكمال الربط."];
    }

    /**
     * خطوة 2: تحقّق من OTP وإنشاء/تفعيل الربط.
     *
     * @return array{success:bool, message:string, user?:User}
     */
    public function verifyOtp(string $waNumber, string $enteredOtp): array
    {
        if ($this->isLockedOut($waNumber)) {
            return ['success' => false, 'message' =>
                "🔒 تجاوزت عدد المحاولات. حاول لاحقاً."];
        }

        $cached = Cache::get(self::OTP_CACHE_PREFIX . $waNumber);
        if (!$cached) {
            return ['success' => false, 'message' =>
                "⏰ انتهت صلاحية الرمز. اكتب *ربط الحساب* للمحاولة من جديد."];
        }

        if (!hash_equals((string) $cached['otp'], trim($enteredOtp))) {
            $attempts = (int) Cache::get(self::ATTEMPTS_PREFIX . $waNumber, 0) + 1;
            Cache::put(self::ATTEMPTS_PREFIX . $waNumber, $attempts, now()->addMinutes(self::OTP_TTL_MINUTES));

            if ($attempts >= self::MAX_OTP_ATTEMPTS) {
                Cache::put(self::LOCKOUT_PREFIX . $waNumber, true, now()->addMinutes(self::LOCKOUT_MINUTES));
                Cache::forget(self::OTP_CACHE_PREFIX . $waNumber);
                $this->audit($waNumber, $cached['user_id'], WhatsappAuditLog::EVENT_LINK_FAILED,
                    WhatsappAuditLog::OUTCOME_BLOCKED, ['reason' => 'max_attempts']);
                return ['success' => false, 'message' =>
                    "🔒 تجاوزت عدد المحاولات المسموح. حُظر الربط " . self::LOCKOUT_MINUTES . " دقيقة."];
            }

            $remaining = self::MAX_OTP_ATTEMPTS - $attempts;
            $this->audit($waNumber, $cached['user_id'], WhatsappAuditLog::EVENT_LINK_FAILED,
                WhatsappAuditLog::OUTCOME_FAILED, ['reason' => 'wrong_otp']);
            return ['success' => false, 'message' =>
                "❌ الرمز غير صحيح. لديك {$remaining} محاولة متبقّية."];
        }

        $user = User::find($cached['user_id']);
        if (!$user) {
            return ['success' => false, 'message' => "⚠️ حدث خطأ. حاول مجدّداً."];
        }

        $link = WhatsappLinkedDevice::updateOrCreate(
            ['user_id' => $user->id, 'whatsapp_number' => $waNumber],
            [
                'status'             => WhatsappLinkedDevice::STATUS_ACTIVE,
                'device_fingerprint' => hash('sha256', $waNumber . '|' . $user->id),
                'otp_verified_at'    => now(),
                'last_activity_at'   => now(),
                'risk_score'         => 0,
            ],
        );

        Cache::forget(self::OTP_CACHE_PREFIX . $waNumber);
        Cache::forget(self::ATTEMPTS_PREFIX . $waNumber);

        $this->audit($waNumber, $user->id, WhatsappAuditLog::EVENT_LINK_SUCCESS,
            WhatsappAuditLog::OUTCOME_SUCCESS, ['link_id' => $link->id]);

        return ['success' => true, 'message' => '', 'user' => $user];
    }

    /**
     * إلغاء الربط (يطلبه المستخدم أو الإدارة).
     */
    public function revoke(string $waNumber, ?int $byUserId = null, string $reason = 'user_request'): bool
    {
        $link = WhatsappLinkedDevice::where('whatsapp_number', $waNumber)
            ->where('status', WhatsappLinkedDevice::STATUS_ACTIVE)
            ->first();

        if (!$link) return false;

        $link->update([
            'status'        => WhatsappLinkedDevice::STATUS_REVOKED,
            'revoked_at'    => now(),
            'revoked_by'    => $byUserId,
            'revoke_reason' => $reason,
        ]);

        $this->audit($waNumber, $link->user_id, WhatsappAuditLog::EVENT_SECURITY_FLAG,
            WhatsappAuditLog::OUTCOME_SUCCESS, ['action' => 'revoked', 'reason' => $reason]);

        return true;
    }

    /**
     * يرفع Risk Score لجلسة (Section 26) — لا يحذف، يُراكِم.
     */
    public function bumpRisk(string $waNumber, int $delta, string $reason): void
    {
        $link = WhatsappLinkedDevice::where('whatsapp_number', $waNumber)
            ->where('status', WhatsappLinkedDevice::STATUS_ACTIVE)
            ->first();
        if (!$link) return;

        $newScore = min(100, $link->risk_score + $delta);
        $link->update(['risk_score' => $newScore]);

        $this->audit($waNumber, $link->user_id, WhatsappAuditLog::EVENT_SECURITY_FLAG,
            WhatsappAuditLog::OUTCOME_SUCCESS, [
                'risk_delta' => $delta, 'new_score' => $newScore, 'reason' => $reason,
            ]);
    }

    // ============ Helpers ============

    private function isLockedOut(string $waNumber): bool
    {
        return (bool) Cache::get(self::LOCKOUT_PREFIX . $waNumber, false);
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
        if (str_starts_with($phone, '00')) $phone = substr($phone, 2);
        if (str_starts_with($phone, '+'))  $phone = substr($phone, 1);
        return $phone;
    }

    private function audit(
        string $waNumber, ?int $userId, string $event, string $outcome, array $meta = []
    ): void {
        try {
            WhatsappAuditLog::create([
                'whatsapp_number' => $waNumber,
                'user_id'         => $userId,
                'event_type'      => $event,
                'outcome'         => $outcome,
                'metadata'        => $meta,
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('[WA-LINK] audit failed', ['error' => $e->getMessage()]);
        }
    }
}
