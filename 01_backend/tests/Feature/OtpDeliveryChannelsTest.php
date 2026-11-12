<?php

namespace Tests\Feature;

use App\Services\Messaging\ProviderRegistry;
use App\Services\Otp\EmailOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * الهاتف هو القناة الأولى لرموز التفعيل والاستعادة، والبريد احتياطها. الافتراضيُّ اليوم البريد (لا مزوّدَ رسائل بعد)،
 * والتحويل إلى الهاتف متغيّرُ بيئةٍ واحد: `ACCOUNT_ACTIVATION_CHANNEL=sms,whatsapp,email` بعد `php artisan amial:messaging`.
 */
class OtpDeliveryChannelsTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+967771234567';

    private function service(): EmailOtpService
    {
        return app(EmailOtpService::class);
    }

    private function issue(string $purpose = 'account_activation', ?string $phone = self::PHONE): array
    {
        return $this->service()->issue(email: 'chan-'.bin2hex(random_bytes(3)).'@example.test', purpose: $purpose, phone: $phone);
    }

    private function row(string $challengeId): object
    {
        return DB::table('otp_challenges')->where('challenge_id', $challengeId)->first();
    }

    private function registry(?string $smsResult, ?string $waResult = null): void
    {
        $this->mock(ProviderRegistry::class, function ($m) use ($smsResult, $waResult): void {
            $m->shouldReceive('sendOtp')->andReturnUsing(fn (string $channel) => $channel === 'sms' ? ($smsResult ?? 'not_found') : ($waResult ?? 'not_found'));
        });
    }

    #[Test]
    public function the_channel_list_is_parsed_validated_and_defaults_to_email(): void
    {
        $svc = $this->service();
        $this->assertSame(['email'], $svc->channelsFor('account_activation'), 'الافتراضي بريد');
        config(['amial_otp.account_activation_channel' => ' SMS , whatsapp,email,sms,bogus ']);
        $this->assertSame(['sms', 'whatsapp', 'email'], $svc->channelsFor('account_activation'));
        config(['amial_otp.account_activation_channel' => 'off']);
        $this->assertSame([], $svc->channelsFor('account_activation'), 'off يعطّل الغرض');
        config(['amial_otp.password_reset_channel' => 'sms']);
        $this->assertSame(['sms'], $svc->channelsFor('password_reset'));
    }

    #[Test]
    public function registration_and_email_change_prove_the_email_itself_so_they_stay_on_email(): void
    {
        config(['amial_otp.registration_channel' => 'sms,email', 'amial_otp.email_change_channel' => 'sms']);
        $this->assertSame(['email'], $this->service()->channelsFor('registration'));
        $this->assertSame([], $this->service()->channelsFor('email_change'), 'لا بريدَ في السلسلة = معطّل لا يُحوَّل إلى هاتف');
    }

    #[Test]
    public function phone_first_sends_by_sms_and_never_touches_the_email_provider(): void
    {
        config(['amial_otp.resend.api_key' => 'rk_test', 'amial_otp.account_activation_channel' => 'sms,email']);
        Http::fake();
        $this->registry('success');
        $meta = $this->issue();
        Http::assertNothingSent();
        $row = $this->row($meta['challenge_id']);
        $this->assertSame('sms', $row->channel);
        $this->assertSame('sent', $row->delivery_status);
    }

    #[Test]
    public function email_is_the_fallback_when_no_phone_provider_is_enabled(): void
    {
        config(['amial_otp.resend.api_key' => 'rk_test', 'amial_otp.account_activation_channel' => 'sms,whatsapp,email']);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'msg_1'], 200)]);
        $this->registry('not_found', 'not_found');
        $meta = $this->issue();
        $row = $this->row($meta['challenge_id']);
        $this->assertSame('email', $row->channel);
        $this->assertSame('msg_1', $row->provider_message_id);
    }

    #[Test]
    public function a_failing_sms_provider_falls_through_to_whatsapp_then_email(): void
    {
        config(['amial_otp.account_activation_channel' => 'sms,whatsapp,email', 'amial_otp.resend.api_key' => 'rk_test']);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'msg_2'], 200)]);
        $this->registry('error', 'success');
        $this->assertSame('whatsapp', $this->row($this->issue()['challenge_id'])->channel);
    }

    #[Test]
    public function without_a_phone_the_phone_channels_are_skipped_not_failed(): void
    {
        config(['amial_otp.resend.api_key' => 'rk_test', 'amial_otp.account_activation_channel' => 'sms,email']);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'msg_3'], 200)]);
        $this->registry('success');
        $this->assertSame('email', $this->row($this->issue('account_activation', null)['challenge_id'])->channel);
    }

    #[Test]
    public function when_every_channel_fails_the_challenge_records_why_and_delivery_fails(): void
    {
        config(['amial_otp.resend.api_key' => 'rk_test', 'amial_otp.account_activation_channel' => 'sms,email']);
        Http::fake(['api.resend.com/*' => Http::response('down', 503)]);
        $this->registry('error');
        try {
            $this->issue();
            $this->fail('expected failure');
        } catch (RuntimeException $e) {
            $this->assertSame('OTP_DELIVERY_FAILED', $e->getMessage());
        }
        $row = DB::table('otp_challenges')->orderByDesc('id')->first();
        $this->assertSame('failed', $row->delivery_status);
        $this->assertSame('RESEND_HTTP_503', $row->last_error);
    }

    #[Test]
    public function the_default_path_is_unchanged_email_only_when_nothing_is_configured(): void
    {
        config(['amial_otp.resend.api_key' => 'rk_test']);
        Http::fake(['api.resend.com/*' => Http::response(['id' => 'msg_4'], 200)]);
        $this->assertSame('email', $this->row($this->issue()['challenge_id'])->channel);
        Http::assertSentCount(1);
    }
}
