<?php

namespace App\Services\Messaging\Providers\Sms;

use App\Services\Messaging\AbstractProvider;
use Illuminate\Support\Facades\Http;

/** AMIAL-MESSAGING-001 — Nexmo/Vonage. `messages[0].status === '0'` يعني نجاحاً. */
class NexmoSmsProvider extends AbstractProvider
{
    public function key(): string { return 'nexmo'; }

    public function channel(): string { return 'sms'; }

    public function priority(): int { return 20; }

    protected function requiredKeys(): array { return ['api_key', 'api_secret']; }

    public function sendOtp(string $to, string $otp): bool
    {
        $c = $this->config();

        return $this->attempt($to, function () use ($c, $to, $otp) {
            $r = Http::timeout(self::HTTP_TIMEOUT)->asForm()
                ->post('https://rest.nexmo.com/sms/json', [
                    'from' => $c['from'] ?? '',
                    'text' => $this->template($c, $otp, '#OTP#'),
                    'to' => $to,
                    'api_key' => $c['api_key'],
                    'api_secret' => $c['api_secret'],
                ]);

            $status = $r->json()['messages'][0]['status'] ?? null;

            return $r->successful() && ($status === '0' || $status === 0);
        });
    }
}
