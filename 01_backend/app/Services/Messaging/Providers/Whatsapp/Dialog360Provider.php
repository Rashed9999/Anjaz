<?php

namespace App\Services\Messaging\Providers\Whatsapp;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** AMIAL-MESSAGING-001 — 360dialog: وسيطٌ لواجهة Meta نفسِها. */
class Dialog360Provider extends MetaStyleProvider
{
    public function key(): string { return '360dialog'; }

    public function channel(): string { return 'whatsapp'; }

    public function priority(): int { return 30; }

    protected function requiredKeys(): array { return ['api_key']; }

    protected function transport(array $c, array $payload): Response
    {
        return Http::withHeaders(['D360-API-KEY' => $c['api_key']])->timeout(self::HTTP_TIMEOUT)
            ->post('https://waba-v2.360dialog.io/messages',
                ['messaging_product' => 'whatsapp'] + $payload);
    }
}
