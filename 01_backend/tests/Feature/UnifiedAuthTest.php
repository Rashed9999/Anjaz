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
        // AMIAL-FIX: الدخول بالهاتف + كلمة السرّ فقط (national_id يخصّ KYC)
        $response = $this->postJson('/api/v1/auth/login', ['role' => 'customer']);
        $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');
        $response->assertJsonStructure(['errors' => ['phone', 'password']]);
    }

    /** @test */
    public function customer_login_fails_with_invalid_credentials()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'role' => 'customer',
            'phone' => '+967700000099',
            'password' => 'wrong-password',
        ]);
        $response->assertStatus(401)->assertJsonPath('code', 'AUTH_FAILED');
    }

    /** @test AMIAL-FIX: المسار السعيد — عميل مُسجَّل يدخل بالهاتف + كلمة السرّ */
    public function customer_can_login_with_phone_and_password()
    {
        // إصدار التوكن يستخدم Passport createToken → نحتاج عميل وصول شخصي
        \Illuminate\Support\Facades\Artisan::call('passport:install', ['--no-interaction' => true]);

        \App\Models\User::factory()->create([
            'type' => 2, 'role' => 'customer', 'zone_code' => 'SOUTH',
            'phone' => '967771230001', 'password' => \Illuminate\Support\Facades\Hash::make('1234'),
            'is_active' => 1,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'role' => 'customer', 'phone' => '967771230001', 'password' => '1234',
        ]);
        $response->assertStatus(200)->assertJsonPath('code', 'LOGIN_OK');
        $this->assertNotEmpty($response->json('meta.token'));
    }

    /** @test AMYAL-SEC-LOGIN-001: الدخول الثاني يُرجع «آخر تسجيل دخول» في الـ meta */
    public function second_login_returns_last_login_meta()
    {
        \Illuminate\Support\Facades\Artisan::call('passport:install', ['--no-interaction' => true]);

        \App\Models\User::factory()->create([
            'type' => 2, 'role' => 'customer', 'zone_code' => 'SOUTH',
            'phone' => '967771230009', 'password' => \Illuminate\Support\Facades\Hash::make('1234'),
            'is_active' => 1,
        ]);

        $payload = ['role' => 'customer', 'phone' => '967771230009', 'password' => '1234'];

        // الدخول الأوّل: لا يوجد سجلّ سابق → لا last_login
        $first = $this->postJson('/api/v1/auth/login', $payload);
        $first->assertStatus(200);
        $this->assertNull($first->json('meta.last_login'));

        // الدخول الثاني: يجب أن يظهر آخر دخول (وقت + IP + اسم المنطقة العربي)
        $second = $this->postJson('/api/v1/auth/login', $payload);
        $second->assertStatus(200);
        $this->assertNotEmpty($second->json('meta.last_login.at'));
        $this->assertArrayHasKey('ip', $second->json('meta.last_login'));
        // zone_code = SOUTH → «الجنوب» (نظام المناطق الجاهز، لا GeoIP)
        $this->assertEquals('الجنوب', $second->json('meta.last_login.zone'));
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
