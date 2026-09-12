<?php

namespace App\Services\Otp;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-EMAIL-OTP-001 + AMIAL-EMAIL-IDENTITY-001
 *
 * One secure implementation for registration, password reset, PIN recovery and
 * verified email replacement. OTP values are never stored in plaintext and are
 * never returned to operators.
 */
class EmailOtpService
{
    public const PURPOSE_REGISTRATION = 'registration';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';
    public const PURPOSE_PIN_RECOVERY = 'pin_recovery';
    public const PURPOSE_EMAIL_CHANGE = 'email_change';

    public const PURPOSES = [
        self::PURPOSE_REGISTRATION,
        self::PURPOSE_PASSWORD_RESET,
        self::PURPOSE_PIN_RECOVERY,
        self::PURPOSE_EMAIL_CHANGE,
    ];

    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array{challenge_id:string,masked_email:string,expires_in_seconds:int,resend_after_seconds:int,delivery_status:string}
     */
    public function issue(
        string $email,
        string $purpose,
        ?int $userId = null,
        string $requestedByType = 'user',
        ?int $requestedById = null,
        array $meta = [],
    ): array {
        $email = $this->normalizeEmail($email);
        $this->assertPurpose($purpose);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('INVALID_EMAIL');
        }

        $now = now();
        $recent = DB::table('otp_challenges')
            ->where('identifier', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->orderByDesc('id')
            ->first();

        if ($recent && $recent->delivery_status !== 'failed' && $recent->resend_available_at) {
            $available = Carbon::parse($recent->resend_available_at);
            if ($available->isFuture()) {
                throw new RuntimeException('RESEND_TOO_SOON:' . max(1, now()->diffInSeconds($available)));
            }
        }

        // A new code invalidates all older active codes for the same purpose.
        DB::table('otp_challenges')
            ->where('identifier', $email)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update([
                'consumed_at' => $now,
                'delivery_status' => DB::raw("CASE WHEN delivery_status = 'failed' THEN delivery_status ELSE 'superseded' END"),
                'updated_at' => $now,
            ]);

        $otp = (string) random_int(100000, 999999);
        $challengeId = (string) Str::ulid();
        $ttl = max(1, (int) config('amial_otp.ttl_minutes', 5));
        $resend = max(30, (int) config('amial_otp.resend_seconds', 60));
        $maxAttempts = max(3, min(10, (int) config('amial_otp.max_attempts', 5)));

        DB::table('otp_challenges')->insert([
            'challenge_id' => $challengeId,
            'user_id' => $userId,
            'identifier' => $email,
            'channel' => 'email',
            'purpose' => $purpose,
            'token_hash' => Hash::make($otp),
            'expires_at' => $now->copy()->addMinutes($ttl),
            'resend_available_at' => $now->copy()->addSeconds($resend),
            'attempts' => 0,
            'max_attempts' => $maxAttempts,
            'delivery_status' => 'pending',
            'requested_by_type' => $requestedByType,
            'requested_by_id' => $requestedById,
            'meta' => empty($meta) ? null : json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $providerId = $this->sendViaResend($email, $otp, $purpose, $challengeId);
            DB::table('otp_challenges')->where('challenge_id', $challengeId)->update([
                'delivery_status' => 'sent',
                'provider_message_id' => $providerId,
                'last_error' => null,
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            DB::table('otp_challenges')->where('challenge_id', $challengeId)->update([
                'delivery_status' => 'failed',
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            throw new RuntimeException('OTP_DELIVERY_FAILED', previous: $e);
        }

        return [
            'challenge_id' => $challengeId,
            'masked_email' => $this->maskEmail($email),
            'expires_in_seconds' => $ttl * 60,
            'resend_after_seconds' => $resend,
            'delivery_status' => 'sent',
        ];
    }

    /**
     * Verifies the OTP once and returns a second, one-time verification token.
     * The verification token is what authorizes the sensitive action; the OTP
     * itself cannot be replayed for password/PIN/email changes.
     */
    public function verify(string $challengeId, string $email, string $purpose, string $otp): string
    {
        $email = $this->normalizeEmail($email);
        $this->assertPurpose($purpose);

        return DB::transaction(function () use ($challengeId, $email, $purpose, $otp) {
            $row = DB::table('otp_challenges')
                ->where('challenge_id', $challengeId)
                ->where('identifier', $email)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                throw new RuntimeException('OTP_NOT_FOUND');
            }
            if ($row->consumed_at) {
                throw new RuntimeException('OTP_ALREADY_USED');
            }
            if (Carbon::parse($row->expires_at)->isPast()) {
                DB::table('otp_challenges')->where('id', $row->id)->update([
                    'consumed_at' => now(),
                    'delivery_status' => 'expired',
                    'updated_at' => now(),
                ]);
                throw new RuntimeException('OTP_EXPIRED');
            }
            if ((int) $row->attempts >= (int) $row->max_attempts) {
                throw new RuntimeException('OTP_LOCKED');
            }

            if (!Hash::check($otp, $row->token_hash)) {
                $attempts = (int) $row->attempts + 1;
                $locked = $attempts >= (int) $row->max_attempts;
                DB::table('otp_challenges')->where('id', $row->id)->update([
                    'attempts' => $attempts,
                    'consumed_at' => $locked ? now() : null,
                    'delivery_status' => $locked ? 'locked' : $row->delivery_status,
                    'updated_at' => now(),
                ]);
                throw new RuntimeException($locked ? 'OTP_LOCKED' : 'OTP_INVALID');
            }

            $verificationToken = bin2hex(random_bytes(32));
            $verificationTtl = max(2, (int) config('amial_otp.verification_ttl_minutes', 10));

            DB::table('otp_challenges')->where('id', $row->id)->update([
                'verified_at' => now(),
                'verification_token_hash' => Hash::make($verificationToken),
                'verification_expires_at' => now()->addMinutes($verificationTtl),
                'updated_at' => now(),
            ]);

            return $verificationToken;
        });
    }

    /**
     * Consumes a previously verified challenge and returns its database row.
     */
    public function consumeVerification(
        string $challengeId,
        string $email,
        string $purpose,
        string $verificationToken,
    ): object {
        $email = $this->normalizeEmail($email);
        $this->assertPurpose($purpose);

        return DB::transaction(function () use ($challengeId, $email, $purpose, $verificationToken) {
            $row = DB::table('otp_challenges')
                ->where('challenge_id', $challengeId)
                ->where('identifier', $email)
                ->where('purpose', $purpose)
                ->lockForUpdate()
                ->first();

            if (!$row || !$row->verified_at || !$row->verification_token_hash) {
                throw new RuntimeException('OTP_NOT_VERIFIED');
            }
            if ($row->consumed_at) {
                throw new RuntimeException('OTP_ALREADY_USED');
            }
            if (!$row->verification_expires_at || Carbon::parse($row->verification_expires_at)->isPast()) {
                throw new RuntimeException('VERIFICATION_EXPIRED');
            }
            if (!Hash::check($verificationToken, $row->verification_token_hash)) {
                throw new RuntimeException('VERIFICATION_INVALID');
            }

            DB::table('otp_challenges')->where('id', $row->id)->update([
                'consumed_at' => now(),
                'updated_at' => now(),
            ]);

            return $row;
        });
    }

    public function status(string $challengeId): ?array
    {
        $row = DB::table('otp_challenges')->where('challenge_id', $challengeId)->first();
        if (!$row) {
            return null;
        }

        return [
            'challenge_id' => $row->challenge_id,
            'purpose' => $row->purpose,
            'masked_email' => $this->maskEmail($row->identifier),
            'delivery_status' => $row->delivery_status,
            'attempts' => (int) $row->attempts,
            'max_attempts' => (int) $row->max_attempts,
            'expires_at' => (string) $row->expires_at,
            'verified_at' => $row->verified_at ? (string) $row->verified_at : null,
            'consumed_at' => $row->consumed_at ? (string) $row->consumed_at : null,
            'provider_message_id' => $row->provider_message_id,
            'created_at' => (string) $row->created_at,
        ];
    }

    /** Update delivery status from a verified Resend webhook. */
    public function applyResendEvent(array $payload): void
    {
        $type = (string) ($payload['type'] ?? '');
        $data = (array) ($payload['data'] ?? []);
        $providerId = (string) ($data['email_id'] ?? '');
        if ($providerId === '') {
            return;
        }

        $status = match ($type) {
            'email.sent' => 'sent',
            'email.delivered' => 'delivered',
            'email.delivery_delayed' => 'delayed',
            'email.bounced' => 'bounced',
            'email.complained' => 'complained',
            default => null,
        };

        if ($status !== null) {
            DB::table('otp_challenges')->where('provider_message_id', $providerId)->update([
                'delivery_status' => $status,
                'updated_at' => now(),
            ]);
        }
    }

    public function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        if ($domain === '') {
            return '***';
        }
        $prefix = mb_substr($local, 0, min(2, mb_strlen($local)));
        return $prefix . '***@' . $domain;
    }

    private function sendViaResend(string $email, string $otp, string $purpose, string $challengeId): string
    {
        $apiKey = (string) config('amial_otp.resend.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('RESEND_API_KEY_MISSING');
        }

        $fromAddress = (string) config('amial_otp.resend.from_address', 'verify@amialpay.com');
        $fromName = (string) config('amial_otp.resend.from_name', 'Amial Pay');
        $apiUrl = (string) config('amial_otp.resend.api_url', 'https://api.resend.com/emails');
        $minutes = max(1, (int) config('amial_otp.ttl_minutes', 5));
        $purposeLabel = match ($purpose) {
            self::PURPOSE_REGISTRATION => 'إنشاء حساب أميال',
            self::PURPOSE_PASSWORD_RESET => 'استعادة كلمة المرور',
            self::PURPOSE_PIN_RECOVERY => 'استعادة رمز PIN',
            self::PURPOSE_EMAIL_CHANGE => 'تغيير البريد الإلكتروني',
            default => 'التحقق من الحساب',
        };

        $subject = "رمز التحقق من أميال — {$purposeLabel}";
        $text = "رمز التحقق الخاص بك هو: {$otp}\nصالح لمدة {$minutes} دقائق.\nإذا لم تطلب هذا الرمز فتجاهل الرسالة ولا تشاركه مع أي شخص، بما في ذلك فريق الدعم.";
        $html = '<div dir="rtl" style="font-family:Arial,sans-serif;max-width:560px;margin:auto">'
            . '<h2>أميال باي</h2>'
            . '<p>' . e($purposeLabel) . '</p>'
            . '<div style="font-size:30px;font-weight:700;letter-spacing:8px;padding:18px 0">' . e($otp) . '</div>'
            . '<p>هذا الرمز صالح لمدة ' . $minutes . ' دقائق ويعمل مرة واحدة فقط.</p>'
            . '<p>لن يطلب منك فريق دعم أميال كشف هذا الرمز.</p>'
            . '</div>';

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout(12)
            ->retry(2, 250, throw: false)
            ->post($apiUrl, [
                'from' => $fromName . ' <' . $fromAddress . '>',
                'to' => [$email],
                'subject' => $subject,
                'text' => $text,
                'html' => $html,
                'headers' => ['X-Amial-Challenge' => $challengeId],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('RESEND_HTTP_' . $response->status() . ':' . mb_substr($response->body(), 0, 500));
        }

        $id = (string) ($response->json('id') ?? '');
        if ($id === '') {
            throw new RuntimeException('RESEND_MESSAGE_ID_MISSING');
        }

        return $id;
    }

    private function assertPurpose(string $purpose): void
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new RuntimeException('INVALID_OTP_PURPOSE');
        }
    }
}
