<?php

namespace App\Services\Messaging\Providers\Whatsapp;

use App\Services\Messaging\AbstractProvider;
use App\Services\Messaging\Contracts\SupportsFreeText;
use Illuminate\Support\Facades\Http;

/** AMIAL-MESSAGING-001 — UltraMsg (الأبسط والأرخص — نصٌّ حرٌّ بلا قوالب). */
class UltraMsgProvider extends AbstractProvider implements SupportsFreeText
{
    public function key(): string { return 'ultramsg'; }

    public function channel(): string { return 'whatsapp'; }

    public function priority(): int { return 50; }

    protected function requiredKeys(): array { return ['instance_id', 'token']; }

    public function sendOtp(string $to, string $otp): bool
    {
        return $this->chat($to, $this->template($this->config(), $otp));
    }

    public function sendText(string $to, string $message): bool
    {
        return $this->chat($to, $message);
    }

    private function chat(string $to, string $body): bool
    {
        $c = $this->config();

        return $this->attempt($to, function () use ($c, $to, $body) {
            $r = Http::asForm()->timeout(self::HTTP_TIMEOUT)
                ->post("https://api.ultramsg.com/{$c['instance_id']}/messages/chat", [
                    'token' => $c['token'],
                    'to' => $this->normalize($to, true),
                    'body' => $body,
                ]);

            $sent = $r->json()['sent'] ?? null;

            return $r->successful() && ($sent === 'true' || $sent === true);
        });
    }
}
