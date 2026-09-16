<?php

namespace App\Services\Messaging\Providers\Sms;

use App\Services\Messaging\AbstractProvider;
use Illuminate\Support\Facades\Http;

/** AMIAL-MESSAGING-001 — MSG91. الرقمُ بلا «+» في هذا المزوّد. */
class Msg91SmsProvider extends AbstractProvider
{
    /**
     * MSG91 SendOTP v5 is a JSON POST request.  The older api.msg91.com GET
     * endpoint can accept a request while ignoring its OTP body.
     */
    private const SEND_OTP_URL = 'https://control.msg91.com/api/v5/otp';

    public function key(): string { return 'msg91'; }

    public function channel(): string { return 'sms'; }

    public function priority(): int { return 40; }

    protected function requiredKeys(): array { return ['auth_key', 'template_id']; }

    public function sendOtp(string $to, string $otp): bool
    {
        $c = $this->config();

        return $this->attempt($to, function () use ($c, $to, $otp) {
            $query = http_build_query([
                'template_id' => $c['template_id'],
                'mobile' => $this->normalize($to, false),
                'authkey' => $c['auth_key'],
            ], '', '&', PHP_QUERY_RFC3986);

            $r = Http::timeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->asJson()
                ->post(self::SEND_OTP_URL . '?' . $query, ['OTP' => $otp]);

            return $r->successful() && ($r->json('type') ?? null) === 'success';
        });
    }
}
