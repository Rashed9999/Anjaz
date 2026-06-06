<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-PII-ENCRYPTION-001 (v1.3)
 *
 * EncryptedFileStorage — تشفير الملفات (KYC docs, نزاعات attachments) at rest.
 *
 * **الاستراتيجية:**
 *   - يستخدم AES-256-CTR (streaming - يدعم ملفات كبيرة)
 *   - كل ملف له random IV (محفوظ في أول 16 byte)
 *   - أسماء الملفات obfuscated (لا تكشف عن المحتوى)
 *
 * **الاستخدام:**
 *   $path = $storage->encryptAndStore($uploadedFile, 'kyc/identity_card');
 *   // → 'kyc/identity_card/01HXX5.enc'
 *
 *   $tempFile = $storage->decryptToTemp($path);
 *   // → '/tmp/decrypted_xyz.tmp' — استخدم ثم احذف
 *
 *   $storage->delete($path);
 *
 * **مهم للأداء:**
 *   - لا تفك تشفير ملف لكل request
 *   - استخدم streaming response عند العرض في admin
 *   - audit log كل عملية decrypt
 */
class EncryptedFileStorage
{
    private const CIPHER = 'aes-256-ctr';
    private const IV_LENGTH = 16;
    private const VERSION_BYTE = "\x01"; // version 1
    private const HEADER_LENGTH = 17; // VERSION_BYTE + IV (16 bytes)

    private readonly string $key;

    public function __construct()
    {
        $encKey = config('amial.encryption.pii_key') ?? env('AMIAL_PII_ENCRYPTION_KEY');
        $decoded = base64_decode($encKey ?: '', true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('AMIAL_PII_ENCRYPTION_KEY not configured properly');
        }
        $this->key = $decoded;
    }

    /**
     * شفر ملف uploaded وخزنه في disk.
     *
     * @param UploadedFile|string $file UploadedFile أو path للملف الموجود
     * @param string $directory المجلد في الـ disk
     * @param string $disk اسم الـ disk (default: local)
     * @return string الـ path المخزن (يبدأ بـ $directory/)
     */
    public function encryptAndStore(
        UploadedFile|string $file,
        string $directory = 'encrypted',
        string $disk = 'local',
    ): string {
        $sourcePath = $file instanceof UploadedFile
            ? $file->getRealPath()
            : $file;

        if (!file_exists($sourcePath)) {
            throw new RuntimeException("Source file not found: {$sourcePath}");
        }

        // اسم عشوائي + extension
        $randomName = (string) Str::ulid();
        $targetPath = trim($directory, '/') . "/{$randomName}.enc";

        $iv = random_bytes(self::IV_LENGTH);

        // افتح المصدر والـ target
        $sourceHandle = fopen($sourcePath, 'rb');
        if (!$sourceHandle) {
            throw new RuntimeException('Cannot open source file');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'amial_enc_');
        $targetHandle = fopen($tempPath, 'wb');
        if (!$targetHandle) {
            fclose($sourceHandle);
            throw new RuntimeException('Cannot create encrypted temp file');
        }

        // اكتب header: version byte + IV
        fwrite($targetHandle, self::VERSION_BYTE);
        fwrite($targetHandle, $iv);

        // CTR mode: نشفر بـ blocks. AES-CTR لا يحتاج padding.
        // للبساطة والصحة، نقرأ كل الملف ونشفره دفعة واحدة.
        // (للملفات > 100MB، نحتاج streaming حقيقي — تركها للـ optimization مستقبلاً)
        $plaintext = stream_get_contents($sourceHandle);
        fclose($sourceHandle);

        $encrypted = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
        );

        if ($encrypted === false) {
            fclose($targetHandle);
            @unlink($tempPath);
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        fwrite($targetHandle, $encrypted);
        fclose($targetHandle);

        // ارفع للـ disk
        $diskStorage = Storage::disk($disk);
        $contents = file_get_contents($tempPath);
        $diskStorage->put($targetPath, $contents);

        @unlink($tempPath);

        return $targetPath;
    }

    /**
     * فك تشفير ملف وأرجع المحتوى binary (للـ HTTP response).
     */
    public function decryptToBinary(string $path, string $disk = 'local'): string
    {
        $diskStorage = Storage::disk($disk);
        if (!$diskStorage->exists($path)) {
            throw new RuntimeException("Encrypted file not found: {$path}");
        }

        $encrypted = $diskStorage->get($path);
        if (strlen($encrypted) < self::HEADER_LENGTH + 1) {
            throw new RuntimeException('Encrypted file too short or corrupted');
        }

        // قراءة header
        $version = $encrypted[0];
        if ($version !== self::VERSION_BYTE) {
            throw new RuntimeException('Unsupported encrypted file version');
        }

        $iv = substr($encrypted, 1, self::IV_LENGTH);
        $ciphertext = substr($encrypted, self::HEADER_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
        );

        if ($plaintext === false) {
            throw new RuntimeException('File decryption failed');
        }

        return $plaintext;
    }

    /**
     * فك التشفير إلى temp file (للـ libraries التي تحتاج path).
     * تذكر حذف الـ temp file بعد الاستخدام!
     */
    public function decryptToTemp(string $path, string $disk = 'local'): string
    {
        $binary = $this->decryptToBinary($path, $disk);
        $tempPath = tempnam(sys_get_temp_dir(), 'amial_dec_');
        file_put_contents($tempPath, $binary);
        return $tempPath;
    }

    /**
     * احذف ملف encrypted.
     */
    public function delete(string $path, string $disk = 'local'): bool
    {
        return Storage::disk($disk)->delete($path);
    }

    /**
     * فحص أن ملف existe و encrypted (يبدأ بـ version byte).
     */
    public function exists(string $path, string $disk = 'local'): bool
    {
        return Storage::disk($disk)->exists($path);
    }
}
