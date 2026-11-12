<?php

namespace App\Services\Muwathaq;

use App\Models\User;
use App\Services\AuditService;
use App\Services\Otp\EmailOtpService;
use RuntimeException;

/**
 * رسالة ما بعد اعتماد موثّق = **بريد التفعيل برمزه**: الحساب أُنشئ بكلمة مرور عشوائية، فيفتح صاحبه التطبيق ← «تفعيل الحساب»
 * ويُدخل بريدَه والرمزَ الواصلَ هنا ثم يختار كلمة مرور وPIN (`POST /auth/activate`)، فلا «نسيت كلمة المرور» ولا صفحةَ ويب.
 * الرمز يُصدَر عبر `EmailOtpService` (غرض `account_activation`، صلاحيته ثلاثة أيام) فيرث عدّاد المحاولات والتجزئة والقفل.
 * وفي وضع الهاتف `otp` يبقى إثباتُ الهاتف خطوةً لاحقةً داخل التطبيق.
 */
class MuwathaqWelcomeEmailService
{
    public const KIND_LABELS = ['customer' => 'حسابك', 'merchant' => 'حساب منشأتك', 'agent' => 'حساب نقطتك'];

    /** @throws RuntimeException عند تعذّر الإرسال ليُعاد من المهمة (RESEND_API_KEY_MISSING لا يُعاد). */
    public function send(User $user, string $kind, string $applicationUlid): string
    {
        if ((string) config('amial_otp.resend.api_key', '') === '') {
            throw new RuntimeException('RESEND_API_KEY_MISSING');
        }
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
        app(AuditService::class)->record([
            'actor_type' => 'system', 'actor_user_id' => null, 'subject_type' => 'user', 'subject_id' => (string) $user->id,
            'action' => 'MUWATHAQ_WELCOME_EMAIL_SENT', 'decision_code' => 'SENT', 'severity' => 'info',
            'context' => ['application_ulid' => $applicationUlid, 'challenge_id' => $meta['challenge_id']],
        ]);

        return $meta['challenge_id'];
    }
}
