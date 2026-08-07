<?php

namespace App\Services\Messaging\Providers\Whatsapp;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** AMIAL-MESSAGING-001 — Meta WhatsApp Cloud API (الرسميّ والأرخص غالباً). */
class MetaCloudProvider extends MetaStyleProvider
{
    public function key(): string { return 'meta_cloud'; }

    public function channel(): string { return 'whatsapp'; }

    public function priority(): int { return 10; }

    protected function requiredKeys(): array { return ['access_token', 'phone_number_id']; }

    protected function transport(array $c, array $payload): Response
    {
        return Http::withToken($c['access_token'])->timeout(self::HTTP_TIMEOUT)
            ->post("https://graph.facebook.com/v19.0/{$c['phone_number_id']}/messages",
                ['messaging_product' => 'whatsapp'] + $payload);
    }
}
