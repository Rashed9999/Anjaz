<?php

namespace App\Services\Muwathaq;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * رسالة ما بعد اعتماد موثّق: الحساب أُنشئ بكلمة مرور عشوائية، فيحتاج صاحبه خطوات الدخول الأولى
 * («نسيت كلمة المرور» برمز على بريده ثم PIN). تُرسل عبر Resend بإعدادات رموز التحقق نفسها، ومفتاح
 * عدم التكرار = مرجع الطلب، فإعادة المحاولة لا تُرسل نسختين. وفي وضع الهاتف `otp` تُضاف خطوة إثباته.
 */
class MuwathaqWelcomeEmailService
{
    public const KIND_LABELS = ['customer' => 'حسابك', 'merchant' => 'حساب منشأتك', 'agent' => 'حساب نقطتك'];

    /** @return list<string> */
    public static function steps(string $kind): array
    {
        $steps = [
            'افتح تطبيق أميال باي.',
            'اختر «نسيت كلمة المرور» وأدخل بريدك الإلكتروني المذكور أدناه.',
            'أدخل الرمز الذي يصلك على البريد واختر كلمة مرور جديدة.',
            'اضبط رمز PIN من 4 أرقام عند أول دخول.',
        ];
        if ($kind === 'customer' && config('muwathaq.phone_verification', 'reviewer') === 'otp') {
            $steps[] = 'أثبت رقم هاتفك برمز يصلك داخل التطبيق ليكتمل توثيق حسابك.';
        }

        return $steps;
    }

    /** @throws RuntimeException عند تعذّر الإرسال ليُعاد من المهمة (RESEND_API_KEY_MISSING لا يُعاد). */
    public function send(User $user, string $kind, string $applicationUlid): string
    {
        $apiKey = (string) config('amial_otp.resend.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('RESEND_API_KEY_MISSING');
        }
        $email = mb_strtolower(trim((string) $user->email));
        if ($email === '') {
            throw new RuntimeException('WELCOME_EMAIL_NO_ADDRESS');
        }
        $websiteUrl = rtrim((string) config('amial_otp.resend.website_url', 'https://amialpay.com'), '/');
        $data = [
            'subject' => 'تم اعتماد حسابك في أميال باي — خطوتك التالية للدخول',
            'preheader' => 'اختر «نسيت كلمة المرور» في التطبيق لتبدأ.',
            'name' => trim((string) $user->f_name) ?: 'عميلنا',
            'kindLabel' => self::KIND_LABELS[$kind] ?? 'حسابك',
            'steps' => self::steps($kind),
            'email' => $email,
            'reference' => 'AM-'.strtoupper(substr($applicationUlid, -8)),
            'logoUrl' => (string) config('amial_otp.resend.brand_logo_url', $websiteUrl.'/branding/logo.png'),
            'websiteUrl' => $websiteUrl,
        ];
        $response = Http::withToken($apiKey)->acceptJson()
            ->withHeaders(['Idempotency-Key' => 'muwathaq-welcome/'.$applicationUlid])
            ->timeout(12)->retry(2, 250, throw: false)
            ->post((string) config('amial_otp.resend.api_url', 'https://api.resend.com/emails'), [
                'from' => config('amial_otp.resend.from_name', 'Amial Pay').' <'.config('amial_otp.resend.from_address', 'verify@amialpay.com').'>',
                'to' => [$email],
                'subject' => $data['subject'],
                'text' => view('emails.account-welcome-text', $data)->render(),
                'html' => view('emails.account-welcome', $data)->render(),
                'headers' => ['X-Amial-Category' => 'account-welcome', 'X-Amial-Application' => $applicationUlid],
            ]);
        if (!$response->successful()) {
            throw new RuntimeException('RESEND_HTTP_'.$response->status());
        }
        $id = (string) ($response->json('id') ?? '');
        if ($id === '') {
            throw new RuntimeException('RESEND_MESSAGE_ID_MISSING');
        }
        app(AuditService::class)->record([
            'actor_type' => 'system', 'actor_user_id' => null, 'subject_type' => 'user', 'subject_id' => (string) $user->id,
            'action' => 'MUWATHAQ_WELCOME_EMAIL_SENT', 'decision_code' => 'SENT', 'severity' => 'info',
            'context' => ['application_ulid' => $applicationUlid, 'provider_id' => $id],
        ]);

        return $id;
    }
}
