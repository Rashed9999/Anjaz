<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-WA-001 — HTTP Client لـ Meta WhatsApp Cloud API.
 *
 * يُرسِل ردود النص والأزرار والوسائط عبر API المعتمد من Meta.
 * يدعم مزوّد بديل (Twilio WA / 360Dialog) إن لم يتوفّر Meta.
 */
class WhatsappApiClient
{
    private const META_API = 'https://graph.facebook.com/v19.0';
    private const TIMEOUT  = 10;

    /** يُرسِل رسالة نصّية عادية. */
    public function sendText(string $to, string $body): bool
    {
        $to = $this->normalizePhone($to);

        $provider = $this->activeProvider();

        return match ($provider) {
            'meta'     => $this->metaSendText($to, $body),
            'twilio'   => $this->twilioSendText($to, $body),
            '360dialog'=> $this->dialogSendText($to, $body),
            default    => $this->logOnly($to, $body),
        };
    }

    /** يُرسِل رسالة مع أزرار (Interactive Buttons). */
    public function sendButtons(
        string $to,
        string $bodyText,
        array  $buttons  // [['id' => 'btn_1', 'title' => 'رصيدي'], ...]
    ): bool {
        $to = $this->normalizePhone($to);

        if ($this->activeProvider() !== 'meta') {
            // Fallback: رسالة نصّية عادية
            $text  = $bodyText . "\n\n";
            foreach ($buttons as $i => $btn) {
                $text .= ($i + 1) . '. ' . $btn['title'] . "\n";
            }
            return $this->sendText($to, $text);
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => [
                'type' => 'button',
                'body' => ['text' => $bodyText],
                'action' => [
                    'buttons' => array_map(fn($b) => [
                        'type'  => 'reply',
                        'reply' => ['id' => $b['id'], 'title' => mb_substr($b['title'], 0, 20)],
                    ], array_slice($buttons, 0, 3)), // Meta: max 3 أزرار
                ],
            ],
        ];

        return $this->metaPost($payload);
    }

    // ============ Private Providers ============

    private function metaSendText(string $to, string $body): bool
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => [
                'preview_url' => false,
                'body'        => $body,
            ],
        ];
        return $this->metaPost($payload);
    }

    private function metaPost(array $payload): bool
    {
        try {
            $phoneId = config('services.whatsapp.phone_number_id', '');
            $token   = config('services.whatsapp.access_token', '');
            if (!$phoneId || !$token) {
                Log::warning('[WA] Meta credentials missing');
                return false;
            }

            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->post(self::META_API . "/{$phoneId}/messages", $payload);

            if ($response->successful()) {
                Log::info('[WA] Message sent', [
                    'to' => $this->maskLog($payload['to'] ?? ''),
                ]);
                return true;
            }

            Log::warning('[WA] Meta API error', [
                'status' => $response->status(),
                'body'   => mb_substr($response->body(), 0, 300),
            ]);
            return false;

        } catch (\Throwable $e) {
            Log::error('[WA] Meta send exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function twilioSendText(string $to, string $body): bool
    {
        try {
            $sid   = (string) config('amial.whatsapp.twilio_sid', '');
            $token = (string) config('amial.whatsapp.twilio_token', '');
            $from  = (string) config('amial.whatsapp.twilio_from', '');

            if (!$sid || !$token || !$from) return false;

            $client = new \Twilio\Rest\Client($sid, $token);
            $client->messages->create("whatsapp:{$to}", [
                'from' => str_starts_with($from, 'whatsapp:') ? $from : "whatsapp:{$from}",
                'body' => $body,
            ]);
            return true;

        } catch (\Throwable $e) {
            Log::error('[WA] Twilio send exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function dialogSendText(string $to, string $body): bool
    {
        try {
            $apiKey  = (string) config('amial.whatsapp.dialog360_key', '');
            $baseUrl = (string) config('amial.whatsapp.dialog360_base', 'https://waba-v2.360dialog.io');
            if (!$apiKey) return false;

            $response = Http::withHeaders(['D360-API-KEY' => $apiKey])
                ->timeout(self::TIMEOUT)
                ->post("{$baseUrl}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to'   => ltrim($to, '+'),
                    'type' => 'text',
                    'text' => ['body' => $body],
                ]);

            return $response->successful();

        } catch (\Throwable $e) {
            Log::error('[WA] 360Dialog send exception', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** Fallback: فقط يسجّل في Log (لو لا يوجد provider). */
    private function logOnly(string $to, string $body): bool
    {
        Log::info('[WA] No provider configured — would send', [
            'to'           => $this->maskLog($to),
            'body_preview' => mb_substr($body, 0, 100),
        ]);
        return false;
    }

    // ============ Helpers ============

    private function activeProvider(): string
    {
        if (!empty(config('services.whatsapp.phone_number_id')) && !empty(config('services.whatsapp.access_token'))) {
            return 'meta';
        }
        if (!empty(env('TWILIO_WA_FROM'))) {
            return 'twilio';
        }
        if (!empty(config('amial.whatsapp.dialog360_key'))) {
            return '360dialog';
        }
        return 'none';
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);
        if (str_starts_with($phone, '00')) {
            return substr($phone, 2);
        }
        if (str_starts_with($phone, '+')) {
            return substr($phone, 1);
        }
        return $phone;
    }

    private function maskLog(string $phone): string
    {
        return mb_strlen($phone) > 6
            ? mb_substr($phone, 0, 4) . '****' . mb_substr($phone, -2)
            : '****';
    }
}
