<?php

namespace Tests\Feature;

use App\Services\Messaging\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RealPhoneOtpFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_phone_cannot_receive_false_success_after_provider_failure(): void
    {
        config([
            'amial.otp.demo_code' => '',
            'amial.otp.pilot_customer_phone_enabled' => false,
        ]);
        DB::table('business_settings')->updateOrInsert(
            ['key' => 'phone_verification'],
            ['value' => '1', 'created_at' => now(), 'updated_at' => now()],
        );

        $this->mock(ProviderRegistry::class, function ($provider): void {
            $provider->shouldReceive('hasEnabled')->andReturnUsing(
                fn (string $channel): bool => $channel === 'sms'
            );
            $provider->shouldReceive('sendOtp')->with(
                'sms', '967783545525', \Mockery::type('string')
            )->once()->andReturn('error');
        });

        $this->postJson('/api/v1/customer/auth/check-phone', [
            'phone' => '967783545525',
        ])->assertStatus(502)->assertJsonPath('code', 'OTP_DELIVERY_FAILED');

        $this->assertDatabaseMissing('phone_verifications', [
            'phone' => '967783545525',
        ]);
    }
}
