<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * AMIAL-PIN-SECURITY-001
 *
 * TransactionPinService — كل تحقق/تغيير PIN يمر هنا.
 *
 * تحل المشاكل التالية (راجع AUDIT_v0.6.md 1.5):
 *   - PIN منفصل تماماً عن password (hash مستقل في transaction_pin).
 *   - عداد محاولات فاشلة + قفل تلقائي (سياسة قسم 9 من الوثيقة).
 *   - تسجيل كل حدث في account_security_events.
 *   - fallback انتقالي لـ password (transition window فقط) — مُعطّل بعد 30 يوم.
 *
 * مهم: نستخدم Hash::make (bcrypt) لـ PIN — رغم أن PIN 4 أرقام فقط ضعيف،
 * لكن وراء brute-force حماية pin_failed_attempts و pin_locked_until.
 * (لا نستخدم plain compare أبداً.)
 */
class TransactionPinService
{
    /** عدد المحاولات قبل القفل */
    public const MAX_ATTEMPTS = 5;

    /** مدة القفل بعد تجاوز المحاولات (دقائق) */
    public const LOCK_MINUTES = 30;

    /** هل ندعم fallback لـ password (مرحلة انتقالية)؟ */
    public const ALLOW_PASSWORD_FALLBACK = true;

    /** التاريخ الذي يُمنع بعده fallback (Y-m-d) — 30 يوم بعد النشر */
    public const FALLBACK_DEADLINE = '2026-06-15';

    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * يفحص PIN. يعيد bool. يحدّث حالة المستخدم (counter/lock) كأثر جانبي.
     */
    public function verify(User $user, string $pin): bool
    {
        // فحص الـ lock الحالي
        if ($user->pin_locked_until !== null && $user->pin_locked_until->isFuture()) {
            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $user->id,
                'subject_type' => 'pin',
                'subject_id' => (string)$user->id,
                'action' => 'PIN_VERIFY_BLOCKED',
                'decision_code' => 'PIN_LOCKED',
                'reason' => 'Account is in PIN lock',
                'severity' => 'warning',
            ]);
            return false;
        }

        $valid = $this->checkPin($user, $pin);

        if ($valid) {
            // إعادة تعيين counter
            $user->pin_failed_attempts = 0;
            $user->pin_locked_until = null;
            $user->saveQuietly();

            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $user->id,
                'subject_type' => 'pin',
                'subject_id' => (string)$user->id,
                'action' => 'PIN_VERIFIED',
                'decision_code' => 'PIN_OK',
                'severity' => 'info',
            ]);

            return true;
        }

        // فشل — زيادة counter
        $user->pin_failed_attempts = ($user->pin_failed_attempts ?? 0) + 1;

        $shouldLock = $user->pin_failed_attempts >= self::MAX_ATTEMPTS;
        if ($shouldLock) {
            $user->pin_locked_until = now()->addMinutes(self::LOCK_MINUTES);
        }
        $user->saveQuietly();

        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'subject_type' => 'pin',
            'subject_id' => (string)$user->id,
            'action' => $shouldLock ? 'PIN_LOCKED' : 'PIN_FAILED',
            'decision_code' => $shouldLock ? 'PIN_LOCKED' : 'PIN_INVALID',
            'severity' => $shouldLock ? 'warning' : 'notice',
            'context' => ['attempt' => $user->pin_failed_attempts],
        ]);

        // سجل حدث أمن أيضاً (يُعرض في شاشة "أمان الحساب")
        \App\Models\AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => $shouldLock ? 'PIN_LOCKED' : 'PIN_FAILED',
            'severity' => $shouldLock ? 'warning' : 'notice',
            'metadata' => json_encode([
                'failed_attempt_count' => $user->pin_failed_attempts,
                'locked_for_minutes' => $shouldLock ? self::LOCK_MINUTES : 0,
            ]),
            'ip_address' => request()->ip(),
            'user_agent' => substr((string)request()->userAgent(), 0, 255),
        ]);

        return false;
    }

    /**
     * يعيّن PIN جديد. يفترض أن المُتصل تحقق من الهوية بـ password/OTP.
     */
    public function setPin(User $user, string $newPin): void
    {
        $this->validatePinFormat($newPin);

        $user->transaction_pin = Hash::make($newPin);
        $user->transaction_pin_set_at = now();
        $user->requires_pin_setup = false;
        $user->pin_failed_attempts = 0;
        $user->pin_locked_until = null;
        $user->saveQuietly();

        $this->audit->record([
            'actor_type' => 'user',
            'actor_user_id' => $user->id,
            'subject_type' => 'pin',
            'subject_id' => (string)$user->id,
            'action' => 'PIN_CHANGED',
            'decision_code' => 'PIN_UPDATED',
            'severity' => 'info',
        ]);

        \App\Models\AccountSecurityEvent::create([
            'user_id' => $user->id,
            'event_type' => 'PIN_CHANGED',
            'severity' => 'notice',
            'ip_address' => request()->ip(),
            'user_agent' => substr((string)request()->userAgent(), 0, 255),
        ]);
    }

    /**
     * يغير PIN بعد التحقق من القديم.
     */
    public function changePin(User $user, string $oldPin, string $newPin): bool
    {
        if (!$this->verify($user, $oldPin)) {
            return false;
        }

        if ($oldPin === $newPin) {
            // نمنع PIN نفسه — security hygiene
            return false;
        }

        $this->setPin($user, $newPin);
        return true;
    }

    /**
     * منطق التحقق الفعلي. يدعم fallback انتقالي.
     */
    private function checkPin(User $user, string $pin): bool
    {
        // المسار الرئيسي: transaction_pin مُعيّن
        if (!empty($user->transaction_pin)) {
            return Hash::check($pin, $user->transaction_pin);
        }

        // Fallback المؤقت: حسابات قديمة لم تعيّن PIN بعد
        // الوثيقة قسم 9 تطلب فصل صارم — هذا الفترة الانتقالية فقط.
        if (self::ALLOW_PASSWORD_FALLBACK && !$this->fallbackExpired()) {
            $matches = Hash::check($pin, $user->password ?? '');

            if ($matches) {
                // نطبع لـ Log + audit أن المستخدم يستخدم password كـ PIN — يجب أن يضع PIN
                $this->audit->record([
                    'actor_type' => 'user',
                    'actor_user_id' => $user->id,
                    'subject_type' => 'pin',
                    'action' => 'PIN_FALLBACK_USED',
                    'decision_code' => 'PIN_FALLBACK',
                    'reason' => 'transaction_pin not set, fell back to password',
                    'severity' => 'warning',
                ]);
            }
            return $matches;
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-PIN-FALLBACK-EXPIRY-001 — **مهلةٌ انقضت بلا أن يعلم أحد.**
        //
        // `FALLBACK_DEADLINE` ماضٍ، فكلُّ حسابٍ بلا `transaction_pin`
        // يُرفَض رفضاً دائماً هنا. **والمستعمل يقرأ «الرمز خطأ»** —
        // وليس خطأً: البابُ أُغلق في تاريخٍ مكتوبٍ قبل أشهر.
        //
        // ورفضٌ يُسمّي سبباً غيرَ سببه أسوأ من رفضٍ صامت: يُرسل صاحبَه
        // يجرّب رموزاً لا تُقبل أيّاً كانت، ثمّ يُرسل الدعمَ خلف عطلٍ
        // في «التحقّق» لا وجودَ له.
        //
        // **ولا يُمدَّد التاريخُ هنا** — إغلاقُ الرجوع إلى كلمة المرور
        // قرارُ أمانٍ اتّخذه صاحبُ المشروع، وتمديدُه بحسن نيّةٍ يُعيد
        // فتحَ ما أُغلق عمداً. **فيُرفَع صوتاً بدل أن يُبتلع صمتاً**:
        // أثرٌ في مركز الأعطال باسمٍ يقول ما وقع، ومفتاحٌ ثابتٌ فلا
        // يُغرق الصفحةَ بصفٍّ لكلّ محاولة.
        //
        // (`OpsAlertService`: الأثرُ في مركز الأعطال أوّلاً ودائماً، ثمّ
        // القناة. والقاعدةُ السابعة: الغيابُ يُقال صراحةً مع سببه.)
        // ══════════════════════════════════════════════════════════════
        if (self::ALLOW_PASSWORD_FALLBACK) {
            app(\App\Services\OpsAlertService::class)->note(
                'pin.fallback.window_closed',
                'نافذةُ الرجوع إلى كلمة المرور أُغلقت — وحساباتٌ بلا رمز عمليّات تُرفض',
                'مهلة `TransactionPinService::FALLBACK_DEADLINE` (' . self::FALLBACK_DEADLINE
                . ') انقضت. كلُّ حسابٍ لم يُعيَّن له رمزُ عمليّات يُرفض الآن في كلّ '
                . 'مسارٍ يطلب الرمز — الدفع الآمن وطلبات المال والتبرّعات واستعادة '
                . 'الحساب والتحويل المعلَّق — **ويقرأ صاحبُه «الرمز خطأ»**. '
                . 'وطريقُ التعيين الأوّل من «تغيير الرمز» ما زال مفتوحاً. '
                . 'القرارُ لصاحب المشروع: تمديدُ المهلة، أو حملةُ تعيينٍ للحسابات '
                . 'المتبقّية، أو إبقاؤها مغلقةً بعد التأكّد من خلوّها.',
            );
        }

        return false;
    }

    private function fallbackExpired(): bool
    {
        return now()->greaterThan(\Carbon\Carbon::parse(self::FALLBACK_DEADLINE));
    }

    private function validatePinFormat(string $pin): void
    {
        if (!preg_match('/^\d{4,6}$/', $pin)) {
            throw new \InvalidArgumentException('الرمز يجب أن يكون من 4 إلى 6 أرقام');
        }
        // ضعفاً: نرفض 1234 و 0000 و تكرار رقم.
        // الرسالة بالعربية لأنها تصل شاشة العميل مباشرةً — ورسالة إنجليزية
        // في تطبيق عربي تُقرأ كعطل لا كإرشاد.
        $bannedPatterns = ['0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999', '1234', '4321', '0123'];
        if (in_array($pin, $bannedPatterns, true)) {
            throw new \InvalidArgumentException('رمز سهل التخمين — اختر أرقاماً غير متسلسلة ولا متكرّرة');
        }
    }
}
