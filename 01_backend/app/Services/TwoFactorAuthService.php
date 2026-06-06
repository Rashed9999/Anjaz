<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * AMIAL-2FA-001 (v1.8)
 *
 * TwoFactorAuthService — TOTP كامل (RFC 6238) بدون مكتبات خارجية.
 *
 * **التدفق:**
 *   1. setup($admin) → يولّد secret + QR URI + recovery codes
 *   2. الـ admin يمسح QR في Google Authenticator / Authy
 *   3. confirm($admin, $code) → يتحقق ويفعّل 2FA
 *   4. verify($admin, $code) → عند كل login لاحق
 *
 * **الأمان:**
 *   - الـ secret مشفّر at rest (يستخدم EncryptionService من v1.3)
 *   - recovery codes مشفّرة + single-use
 *   - time-window tolerance ±1 step (30s) للتعامل مع clock drift
 *   - replay protection: نفس الـ code لا يُقبل مرتين خلال نفس الـ window
 *   - rate limiting: 5 محاولات فاشلة → قفل مؤقت
 *
 * **معايير:**
 *   - SHA1 (الافتراضي لـ Google Authenticator)
 *   - 6 digits
 *   - 30-second time step
 */
class TwoFactorAuthService
{
    private const DIGITS = 6;
    private const PERIOD = 30; // seconds
    private const WINDOW_TOLERANCE = 1; // ±1 step
    private const ALGORITHM = 'sha1';
    private const ISSUER = 'Amyal Pay';
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_MINUTES = 15;

    public function __construct(
        private readonly EncryptionService $encryption,
    ) {}

    // ============================================================
    // Setup
    // ============================================================

    /**
     * بدء إعداد 2FA — يولّد secret + QR + recovery codes.
     * لا يُفعّل بعد (ينتظر confirm).
     *
     * @return array{secret: string, qr_uri: string, recovery_codes: array}
     */
    public function setup(User $admin): array
    {
        $secret = $this->generateSecret();
        $recoveryCodes = $this->generateRecoveryCodes();

        // حفظ مشفّر (لكن غير مفعّل بعد)
        $admin->two_factor_secret = $this->encryption->encrypt($secret);
        $admin->two_factor_recovery_codes = $this->encryption->encrypt(json_encode($recoveryCodes));
        $admin->two_factor_enabled = false;
        $admin->two_factor_confirmed_at = null;
        $admin->save();

        $accountLabel = $admin->email ?? "admin_{$admin->id}";
        $qrUri = $this->buildOtpAuthUri($secret, $accountLabel);

        return [
            'secret' => $secret, // يُعرض مرة واحدة فقط
            'qr_uri' => $qrUri,
            'recovery_codes' => $recoveryCodes, // تُعرض مرة واحدة فقط
        ];
    }

    /**
     * تأكيد الإعداد بـ code من التطبيق → يفعّل 2FA.
     */
    public function confirm(User $admin, string $code): bool
    {
        if (empty($admin->two_factor_secret)) {
            throw new RuntimeException('2FA setup not initiated');
        }

        $secret = $this->encryption->decrypt($admin->two_factor_secret);
        if (!$this->verifyTotp($secret, $code)) {
            return false;
        }

        $admin->two_factor_enabled = true;
        $admin->two_factor_confirmed_at = now();
        $admin->save();

        Log::info('2FA enabled', ['user_id' => $admin->id]);
        return true;
    }

    // ============================================================
    // Verify (at login)
    // ============================================================

    /**
     * التحقق من code عند تسجيل الدخول.
     * يدعم TOTP code أو recovery code.
     */
    public function verify(User $admin, string $code): bool
    {
        $this->guardRateLimit($admin);

        if (empty($admin->two_factor_secret) || !$admin->two_factor_enabled) {
            return false;
        }

        // محاولة TOTP أولاً
        $secret = $this->encryption->decrypt($admin->two_factor_secret);
        if ($this->verifyTotp($secret, $code)) {
            $this->recordAttempt($admin, true, 'totp');
            return true;
        }

        // محاولة recovery code
        if ($this->verifyRecoveryCode($admin, $code)) {
            $this->recordAttempt($admin, true, 'recovery_code');
            return true;
        }

        $this->recordAttempt($admin, false, 'totp');
        return false;
    }

    /**
     * تعطيل 2FA (يتطلب verification أولاً في الـ controller).
     */
    public function disable(User $admin): void
    {
        $admin->two_factor_secret = null;
        $admin->two_factor_recovery_codes = null;
        $admin->two_factor_enabled = false;
        $admin->two_factor_confirmed_at = null;
        $admin->save();

        Log::info('2FA disabled', ['user_id' => $admin->id]);
    }

    // ============================================================
    // TOTP Core (RFC 6238)
    // ============================================================

    /**
     * توليد secret (base32, 20 bytes = 160 bits).
     */
    public function generateSecret(): string
    {
        $randomBytes = random_bytes(20);
        return $this->base32Encode($randomBytes);
    }

    /**
     * توليد TOTP code للـ secret في زمن معين.
     */
    public function generateTotp(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter = (int) floor($timestamp / self::PERIOD);
        return $this->hotp($secret, $counter);
    }

    /**
     * التحقق من TOTP code (مع time-window tolerance + replay protection).
     */
    public function verifyTotp(string $secret, string $code): bool
    {
        $code = trim($code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $currentStep = (int) floor(time() / self::PERIOD);

        // فحص النوافذ ضمن الـ tolerance
        for ($i = -self::WINDOW_TOLERANCE; $i <= self::WINDOW_TOLERANCE; $i++) {
            $step = $currentStep + $i;
            $expected = $this->hotp($secret, $step);

            if (hash_equals($expected, $code)) {
                // replay protection: تأكد أن هذا الـ step لم يُستخدم
                $replayKey = 'totp_used:' . md5($secret) . ":{$step}";
                if (Cache::has($replayKey)) {
                    return false; // أُستخدم مسبقاً
                }
                Cache::put($replayKey, true, self::PERIOD * 2);
                return true;
            }
        }

        return false;
    }

    /**
     * HOTP (RFC 4226) — basis للـ TOTP.
     */
    private function hotp(string $base32Secret, int $counter): string
    {
        $key = $this->base32Decode($base32Secret);

        // counter كـ 8-byte big-endian
        $binCounter = pack('N*', 0, $counter);

        $hash = hash_hmac(self::ALGORITHM, $binCounter, $key, true);

        // dynamic truncation
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $otp = $truncated % (10 ** self::DIGITS);
        return str_pad((string) $otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    // ============================================================
    // Recovery Codes
    // ============================================================

    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            // format: XXXX-XXXX (10 hex chars + dash)
            $codes[] = strtoupper(
                bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2))
            );
        }
        return $codes;
    }

    private function verifyRecoveryCode(User $admin, string $code): bool
    {
        if (empty($admin->two_factor_recovery_codes)) {
            return false;
        }

        $code = strtoupper(trim($code));
        $codesJson = $this->encryption->decrypt($admin->two_factor_recovery_codes);
        $codes = json_decode($codesJson, true) ?? [];

        $index = array_search($code, $codes, true);
        if ($index === false) {
            return false;
        }

        // single-use: احذف الـ code المستخدم
        unset($codes[$index]);
        $admin->two_factor_recovery_codes = $this->encryption->encrypt(json_encode(array_values($codes)));
        $admin->save();

        Log::warning('2FA recovery code used', [
            'user_id' => $admin->id,
            'remaining_codes' => count($codes),
        ]);

        return true;
    }

    // ============================================================
    // QR / otpauth URI
    // ============================================================

    /**
     * بناء otpauth:// URI (للـ QR code).
     * الـ Flutter يحوّله إلى QR image.
     */
    public function buildOtpAuthUri(string $secret, string $accountLabel): string
    {
        $issuer = rawurlencode(self::ISSUER);
        $label = rawurlencode(self::ISSUER . ':' . $accountLabel);

        return "otpauth://totp/{$label}?"
            . "secret={$secret}"
            . "&issuer={$issuer}"
            . "&algorithm=" . strtoupper(self::ALGORITHM)
            . "&digits=" . self::DIGITS
            . "&period=" . self::PERIOD;
    }

    // ============================================================
    // Base32 (RFC 4648)
    // ============================================================

    private function base32Encode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $output = '';
        $buffer = 0;
        $bitsLeft = 0;

        foreach (str_split($data) as $char) {
            $buffer = ($buffer << 8) | ord($char);
            $bitsLeft += 8;
            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output .= $alphabet[($buffer >> $bitsLeft) & 0x1F];
            }
        }
        if ($bitsLeft > 0) {
            $output .= $alphabet[($buffer << (5 - $bitsLeft)) & 0x1F];
        }
        return $output;
    }

    private function base32Decode(string $data): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $data = rtrim(strtoupper($data), '=');
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        foreach (str_split($data) as $char) {
            $pos = strpos($alphabet, $char);
            if ($pos === false) continue;
            $buffer = ($buffer << 5) | $pos;
            $bitsLeft += 5;
            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xFF);
            }
        }
        return $output;
    }

    // ============================================================
    // Rate limiting + audit
    // ============================================================

    private function guardRateLimit(User $admin): void
    {
        $key = "2fa_attempts:{$admin->id}";
        if (Cache::get($key, 0) >= self::MAX_FAILED_ATTEMPTS) {
            throw new RuntimeException(
                'تم تجاوز محاولات التحقق. حاول بعد ' . self::LOCKOUT_MINUTES . ' دقيقة'
            );
        }
    }

    private function recordAttempt(User $admin, bool $success, string $method): void
    {
        $key = "2fa_attempts:{$admin->id}";
        if ($success) {
            Cache::forget($key);
        } else {
            $current = Cache::get($key, 0);
            Cache::put($key, $current + 1, now()->addMinutes(self::LOCKOUT_MINUTES));
        }

        try {
            DB::table('two_factor_attempts')->insert([
                'user_id' => $admin->id,
                'success' => $success,
                'method' => $method,
                'ip_address' => request()?->ip(),
                'attempted_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('2FA attempt log failed', ['err' => $e->getMessage()]);
        }
    }
}
