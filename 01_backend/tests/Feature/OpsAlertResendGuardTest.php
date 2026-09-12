<?php

namespace Tests\Feature;

use App\Services\OpsAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AMIAL-PROD-READINESS-004 — التنبيه التشغيلي يعيد استخدام Resend الذي
 * فعّلناه أصلاً لرسائل OTP. لا نطلب SMTP ثانياً ونترك المالك يظن أن
 * القناة تعمل بينما التطبيق لا يملك ناقلاً فعلياً.
 */
class OpsAlertResendGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_ops_alert_email_uses_resend_when_it_is_configured(): void
    {
        config([
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
            'اختبار قناة الإنذار',
            'هذه رسالة اختبار تشغيلية.'
        );

        $this->assertTrue($sent, 'Resend قبل الرسالة لكن خدمة الإنذار عدتها فاشلة');

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.resend.com/emails'
                && $request->hasHeader('Authorization', 'Bearer re_test_secret')
                && ($payload['to'][0] ?? null) === 'owner@example.test'
                && ($payload['from'] ?? null) === 'Amial Pay <verify@amialpay.com>'
                && ($payload['headers']['X-Amial-Category'] ?? null) === 'ops-alert';
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
