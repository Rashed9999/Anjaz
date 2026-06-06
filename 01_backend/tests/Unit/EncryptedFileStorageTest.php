<?php

namespace Tests\Unit;

use App\Services\EncryptedFileStorage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AMIAL-PII-ENCRYPTION-001 (v1.3)
 */
class EncryptedFileStorageTest extends TestCase
{
    private EncryptedFileStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $testKey = base64_encode(random_bytes(32));
        config()->set('amial.encryption.pii_key', $testKey);

        $this->storage = new EncryptedFileStorage();
    }

    /** @test */
    public function it_encrypts_file_and_decrypts_back()
    {
        $tempSource = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tempSource, 'KYC document content - بيانات الهوية الحساسة');

        $encryptedPath = $this->storage->encryptAndStore($tempSource, 'kyc');

        $this->assertStringStartsWith('kyc/', $encryptedPath);
        $this->assertStringEndsWith('.enc', $encryptedPath);

        // الملف المشفر موجود في disk
        $this->assertTrue(Storage::disk('local')->exists($encryptedPath));

        // المحتوى المشفر مختلف عن المصدر
        $encryptedContent = Storage::disk('local')->get($encryptedPath);
        $this->assertNotEquals('KYC document content - بيانات الهوية الحساسة', $encryptedContent);

        // الفك يعيد المحتوى الأصلي
        $decrypted = $this->storage->decryptToBinary($encryptedPath);
        $this->assertEquals('KYC document content - بيانات الهوية الحساسة', $decrypted);

        @unlink($tempSource);
    }

    /** @test */
    public function each_encryption_has_different_iv_and_ciphertext()
    {
        $tempSource = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tempSource, 'same content');

        $path1 = $this->storage->encryptAndStore($tempSource, 'kyc');
        $path2 = $this->storage->encryptAndStore($tempSource, 'kyc');

        $content1 = Storage::disk('local')->get($path1);
        $content2 = Storage::disk('local')->get($path2);

        $this->assertNotEquals($content1, $content2,
            'Random IV should make ciphertexts different');

        // لكن كلاهما يفك للنفس
        $this->assertEquals('same content', $this->storage->decryptToBinary($path1));
        $this->assertEquals('same content', $this->storage->decryptToBinary($path2));

        @unlink($tempSource);
    }

    /** @test */
    public function decrypt_to_temp_creates_usable_file()
    {
        $tempSource = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tempSource, 'PNG image binary data...');

        $encryptedPath = $this->storage->encryptAndStore($tempSource, 'kyc');
        $tempDecrypted = $this->storage->decryptToTemp($encryptedPath);

        $this->assertFileExists($tempDecrypted);
        $this->assertEquals('PNG image binary data...', file_get_contents($tempDecrypted));

        @unlink($tempSource);
        @unlink($tempDecrypted);
    }

    /** @test */
    public function delete_removes_encrypted_file()
    {
        $tempSource = tempnam(sys_get_temp_dir(), 'src_');
        file_put_contents($tempSource, 'content');

        $path = $this->storage->encryptAndStore($tempSource, 'kyc');
        $this->assertTrue(Storage::disk('local')->exists($path));

        $this->storage->delete($path);
        $this->assertFalse(Storage::disk('local')->exists($path));

        @unlink($tempSource);
    }

    /** @test */
    public function nonexistent_file_decryption_throws()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->storage->decryptToBinary('kyc/nonexistent.enc');
    }

    /** @test */
    public function corrupted_file_decryption_throws()
    {
        Storage::disk('local')->put('kyc/corrupted.enc', 'definitely not a valid encrypted file');

        $this->expectException(\RuntimeException::class);
        $this->storage->decryptToBinary('kyc/corrupted.enc');
    }
}
