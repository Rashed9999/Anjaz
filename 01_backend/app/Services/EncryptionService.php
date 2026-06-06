<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AMIAL-PII-ENCRYPTION-001 (v1.3)
 *
 * EncryptionService — تشفير PII at rest باستخدام AES-256-GCM + HMAC blind index.
 *
 * **التصميم:**
 *   - AES-256-GCM للـ encryption (authenticated → يكتشف العبث)
 *   - HMAC-SHA256 للـ blind index (يسمح بالبحث على المحتوى)
 *   - مفاتيح منفصلة لكل غرض (compromise واحد لا يكسر الآخر)
 *   - Versioning للـ ciphertext (`v1:...`) → يسمح بـ key rotation مستقبلاً
 *
 * **الإعداد في .env:**
 *   AMIAL_PII_ENCRYPTION_KEY="..." (base64 of 32 random bytes)
 *   AMIAL_PII_BLIND_INDEX_KEY="..." (base64 of 32 random bytes)
 *
 *   توليد:
 *     php -r 'echo base64_encode(random_bytes(32)) . PHP_EOL;'
 *
 * **مهم:**
 *   - لا تغير الـ keys بدون migration command
 *   - الـ keys في .env (لا تضع في git)
 *   - استخدم secret manager في production (AWS Secrets Manager, HashiCorp Vault)
 */
class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION_PREFIX = 'v1:';
    private const IV_LENGTH = 12; // GCM standard
    private const TAG_LENGTH = 16;

    private readonly string $encryptionKey;
    private readonly string $blindIndexKey;

    public function __construct()
    {
        $encKey = config('amial.encryption.pii_key') ?? env('AMIAL_PII_ENCRYPTION_KEY');
        $bidxKey = config('amial.encryption.blind_index_key') ?? env('AMIAL_PII_BLIND_INDEX_KEY');

        if (empty($encKey) || empty($bidxKey)) {
            throw new RuntimeException(
                'AMIAL PII encryption keys not configured. ' .
                'Set AMIAL_PII_ENCRYPTION_KEY and AMIAL_PII_BLIND_INDEX_KEY in .env. ' .
                'Generate via: php -r \'echo base64_encode(random_bytes(32)) . PHP_EOL;\''
            );
        }

        $decodedEncKey = base64_decode($encKey, true);
        $decodedBidxKey = base64_decode($bidxKey, true);

        if ($decodedEncKey === false || strlen($decodedEncKey) !== 32) {
            throw new RuntimeException('AMIAL_PII_ENCRYPTION_KEY must be base64-encoded 32 bytes');
        }
        if ($decodedBidxKey === false || strlen($decodedBidxKey) !== 32) {
            throw new RuntimeException('AMIAL_PII_BLIND_INDEX_KEY must be base64-encoded 32 bytes');
        }

        $this->encryptionKey = $decodedEncKey;
        $this->blindIndexKey = $decodedBidxKey;
    }

    /**
     * شفر قيمة. يعيد ciphertext مع version prefix.
     *
     * Format: `v1:base64(iv | tag | encrypted)`
     */
    public function encrypt(?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }

        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '', // additional authenticated data (empty)
            self::TAG_LENGTH,
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        return self::VERSION_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * فك التشفير. يعيد plaintext أو null.
     *
     * يفشل بـ exception إن:
     *   - الـ tag لا يطابق (tampered)
     *   - الـ version غير مدعوم
     *   - الـ key خاطئ
     */
    public function decrypt(?string $ciphertext): ?string
    {
        if ($ciphertext === null || $ciphertext === '') {
            return null;
        }

        // فحص version
        if (!str_starts_with($ciphertext, self::VERSION_PREFIX)) {
            throw new RuntimeException('Unsupported ciphertext version');
        }

        $payload = base64_decode(substr($ciphertext, strlen(self::VERSION_PREFIX)), true);
        if ($payload === false || strlen($payload) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new RuntimeException('Invalid ciphertext format');
        }

        $iv = substr($payload, 0, self::IV_LENGTH);
        $tag = substr($payload, self::IV_LENGTH, self::TAG_LENGTH);
        $encrypted = substr($payload, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
        );

        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed — possible tampering or wrong key');
        }

        return $plaintext;
    }

    /**
     * Try-decrypt: يعيد null عند الفشل بدلاً من exception.
     * مفيد للـ fallback في الـ models (data قديمة بدون encryption).
     */
    public function tryDecrypt(?string $ciphertext): ?string
    {
        if ($ciphertext === null) return null;
        try {
            return $this->decrypt($ciphertext);
        } catch (\Throwable $e) {
            Log::warning('PII decrypt failed', [
                'error' => $e->getMessage(),
                'ciphertext_prefix' => substr($ciphertext, 0, 10),
            ]);
            return null;
        }
    }

    /**
     * Blind index — HMAC-SHA256 hash للبحث على قيم encrypted.
     *
     * Deterministic: نفس المدخل = نفس المخرج (دائماً).
     * Non-reversible: لا يمكن استرداد القيمة من الـ hash.
     * يستخدم في WHERE clauses و UNIQUE indexes.
     *
     * **مهم:** القيمة تُنرمل قبل الـ hash:
     *   - trim whitespace
     *   - lowercase (للـ emails)
     *   - remove non-digit chars (للهواتف)
     */
    public function blindIndex(?string $value, ?string $normalizer = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = $this->normalize($value, $normalizer);
        return hash_hmac('sha256', $normalized, $this->blindIndexKey);
    }

    private function normalize(string $value, ?string $type): string
    {
        $value = trim($value);
        return match ($type) {
            'phone' => preg_replace('/[^\d+]/', '', $value),
            'email' => mb_strtolower($value),
            'national_id' => preg_replace('/[^\d]/', '', $value),
            default => $value,
        };
    }

    /**
     * إخفاء جزئي للعرض في UI/logs.
     *
     * Examples:
     *   maskPhone('+967701234567')      => '+967701***567'
     *   maskEmail('ahmed@example.com')  => 'ah***@example.com'
     *   maskName('محمد علي الأحمدي')      => 'محمد *** الأحمدي'
     */
    public function maskPhone(?string $phone): ?string
    {
        if (empty($phone)) return null;
        $len = strlen($phone);
        if ($len < 7) return $phone;
        return substr($phone, 0, $len - 6) . '***' . substr($phone, -3);
    }

    public function maskEmail(?string $email): ?string
    {
        if (empty($email) || !str_contains($email, '@')) return $email;
        [$local, $domain] = explode('@', $email, 2);
        $localMasked = strlen($local) <= 2
            ? str_repeat('*', strlen($local))
            : substr($local, 0, 2) . str_repeat('*', max(2, strlen($local) - 2));
        return "{$localMasked}@{$domain}";
    }

    public function maskNationalId(?string $id): ?string
    {
        if (empty($id)) return null;
        $len = strlen($id);
        if ($len < 5) return str_repeat('*', $len);
        return substr($id, 0, 2) . str_repeat('*', $len - 4) . substr($id, -2);
    }

    /**
     * هل القيمة encrypted (تبدأ بـ version prefix)؟
     */
    public function isEncrypted(?string $value): bool
    {
        return $value !== null && str_starts_with($value, self::VERSION_PREFIX);
    }
}
