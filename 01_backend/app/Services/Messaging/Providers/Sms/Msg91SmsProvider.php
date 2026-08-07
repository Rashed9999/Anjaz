<?php

namespace App\Services\Messaging\Providers\Sms;

use App\Services\Messaging\AbstractProvider;
use Illuminate\Support\Facades\Http;

/** AMIAL-MESSAGING-001 — MSG91. الرقمُ بلا «+» في هذا المزوّد. */
class Msg91SmsProvider extends AbstractProvider
{
    public function key(): string { return 'msg91'; }

    public function channel(): string { return 'sms'; }

    public function priority(): int { return 40; }

    protected function requiredKeys(): array { return ['auth_key', 'template_id']; }

    public function sendOtp(string $to, string $otp): bool
    {
        $c = $this->config();

        return $this->attempt($to, function () use ($c, $to, $otp) {
            $r = Http::timeout(self::HTTP_TIMEOUT)
                ->withHeaders(['content-type' => 'application/json'])
                ->withBody(json_encode(['OTP' => $otp]), 'application/json')
                ->get('https://api.msg91.com/api/v5/otp', [
                    'template_id' => $c['template_id'],
                    'mobile' => $this->normalize($to, false),
                    'authkey' => $c['auth_key'],
                ]);

            return $r->successful() && ($r->json()['type'] ?? null) === 'success';
        });
    }
}
