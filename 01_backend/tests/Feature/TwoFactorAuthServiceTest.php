<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-2FA-001 (v1.8) — اختبارات TOTP.
 */
class TwoFactorAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorAuthService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('amial.encryption.pii_key', base64_encode(random_bytes(32)));
        config()->set('amial.encryption.blind_index_key', base64_encode(random_bytes(32)));

        $this->service = app(TwoFactorAuthService::class);
        $this->admin = User::factory()->create(['email' => 'admin@amyal.test']);
    }

    /** @test */
    public function it_generates_valid_base32_secret()
    {
        $secret = $this->service->generateSecret();
        // base32 = A-Z, 2-7
        $this->assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        $this->assertGreaterThanOrEqual(32, strlen($secret)); // 20 bytes → 32 chars
    }

    /** @test */
    public function generated_totp_is_six_digits()
    {
        $secret = $this->service->generateSecret();
        $code = $this->service->generateTotp($secret);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    /** @test */
    public function it_verifies_its_own_generated_totp()
    {
        $secret = $this->service->generateSecret();
        $code = $this->service->generateTotp($secret);
        $this->assertTrue($this->service->verifyTotp($secret, $code));
    }

    /** @test */
    public function it_rejects_wrong_totp()
    {
        $secret = $this->service->generateSecret();
        $this->assertFalse($this->service->verifyTotp($secret, '000000'));
        $this->assertFalse($this->service->verifyTotp($secret, '123456'));
    }

    /** @test */
    public function it_rejects_invalid_format()
    {
        $secret = $this->service->generateSecret();
        $this->assertFalse($this->service->verifyTotp($secret, '12345'));   // 5 digits
        $this->assertFalse($this->service->verifyTotp($secret, 'abcdef'));  // letters
        $this->assertFalse($this->service->verifyTotp($secret, ''));        // empty
    }

    /** @test */
    public function totp_matches_known_rfc_test_vector()
    {
        // RFC 6238 test: secret "12345678901234567890" (ASCII) at T=59 → 94287082
        // base32 of "12345678901234567890" = GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $code = $this->service->generateTotp($secret, 59);
        // الـ RFC vector لـ SHA1 8-digit = 94287082، آخر 6 = 287082
        $this->assertEquals('287082', $code);
    }

    /** @test */
    public function setup_generates_secret_qr_and_recovery_codes()
    {
        $result = $this->service->setup($this->admin);

        $this->assertArrayHasKey('secret', $result);
        $this->assertArrayHasKey('qr_uri', $result);
        $this->assertArrayHasKey('recovery_codes', $result);
        $this->assertStringStartsWith('otpauth://totp/', $result['qr_uri']);
        $this->assertCount(8, $result['recovery_codes']);

        // 2FA لم يُفعَّل بعد (ينتظر confirm)
        $this->admin->refresh();
        $this->assertFalse($this->admin->two_factor_enabled);
        $this->assertNotNull($this->admin->two_factor_secret);
    }

    /** @test */
    public function confirm_enables_2fa_with_correct_code()
    {
        $result = $this->service->setup($this->admin);
        $this->admin->refresh();

        $code = $this->service->generateTotp($result['secret']);
        $confirmed = $this->service->confirm($this->admin, $code);

        $this->assertTrue($confirmed);
        $this->admin->refresh();
        $this->assertTrue($this->admin->two_factor_enabled);
        $this->assertNotNull($this->admin->two_factor_confirmed_at);
    }

    /** @test */
    public function confirm_fails_with_wrong_code()
    {
        $this->service->setup($this->admin);
        $this->admin->refresh();

        $confirmed = $this->service->confirm($this->admin, '000000');
        $this->assertFalse($confirmed);
        $this->admin->refresh();
        $this->assertFalse($this->admin->two_factor_enabled);
    }

    /** @test */
    public function verify_works_after_enabling()
    {
        $result = $this->service->setup($this->admin);
        $this->admin->refresh();
        $code = $this->service->generateTotp($result['secret']);
        $this->service->confirm($this->admin, $code);
        $this->admin->refresh();

        // رمز جديد للتحقق (بعد فترة لتجنب replay)
        // نولّد لـ step مختلف
        $newCode = $this->service->generateTotp($result['secret'], time() + 60);
        // verify يستخدم الوقت الحالي، لذا نتحقق بـ code الحالي في step مختلف
        // للاختبار: نتحقق أن code صحيح حالي يعمل
        $currentCode = $this->service->generateTotp($result['secret']);
        // لكن قد يكون مُستخدماً في confirm — نمسح الـ replay cache
        \Cache::flush();
        $this->assertTrue($this->service->verify($this->admin, $currentCode));
    }

    /** @test */
    public function recovery_code_works_and_is_single_use()
    {
        $result = $this->service->setup($this->admin);
        $this->admin->refresh();
        $code = $this->service->generateTotp($result['secret']);
        $this->service->confirm($this->admin, $code);
        $this->admin->refresh();

        $recoveryCode = $result['recovery_codes'][0];

        // أول استخدام ينجح
        $this->assertTrue($this->service->verify($this->admin, $recoveryCode));

        // ثاني استخدام يفشل (single-use)
        $this->admin->refresh();
        $this->assertFalse($this->service->verify($this->admin, $recoveryCode));
    }

    /** @test */
    public function replay_protection_prevents_code_reuse()
    {
        \Cache::flush();
        $result = $this->service->setup($this->admin);
        $this->admin->refresh();
        $code = $this->service->generateTotp($result['secret']);
        $this->service->confirm($this->admin, $code);
        $this->admin->refresh();

        \Cache::flush();
        $currentCode = $this->service->generateTotp($result['secret']);

        // أول مرة ينجح
        $this->assertTrue($this->service->verify($this->admin, $currentCode));
        // نفس الـ code فوراً → replay → يفشل
        $this->assertFalse($this->service->verify($this->admin, $currentCode));
    }

    /** @test */
    public function disable_clears_2fa_data()
    {
        $result = $this->service->setup($this->admin);
        $this->admin->refresh();
        $code = $this->service->generateTotp($result['secret']);
        $this->service->confirm($this->admin, $code);

        $this->service->disable($this->admin->fresh());

        $this->admin->refresh();
        $this->assertFalse($this->admin->two_factor_enabled);
        $this->assertNull($this->admin->two_factor_secret);
        $this->assertNull($this->admin->two_factor_recovery_codes);
    }

    /** @test */
    public function secret_is_encrypted_at_rest()
    {
        $result = $this->service->setup($this->admin);
        $this->admin->refresh();

        // الـ secret المخزّن مشفّر (ليس plaintext)
        $this->assertNotEquals($result['secret'], $this->admin->two_factor_secret);
        $this->assertStringStartsWith('v1:', $this->admin->two_factor_secret);
    }
}
