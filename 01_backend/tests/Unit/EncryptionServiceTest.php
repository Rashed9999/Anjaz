<?php

namespace Tests\Unit;

use App\Services\EncryptionService;
use Tests\TestCase;

/**
 * AMIAL-PII-ENCRYPTION-001 (v1.3) — اختبارات.
 */
class EncryptionServiceTest extends TestCase
{
    private EncryptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // ضع keys للاختبار
        $testKey = base64_encode(random_bytes(32));
        $testBidxKey = base64_encode(random_bytes(32));

        config()->set('amial.encryption.pii_key', $testKey);
        config()->set('amial.encryption.blind_index_key', $testBidxKey);

        $this->service = new EncryptionService();
    }

    /** @test */
    public function it_encrypts_and_decrypts_round_trip()
    {
        $plain = '+967701234567';
        $encrypted = $this->service->encrypt($plain);

        $this->assertNotNull($encrypted);
        $this->assertNotEquals($plain, $encrypted);
        $this->assertStringStartsWith('v1:', $encrypted);

        $decrypted = $this->service->decrypt($encrypted);
        $this->assertEquals($plain, $decrypted);
    }

    /** @test */
    public function it_returns_null_for_empty_input()
    {
        $this->assertNull($this->service->encrypt(null));
        $this->assertNull($this->service->encrypt(''));
        $this->assertNull($this->service->decrypt(null));
        $this->assertNull($this->service->decrypt(''));
    }

    /** @test */
    public function encryption_is_randomized_same_input_different_output()
    {
        $plain = 'محمد علي الأحمدي';
        $enc1 = $this->service->encrypt($plain);
        $enc2 = $this->service->encrypt($plain);

        $this->assertNotEquals($enc1, $enc2,
            'AES-GCM should produce different ciphertexts for same plaintext');

        // لكن كلاهما يفك للنفس
        $this->assertEquals($plain, $this->service->decrypt($enc1));
        $this->assertEquals($plain, $this->service->decrypt($enc2));
    }

    /** @test */
    public function tampered_ciphertext_throws_exception()
    {
        $plain = '+967701234567';
        $encrypted = $this->service->encrypt($plain);

        // عبث: غير آخر byte
        $tampered = substr($encrypted, 0, -1) . 'A';

        $this->expectException(\RuntimeException::class);
        $this->service->decrypt($tampered);
    }

    /** @test */
    public function try_decrypt_returns_null_on_failure()
    {
        $result = $this->service->tryDecrypt('v1:invalid_base64_garbage');
        $this->assertNull($result);
    }

    /** @test */
    public function blind_index_is_deterministic()
    {
        $phone = '+967701234567';
        $idx1 = $this->service->blindIndex($phone, 'phone');
        $idx2 = $this->service->blindIndex($phone, 'phone');

        $this->assertEquals($idx1, $idx2);
        $this->assertEquals(64, strlen($idx1)); // SHA-256 hex = 64 chars
    }

    /** @test */
    public function blind_index_normalizes_phone()
    {
        // كلها يجب أن تنتج نفس الـ index
        $idx1 = $this->service->blindIndex('+967701234567', 'phone');
        $idx2 = $this->service->blindIndex(' +967701234567 ', 'phone'); // مسافات
        $idx3 = $this->service->blindIndex('+967-70-123-4567', 'phone'); // مع شُرَط

        $this->assertEquals($idx1, $idx2);
        $this->assertEquals($idx1, $idx3);
    }

    /** @test */
    public function blind_index_normalizes_email_lowercase()
    {
        $idx1 = $this->service->blindIndex('ahmed@example.com', 'email');
        $idx2 = $this->service->blindIndex('AHMED@EXAMPLE.COM', 'email');
        $idx3 = $this->service->blindIndex(' Ahmed@Example.Com ', 'email');

        $this->assertEquals($idx1, $idx2);
        $this->assertEquals($idx1, $idx3);
    }

    /** @test */
    public function blind_index_different_inputs_different_outputs()
    {
        $idx1 = $this->service->blindIndex('+967701234567', 'phone');
        $idx2 = $this->service->blindIndex('+967701234568', 'phone'); // فرق واحد

        $this->assertNotEquals($idx1, $idx2);
    }

    /** @test */
    public function mask_phone_hides_middle_digits()
    {
        $masked = $this->service->maskPhone('+967701234567');
        $this->assertStringEndsWith('567', $masked);
        $this->assertStringContainsString('***', $masked);
        $this->assertStringStartsWith('+967', $masked);
    }

    /** @test */
    public function mask_email_hides_local_part()
    {
        $masked = $this->service->maskEmail('ahmed@example.com');
        $this->assertStringContainsString('@example.com', $masked);
        $this->assertStringContainsString('*', $masked);
        $this->assertStringStartsWith('ah', $masked);
    }

    /** @test */
    public function is_encrypted_detects_version_prefix()
    {
        $encrypted = $this->service->encrypt('test');
        $this->assertTrue($this->service->isEncrypted($encrypted));
        $this->assertFalse($this->service->isEncrypted('plaintext'));
        $this->assertFalse($this->service->isEncrypted(null));
    }

    /** @test */
    public function handles_arabic_text_correctly()
    {
        $arabic = 'محمد علي الأحمدي - صنعاء، اليمن';
        $encrypted = $this->service->encrypt($arabic);
        $decrypted = $this->service->decrypt($encrypted);

        $this->assertEquals($arabic, $decrypted);
    }

    /** @test */
    public function handles_long_text_for_addresses()
    {
        $address = str_repeat('عدن، حي المنصورة، شارع 12، منزل رقم 45 ', 10);
        $encrypted = $this->service->encrypt($address);
        $decrypted = $this->service->decrypt($encrypted);

        $this->assertEquals($address, $decrypted);
    }
}
