<?php

namespace App\Services\Messaging\Providers\Sms;

use App\Services\Messaging\AbstractProvider;
use Illuminate\Support\Facades\Http;

/** AMIAL-MESSAGING-001 — 2Factor.in. */
class TwoFactorSmsProvider extends AbstractProvider
{
    public function key(): string { return '2factor'; }

    public function channel(): string { return 'sms'; }

    public function priority(): int { return 30; }

    protected function requiredKeys(): array { return ['api_key']; }

    public function sendOtp(string $to, string $otp): bool
    {
        $c = $this->config();

        return $this->attempt($to, function () use ($c, $to, $otp) {
            $r = Http::timeout(self::HTTP_TIMEOUT)
                ->get("https://2factor.in/API/V1/{$c['api_key']}/SMS/{$to}/{$otp}");

            return $r->successful() && ($r->json()['Status'] ?? null) === 'Success';
        });
    }
}
