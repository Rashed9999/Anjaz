<?php

namespace Tests\Feature;

use App\CentralLogics\OtpDispatcher;
use App\CentralLogics\WhatsappModule;
use App\CentralLogics\SmsModule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AMIAL-WHATSAPP-OTP-001 — اختبار قناة واتساب متعدّدة المزوّدين + الموزّع.
 *
 * يثبت: إرسال واتساب أولاً، fallback تلقائي إلى SMS عند الفشل، احترام التفضيل
 * (sms_only يتخطّى واتساب)، وأن كل المتصلين القدامى (SmsModule::send) يكتسبون
 * واتساب شفّافاً. يُزيّف الـ HTTP عبر Http::fake — لا اتصال خارجي فعلي.
 */
class WhatsappOtpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // addon_settings جدول قاعدة 6cash (ليس هجرة) — ننشئه للاختبار.
        Schema::dropIfExists('addon_settings');
        Schema::create('addon_settings', function (Blueprint $t) {
            $t->string('id', 36)->primary();
            $t->string('key_name', 191)->nullable();
            $t->longText('live_values')->nullable();
            $t->longText('test_values')->nullable();
            $t->string('settings_type', 255)->nullable();
            $t->string('mode', 20)->default('live');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->longText('additional_data')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('addon_settings');
        parent::tearDown();
    }

    private function configure(string $key, string $type, array $values): void
    {
        DB::table('addon_settings')->insert([
            'id' => (string) Str::uuid(),
            'key_name' => $key,
            'settings_type' => $type,
            'live_values' => json_encode($values),
            'mode' => 'live',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function enableUltramsg(): void
    {
        $this->configure('ultramsg', 'whatsapp_config', [
            'status' => 1, 'instance_id' => 'instance123', 'token' => 'tok',
            'otp_template' => 'رمزك: #OTP#',
        ]);
    }

    private function enable2factorSms(): void
    {
        $this->configure('2factor', 'sms_config', ['status' => 1, 'api_key' => 'key123']);
    }

    public function test_whatsapp_first_sends_via_ultramsg(): void
    {
        $this->enableUltramsg();
        Http::fake(['api.ultramsg.com/*' => Http::response(['sent' => 'true', 'id' => 'm1'], 200)]);

        $result = OtpDispatcher::send('+967777123456', '4321');

        $this->assertSame('success', $result);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.ultramsg.com'));
    }

    public function test_falls_back_to_sms_when_whatsapp_fails(): void
    {
        $this->enableUltramsg();      // واتساب مُفعّل لكنه سيفشل
        $this->enable2factorSms();    // SMS احتياطي
        Http::fake([
            'api.ultramsg.com/*' => Http::response(['sent' => 'false', 'error' => 'x'], 200),
            '2factor.in/*' => Http::response(['Status' => 'Success'], 200),
        ]);

        $result = SmsModule::send('+967777123456', '4321'); // المتصل القديم

        $this->assertSame('success', $result);
        // جُرّب واتساب أولاً ثم سقط إلى SMS
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.ultramsg.com'));
        Http::assertSent(fn ($req) => str_contains($req->url(), '2factor.in'));
    }

    public function test_sms_only_preference_skips_whatsapp(): void
    {
        $this->configure('otp_channel', 'otp_config', ['value' => 'sms_only']);
        $this->enableUltramsg();   // مُفعّل لكنه يجب أن يُتخطّى
        $this->enable2factorSms();
        Http::fake([
            'api.ultramsg.com/*' => Http::response(['sent' => 'true'], 200),
            '2factor.in/*' => Http::response(['Status' => 'Success'], 200),
        ]);

        $result = OtpDispatcher::send('+967777123456', '4321');

        $this->assertSame('success', $result);
        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'api.ultramsg.com'));
        Http::assertSent(fn ($req) => str_contains($req->url(), '2factor.in'));
    }

    public function test_meta_cloud_template_send(): void
    {
        $this->configure('meta_cloud', 'whatsapp_config', [
            'status' => 1, 'access_token' => 'EAAt', 'phone_number_id' => '100200300',
            'template_name' => 'otp_code', 'lang_code' => 'ar',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);

        $result = WhatsappModule::send('+967777123456', '9999');

        $this->assertSame('success', $result);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'graph.facebook.com')
            && $req['type'] === 'template');
    }

    public function test_no_provider_returns_not_found(): void
    {
        Http::fake();
        $this->assertSame('not_found', OtpDispatcher::send('+967777123456', '4321'));
    }

    public function test_default_channel_preference_is_whatsapp_first(): void
    {
        $this->assertSame('whatsapp_first', OtpDispatcher::channelPreference());
    }

    // ===== إشعارات نصّية حرّة (لا OTP) =====

    public function test_send_text_notification_via_ultramsg(): void
    {
        $this->enableUltramsg();
        Http::fake(['api.ultramsg.com/*' => Http::response(['sent' => 'true', 'id' => 'm9'], 200)]);

        $result = WhatsappModule::sendText('+967777123456', 'تم استلام تحويلك بقيمة 100 ر.ي ✅');

        $this->assertSame('success', $result);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'api.ultramsg.com')
            && str_contains((string) $req['body'], 'تحويلك'));
    }

    public function test_send_text_via_meta_cloud_uses_text_type(): void
    {
        $this->configure('meta_cloud', 'whatsapp_config', [
            'status' => 1, 'access_token' => 'EAA', 'phone_number_id' => '100200300',
        ]);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.Y']]], 200)]);

        $result = WhatsappModule::sendText('+967777123456', 'إشعار: فاتورتك جاهزة');

        $this->assertSame('success', $result);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'graph.facebook.com')
            && $req['type'] === 'text');
    }
}
