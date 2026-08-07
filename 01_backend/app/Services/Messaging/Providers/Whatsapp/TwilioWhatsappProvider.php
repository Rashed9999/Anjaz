<?php

namespace App\Services\Messaging\Providers\Whatsapp;

use App\Services\Messaging\AbstractProvider;
use App\Services\Messaging\Contracts\SupportsFreeText;
use Twilio\Rest\Client as TwilioClient;

/** AMIAL-MESSAGING-001 — Twilio WhatsApp. الرقمُ يُسبَق بـ`whatsapp:`. */
class TwilioWhatsappProvider extends AbstractProvider implements SupportsFreeText
{
    public function key(): string { return 'twilio'; }

    public function channel(): string { return 'whatsapp'; }

    public function priority(): int { return 20; }

    protected function requiredKeys(): array { return ['sid', 'token', 'from']; }

    public function sendOtp(string $to, string $otp): bool
    {
        return $this->deliver($to, $this->template($this->config(), $otp));
    }

    public function sendText(string $to, string $message): bool
    {
        return $this->deliver($to, $message);
    }

    private function deliver(string $to, string $body): bool
    {
        $c = $this->config();

        return $this->attempt($to, function () use ($c, $to, $body) {
            (new TwilioClient($c['sid'], $c['token']))->messages->create(
                'whatsapp:' . $this->normalize($to, true),
                ['from' => 'whatsapp:' . $this->normalize($c['from'], true), 'body' => $body]
            );

            return true;
        });
    }
}
