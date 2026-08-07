<?php

namespace App\Services\Messaging\Providers\Sms;

use App\Services\Messaging\AbstractProvider;
use Twilio\Rest\Client as TwilioClient;

/** AMIAL-MESSAGING-001 — Twilio SMS. النداءُ منقولٌ حرفيّاً من `SmsModule::twilio`. */
class TwilioSmsProvider extends AbstractProvider
{
    public function key(): string { return 'twilio'; }

    public function channel(): string { return 'sms'; }

    public function priority(): int { return 10; }

    protected function requiredKeys(): array { return ['sid', 'token']; }

    public function sendOtp(string $to, string $otp): bool
    {
        $c = $this->config();

        return $this->attempt($to, function () use ($c, $to, $otp) {
            (new TwilioClient($c['sid'], $c['token']))->messages->create($to, [
                'messagingServiceSid' => $c['messaging_service_sid'] ?? '',
                'body' => $this->template($c, $otp, '#OTP#'),
            ]);

            return true;
        });
    }
}
