<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\PosUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * AMIAL-UNIFIED-AUTH-001 (v1.5) — اختبارات.
 */
class UnifiedAuthTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function customer_login_requires_role()
    {
        $response = $this->postJson('/api/v1/auth/login', []);
        $response->assertStatus(422)->assertJsonPath('code', 'ROLE_REQUIRED');
    }

    /** @test */
    public function invalid_role_returns_422()
    {
        $response = $this->postJson('/api/v1/auth/login', ['role' => 'superuser']);
        $response->assertStatus(422)->assertJsonPath('code', 'INVALID_ROLE');
    }

    /** @test */
    public function customer_login_validates_fields()
    {
        $response = $this->postJson('/api/v1/auth/login', ['role' => 'customer']);
        $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => ['national_id', 'phone', 'password']]);
    }

    /** @test */
    public function customer_login_fails_with_invalid_credentials()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'role' => 'customer',
            'national_id' => '12345678',
            'phone' => '+967700000099',
            'password' => 'wrong-password',
        ]);
        $response->assertStatus(401)->assertJsonPath('code', 'AUTH_FAILED');
    }

    /** @test */
    public function merchant_login_fails_when_merchant_not_found()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'role' => 'merchant',
            'merchant_number' => 'M-99999',
            'phone' => '+967700000099',
            'password' => 'password',
        ]);
        $response->assertStatus(401)->assertJsonPath('code', 'AUTH_FAILED');
    }

    /** @test */
    public function agent_login_step1_validates_fields()
    {
        $response = $this->postJson('/api/v1/auth/login', ['role' => 'agent']);
        $response->assertStatus(422);
    }

    /** @test */
    public function agent_login_step1_returns_otp_token_on_success()
    {
        $agent = User::factory()->create([
            'type' => AGENT_TYPE,
            'agent_number' => 'AG-001',
            'password' => Hash::make('agent-pass'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'role' => 'agent',
            'agent_number' => 'AG-001',
            'password' => 'agent-pass',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('code', 'OTP_SENT')
            ->assertJsonStructure([
                'meta' => ['otp_token', 'masked_phone', 'expires_in_seconds', 'next_step'],
            ]);
    }

    /** @test */
    public function agent_verify_otp_validates_fields()
    {
        $response = $this->postJson('/api/v1/auth/agent/verify-otp', []);
        $response->assertStatus(422);
    }

    /** @test */
    public function agent_verify_otp_fails_with_invalid_token()
    {
        $response = $this->postJson('/api/v1/auth/agent/verify-otp', [
            'otp_token' => str_repeat('A', 26),
            'otp_code' => '123456',
        ]);
        $response->assertStatus(401)->assertJsonPath('code', 'OTP_FAILED');
    }

    /** @test */
    public function rate_limit_kicks_in_after_too_many_attempts()
    {
        // 10 failed attempts within 1 minute on same IP → rate limited
        for ($i = 0; $i < 11; $i++) {
            $response = $this->postJson('/api/v1/auth/login', [
                'role' => 'customer',
                'national_id' => '12345678',
                'phone' => '+967700000000',
                'password' => 'wrong',
            ]);

            if ($i >= 10) {
                $response->assertStatus(429);
                return;
            }
        }

        $this->fail('Rate limit did not trigger');
    }
}
