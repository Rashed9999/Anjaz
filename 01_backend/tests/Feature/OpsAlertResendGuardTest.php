<?php

namespace Tests\Feature;

use App\Services\OpsAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AMIAL-PROD-READINESS-004/005 — التنبيه التشغيلي يعيد استخدام Resend
 * الذي فُعّل لرسائل OTP، ويجب أن يحمل سياقاً عملياً لا مجرد «وقع عطل».
 */
class OpsAlertResendGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_ops_alert_email_uses_resend_when_it_is_configured(): void
    {
        config([
            'app.env' => 'testing',
            'app.url' => 'https://amialpay.com',
            'amial.reconciliation.alert_numbers' => [],
            'amial.reconciliation.alert_emails' => ['owner@example.test'],
            'amial_otp.resend.api_key' => 're_test_secret',
            'amial_otp.resend.api_url' => 'https://api.resend.com/emails',
            'amial_otp.resend.from_address' => 'verify@amialpay.com',
            'amial_otp.resend.from_name' => 'Amial Pay',
        ]);

        Http::fake([
            'https://api.resend.com/emails' => Http::response(['id' => 'email_test_123'], 200),
        ]);
        Mail::fake();

        $sent = app(OpsAlertService::class)->raise(
            'health.down.database',
            'قاعدة البيانات متعثرة',
            'تعذر على فحص الصحة الاتصال بقاعدة البيانات في آخر جولة.'
        );

        $this->assertTrue($sent, 'Resend قبل الرسالة لكن خدمة الإنذار عدتها فاشلة');

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $text = (string) ($payload['text'] ?? '');
            $html = (string) ($payload['html'] ?? '');

            return $request->url() === 'https://api.resend.com/emails'
                && $request->hasHeader('Authorization', 'Bearer re_test_secret')
                && ($payload['to'][0] ?? null) === 'owner@example.test'
                && ($payload['from'] ?? null) === 'Amial Pay <verify@amialpay.com>'
                && ($payload['headers']['X-Amial-Category'] ?? null) === 'ops-alert'
                && ($payload['headers']['X-Amial-Alert-Key'] ?? null) === 'health.down.database'
                && str_starts_with((string) ($payload['headers']['X-Amial-Alert-Reference'] ?? ''), 'OPS-')
                && str_contains((string) ($payload['subject'] ?? ''), '[حرج]')
                && str_contains($text, 'مستوى الخطورة: حرج')
                && str_contains($text, 'المكوّن: صحة النظام')
                && str_contains($text, 'مفتاح الرصد: health.down.database')
                && str_contains($text, 'الإجراء المقترح:')
                && str_contains($text, 'https://amialpay.com/admin/amial/system/health')
                && str_contains($html, 'مرجع الإنذار')
                && str_contains($html, 'فتح صحة النظام');
        });

        // وجود Resend يجب أن يمنع المرور من SMTP حتى لا نعتمد ناقلين بلا حاجة.
        Mail::assertNothingSent();
    }

    public function test_resend_http_failure_is_not_reported_as_delivery(): void
    {
        config([
            'amial.reconciliation.alert_numbers' => [],
            'amial.reconciliation.alert_emails' => ['owner@example.test'],
            'amial_otp.resend.api_key' => 're_test_secret',
            'amial_otp.resend.api_url' => 'https://api.resend.com/emails',
        ]);

        Http::fake([
            'https://api.resend.com/emails' => Http::response(['message' => 'failed'], 500),
        ]);

        $sent = app(OpsAlertService::class)->raise(
            'health.down.database',
            'اختبار فشل القناة',
            'لا يجوز ادعاء الوصول.'
        );

        $this->assertFalse($sent, 'فشل Resend لكن النظام ادعى أن التنبيه وصل');
    }
}
