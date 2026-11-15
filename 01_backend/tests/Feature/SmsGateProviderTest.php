<?php

namespace Tests\Feature;

use App\Services\Messaging\ProviderRegistry;
use App\Services\Messaging\Providers\Sms\SmsGateProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * AMIAL-MESSAGING-003 — عقدُ SMSGate يُنفَّذ لا يُوصَف.
 *
 * العقد: `POST /messages` ← `smsgateway.Message` (textMessage · phoneNumbers ·
 * ttl · withDeliveryReport) و`202 GetMessageResponse`؛ المصادقة Basic.
 * كلُّ الاختبارات بـ`Http::fake` — لا رسالةَ حقيقيّةَ تخرج ولا سرَّ يُستعمل.
 */
class SmsGateProviderTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://api.sms-gate.app/3rdparty/v1/messages';

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('addon_settings')->delete();
    }

    private function enable(array $extra = [], string $key = 'sms_gate'): void
    {
        DB::table('addon_settings')->updateOrInsert(
            ['key_name' => $key, 'settings_type' => 'sms_config'],
            ['id' => (string) \Illuminate\Support\Str::uuid(),
             'live_values' => json_encode($extra + ['username' => 'amial-user', 'password' => 'pa55-test-only', 'status' => 1]),
             'test_values' => json_encode([]), 'mode' => 'live', 'is_active' => 1]
        );
        app(ProviderRegistry::class)->forget();
    }

    private function accepted(): void
    {
        Http::fake(['api.sms-gate.app/*' => Http::response(
            ['id' => 'PyDmBQZZXYmyxMwED8Fzy', 'state' => 'Pending', 'deviceId' => 'dev1',
             'recipients' => [['phoneNumber' => '+967770001111', 'state' => 'Pending']]], 202)]);
    }

    /** @test */
    public function it_posts_the_documented_message_with_basic_auth_and_a_short_ttl(): void
    {
        $this->enable(['otp_template' => 'رمز أميال: #OTP#']);
        $this->accepted();

        $this->assertTrue(app(SmsGateProvider::class)->sendOtp('967 77-000-1111', '123456'));

        Http::assertSent(function (Request $r) {
            $b = $r->data();

            return $r->method() === 'POST'
                && $r->url() === self::URL
                && $r->hasHeader('Authorization', 'Basic ' . base64_encode('amial-user:pa55-test-only'))
                && $b['textMessage']['text'] === 'رمز أميال: 123456'
                && $b['phoneNumbers'] === ['+967770001111']
                && $b['ttl'] === 300
                && $b['withDeliveryReport'] === true
                && !isset($b['deviceId'], $b['simNumber'], $b['message']);
        });
    }

    /** @test */
    public function device_sim_and_ttl_are_sent_only_when_valid_and_ttl_is_clamped(): void
    {
        $this->enable(['device_id' => 'dev-77', 'sim_number' => 2, 'ttl' => 99999]);
        $this->accepted();
        app(SmsGateProvider::class)->sendOtp('+967770001111', '111111');

        $this->enable(['device_id' => '', 'sim_number' => 9, 'ttl' => 3]);
        app(SmsGateProvider::class)->sendOtp('+967770001111', '222222');

        $sent = Http::recorded()->map(fn ($p) => $p[0]->data())->values();
        $this->assertSame(['dev-77', 2, 3600], [$sent[0]['deviceId'], $sent[0]['simNumber'], $sent[0]['ttl']]);
        $this->assertSame(30, $sent[1]['ttl'], 'الحدُّ الأدنى للصلاحيّة');
        $this->assertArrayNotHasKey('simNumber', $sent[1]);
        $this->assertArrayNotHasKey('deviceId', $sent[1]);
    }

    /** @test */
    public function every_non_accepting_answer_is_a_failure_not_a_success(): void
    {
        $this->enable();

        // fakeSequence لا fake متكرّراً: الثاني يُهمَل إن طابق الأوّلَ فيصير الاختبار أضعف ممّا يبدو.
        $answers = [
            [['message' => 'unauthorized'], 401],
            [['message' => 'bad phone'], 400],
            [['message' => 'no device'], 409],
            ['', 500],
            [['state' => 'Pending'], 202],                       // بلا معرّف
            [['id' => 'x', 'state' => 'Failed'], 202],           // حالةٌ فاشلة
        ];

        $seq = Http::fakeSequence('api.sms-gate.app/*');
        foreach ($answers as [$body, $status]) {
            $seq->push($body, $status);
        }

        foreach ($answers as $i => [, $status]) {
            $this->assertFalse(app(SmsGateProvider::class)->sendOtp('+967770001111', '123456'),
                "ردٌّ غيرُ مقبولٍ عُدّ نجاحاً (#{$i}، HTTP {$status})");
        }
    }

    /** @test */
    public function a_network_error_is_a_failure_and_never_throws(): void
    {
        $this->enable();
        Http::fake(['api.sms-gate.app/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $this->assertFalse(app(SmsGateProvider::class)->sendOtp('+967770001111', '123456'));
    }

    /** @test */
    public function it_is_not_enabled_without_credentials_or_with_an_unsafe_base_url(): void
    {
        $p = fn () => app(SmsGateProvider::class)->isEnabled();

        $this->assertFalse($p(), 'بلا إعداد');

        $this->enable(['username' => '']);
        $this->assertFalse($p(), 'بلا اسم مستخدم');

        foreach (['http://api.sms-gate.app/3rdparty/v1', 'https://127.0.0.1/v1', 'https://10.0.0.5/v1',
                  'https://169.254.169.254/latest', 'https://localhost/v1', 'https://user:pw@host.example/v1', 'ftp://x/y'] as $bad) {
            $this->enable(['base_url' => $bad]);
            $this->assertFalse($p(), "عنوانٌ يجب رفضه: {$bad}");
        }

        $this->enable(['base_url' => 'https://sms.amialpay.com/3rdparty/v1/']);
        $this->assertTrue($p(), 'خادمٌ خاصٌّ بـhttps يُقبل');
    }

    /** @test */
    public function a_private_server_base_url_is_used_without_a_trailing_slash(): void
    {
        $this->enable(['base_url' => 'https://sms.amialpay.com/3rdparty/v1/']);
        Http::fake(['sms.amialpay.com/*' => Http::response(['id' => 'a', 'state' => 'Pending'], 202)]);

        $this->assertTrue(app(SmsGateProvider::class)->sendOtp('+967770001111', '123456'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://sms.amialpay.com/3rdparty/v1/messages');
    }

    /** @test */
    public function the_otp_the_password_and_the_full_number_never_reach_the_log(): void
    {
        $this->enable();
        $this->accepted();
        $lines = [];
        Log::listen(function ($m) use (&$lines) { $lines[] = $m->message . json_encode($m->context, JSON_UNESCAPED_UNICODE); });

        app(SmsGateProvider::class)->sendOtp('+967770001111', '654321');
        Http::fake(['api.sms-gate.app/*' => Http::response('boom 654321 +967770001111', 500)]);
        app(SmsGateProvider::class)->sendOtp('+967770001111', '654321');

        $log = implode("\n", $lines);
        $this->assertNotEmpty($lines);
        foreach (['654321', 'pa55-test-only', '+967770001111', '967770001111'] as $secret) {
            $this->assertStringNotContainsString($secret, $log, "تسرّب إلى السجلّ: {$secret}");
        }
    }

    /** @test */
    public function the_registry_falls_through_to_the_next_provider_when_sms_gate_fails(): void
    {
        $this->enable();
        $this->enable(['api_key' => 'k2'], '2factor');
        Http::fake([
            'api.sms-gate.app/*' => Http::response('', 503),
            '2factor.in/*' => Http::response(['Status' => 'Success'], 200),
        ]);

        $this->assertSame('success', app(ProviderRegistry::class)->sendOtp('sms', '+967770001111', '123456'));
        Http::assertSentCount(2);
    }

    /** @test */
    public function message_state_and_probe_read_without_sending(): void
    {
        $this->enable();
        Http::fake([
            'api.sms-gate.app/3rdparty/v1/messages/abc' => Http::response(
                ['id' => 'abc', 'state' => 'Delivered', 'recipients' => [['phoneNumber' => '+967770001111', 'state' => 'Delivered']]], 200),
            'api.sms-gate.app/3rdparty/v1/devices' => Http::response([['id' => 'd1'], ['id' => 'd2']], 200),
        ]);

        $p = app(SmsGateProvider::class);
        $this->assertSame('Delivered', $p->messageState('abc')['state']);
        $this->assertSame(['ok' => true, 'status' => 200, 'devices' => 2, 'error' => null], $p->probe());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    /** @test */
    public function the_check_command_reports_without_printing_secrets(): void
    {
        $this->enable();
        Http::fakeSequence('api.sms-gate.app/3rdparty/v1/devices')
            ->push([], 200)
            ->push([['id' => 'd1']], 200)
            ->push('', 401);

        $this->artisan('amial:sms-gate-check')
            ->expectsOutputToContain('الأجهزة المسجَّلة: 0')
            ->doesntExpectOutputToContain('pa55-test-only')
            ->assertFailed();

        $this->artisan('amial:sms-gate-check')->assertSuccessful();
        $this->artisan('amial:sms-gate-check')->assertFailed();
    }
}
