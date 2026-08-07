<?php

namespace App\Services\Messaging\Providers\Whatsapp;

use App\Services\Messaging\AbstractProvider;
use App\Services\Messaging\Contracts\SupportsFreeText;
use Illuminate\Support\Facades\Http;

/** AMIAL-MESSAGING-001 — WATI. */
class WatiProvider extends AbstractProvider implements SupportsFreeText
{
    public function key(): string { return 'wati'; }

    public function channel(): string { return 'whatsapp'; }

    public function priority(): int { return 40; }

    protected function requiredKeys(): array { return ['api_endpoint', 'access_token']; }

    public function sendOtp(string $to, string $otp): bool
    {
        $c = $this->config();

        return $this->attempt($to, function () use ($c, $to, $otp) {
            $n = $this->normalize($to, false);

            $r = Http::withToken($c['access_token'])->timeout(self::HTTP_TIMEOUT)
                ->post($this->base($c) . "/api/v1/sendTemplateMessage?whatsappNumber={$n}", [
                    'template_name' => $c['template_name'] ?? 'otp_code',
                    'broadcast_name' => $c['broadcast_name'] ?? 'otp',
                    'parameters' => [['name' => '1', 'value' => $otp]],
                ]);

            $result = $r->json()['result'] ?? null;

            return $r->successful() && ($result === true || $result === 'true');
        });
    }

    public function sendText(string $to, string $message): bool
    {
        $c = $this->config();

        return $this->attempt($to, function () use ($c, $to, $message) {
            $n = $this->normalize($to, false);

            return Http::withToken($c['access_token'])->timeout(self::HTTP_TIMEOUT)
                ->post($this->base($c) . "/api/v1/sendSessionMessage/{$n}?messageText=" . rawurlencode($message))
                ->successful();
        });
    }

    private function base(array $c): string
    {
        return rtrim($c['api_endpoint'], '/');
    }
}
