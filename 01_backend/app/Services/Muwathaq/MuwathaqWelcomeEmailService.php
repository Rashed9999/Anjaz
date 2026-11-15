<?php

namespace App\Services\Muwathaq;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Otp\EmailOtpService;
use RuntimeException;
use Illuminate\Support\Facades\DB;

/**
 * رسالة ما بعد اعتماد موثّق = **رمز التفعيل عبر SMS أولاً أو البريد عند فشل SMS**: الحساب أُنشئ بكلمة مرور عشوائية، فيفتح صاحبه التطبيق ← «تفعيل الحساب»
 * ويُدخل بريدَه المقيّد حاليًا بالحساب والرمزَ الواصلَ هنا ثم يختار كلمة مرور وPIN (`POST /auth/activate`)، فلا «نسيت كلمة المرور» ولا صفحةَ ويب.
 * الرمز يُصدَر عبر `EmailOtpService` (غرض `account_activation`، صلاحيته ثلاثة أيام) فيرث عدّاد المحاولات والتجزئة والقفل.
 * ملاحظة: السماح بحساب دون بريد يحتاج عقد تسجيل/تفعيل خاص في موثّق وأميال باي؛ لا يستبدله تغيير قناة الرسالة.
 */
class MuwathaqWelcomeEmailService
{
    public const KIND_LABELS = ['customer' => 'حسابك', 'merchant' => 'حساب منشأتك', 'agent' => 'حساب نقطتك'];

    /** @throws RuntimeException عند تعذّر الإرسال ليُعاد من المهمة (RESEND_API_KEY_MISSING لا يُعاد). */
    public function send(User $user, string $kind, string $applicationUlid): string
    {
        // Route activation OTP via the purpose-specific SMS-first policy.
        // If the SMS provider is unavailable, EmailOtpService tries email next.
        $email = mb_strtolower(trim((string) $user->email));
        if ($email === '') {
            throw new RuntimeException('WELCOME_EMAIL_NO_ADDRESS');
        }
        $meta = app(EmailOtpService::class)->issue(
            email: $email,
            purpose: EmailOtpService::PURPOSE_ACCOUNT_ACTIVATION,
            userId: (int) $user->id,
            requestedByType: 'system',
            requestedById: null,
            meta: ['application_ulid' => $applicationUlid, 'kind' => $kind],
            phone: (string) $user->phone,
        );
        $channel = (string) DB::table('otp_challenges')->where('challenge_id', $meta['challenge_id'])->value('channel');
        app(AuditService::class)->record([
            'actor_type' => 'system', 'actor_user_id' => null, 'subject_type' => 'user', 'subject_id' => (string) $user->id,
            'action' => $channel === 'sms' ? 'MUWATHAQ_WELCOME_SMS_SENT' : 'MUWATHAQ_WELCOME_EMAIL_SENT', 'decision_code' => 'SENT', 'severity' => 'info',
            'context' => ['application_ulid' => $applicationUlid, 'challenge_id' => $meta['challenge_id'], 'channel' => $channel],
        ]);

        return $meta['challenge_id'];
    }
}
