<?php

namespace Tests\Feature;

use App\Services\Messaging\ProviderRegistry;
use App\Traits\SmsGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MSG91 must receive the OTP body over its current SendOTP POST contract.
 * Both the provider registry and the legacy gateway are covered because
 * password-recovery callers still use SmsGateway.
 */
class Msg91SendOtpContractTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://control.msg91.com/api/v5/otp';

    private function enableMsg91(): void
    {
        DB::table('addon_settings')->updateOrInsert(
            ['key_name' => 'msg91', 'settings_type' => 'sms_config'],
            [
                'id' => (string) Str::uuid(),
                'live_values' => json_encode([
                    'auth_key' => 'auth-key-for-test',
                    'template_id' => 'template-id-for-test',
                    'status' => 1,
                ]),
                'test_values' => json_encode([]),
                'mode' => 'live',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function fakeSuccessfulMsg91(): void
    {
        Http::fake([
            'control.msg91.com/*' => Http::response(['type' => 'success'], 200),
        ]);
    }

    private function assertCurrentSendOtpRequest(): void
    {
        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->method() === 'POST'
                && strtok($request->url(), '?') === self::ENDPOINT
                && $query === [
                    'template_id' => 'template-id-for-test',
                    'mobile' => '967770001111',
                    'authkey' => 'auth-key-for-test',
                ]
                && json_decode($request->body(), true) === ['OTP' => '123456'];
        });
    }

    public function test_registry_uses_the_current_msg91_sendotp_post_contract(): void
    {
        $this->enableMsg91();
        $this->fakeSuccessfulMsg91();

        $registry = app(ProviderRegistry::class);
        $registry->forget();

        $this->assertSame(
            'success',
            $registry->sendOtp('sms', '+967 770 001 111', '123456'),
        );

        $this->assertCurrentSendOtpRequest();
    }

    public function test_legacy_gateway_returns_error_when_msg91_rejects_the_otp(): void
    {
        $this->enableMsg91();

        Http::fake([
            'control.msg91.com/*' => Http::response(['type' => 'error'], 200),
        ]);

        $gateway = new class {
            use SmsGateway;
        };

        $this->assertSame('error', $gateway::msg_91('+967770001111', '123456'));
        $this->assertCurrentSendOtpRequest();
    }
}
