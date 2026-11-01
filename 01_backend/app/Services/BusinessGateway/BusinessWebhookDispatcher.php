<?php

namespace App\Services\BusinessGateway;

use App\Models\BusinessGateway\BusinessOutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AMIAL-BUSINESS-GATEWAY-001 — يسلّم أحداث الـ outbox إلى أميال أعمال.
 *
 * التسليم «مرةً على الأقل»: قد يصل الحدث مرتين (انقطاعٌ بعد استلامه وقبل
 * ردّه)، وأميال أعمال تُسقط المكرر بـ event_id. لا يُحذف حدث: ما استنفد
 * المحاولات يصير dead ويبقى للمراجعة وإعادة الإرسال.
 */
class BusinessWebhookDispatcher
{
    private const CLAIM_SECONDS = 120;

    /** @return array{delivered:int, failed:int, dead:int, skipped:string|null} */
    public function dispatchDue(int $limit = 50): array
    {
        $result = ['delivered' => 0, 'failed' => 0, 'dead' => 0, 'skipped' => null];

        $url = trim((string) config('amial_business.webhook_url'));
        $secret = trim((string) config('amial_business.webhook_secret'));
        if ($url === '' || $secret === '') {
            // الأحداث تبقى معلّقة ولا تضيع؛ تُسلَّم حين تُضبط القناة.
            $result['skipped'] = 'BUSINESS_WEBHOOK_NOT_CONFIGURED';

            return $result;
        }

        foreach ($this->claim($limit) as $event) {
            $outcome = $this->deliver($event, $url, $secret);
            $result[$outcome]++;
        }

        return $result;
    }

    /** يحجز دفعةً بقفل، فلا يرسل مُشغِّلان متوازيان الحدث نفسه في اللحظة نفسها. */
    private function claim(int $limit): array
    {
        return DB::transaction(function () use ($limit) {
            $events = BusinessOutboxEvent::where('status', BusinessOutboxEvent::STATUS_PENDING)
                ->where('next_attempt_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('claimed_until')->orWhere('claimed_until', '<', now()))
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($events as $event) {
                $event->update(['claimed_until' => now()->addSeconds(self::CLAIM_SECONDS)]);
            }

            return $events->all();
        });
    }

    private function deliver(BusinessOutboxEvent $event, string $url, string $secret): string
    {
        $body = json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();

        try {
            $response = Http::timeout(max(1, (int) config('amial_business.webhook_timeout_seconds', 10)))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Amial-Pay-Event-Id' => $event->event_ulid,
                    'X-Amial-Pay-Event-Type' => $event->event_type,
                    'X-Amial-Pay-Timestamp' => $timestamp,
                    'X-Amial-Pay-Signature' => BusinessGatewaySignature::outbound($timestamp, $body, $secret),
                ])
                ->withBody($body, 'application/json')
                ->post($url);

            if ($response->successful()) {
                $event->update([
                    'status' => BusinessOutboxEvent::STATUS_DELIVERED,
                    'delivered_at' => now(),
                    'attempts' => $event->attempts + 1,
                    'claimed_until' => null,
                    'last_error' => null,
                ]);

                return 'delivered';
            }

            return $this->fail($event, 'HTTP '.$response->status());
        } catch (Throwable $e) {
            return $this->fail($event, mb_substr($e::class.': '.$e->getMessage(), 0, 1000));
        }
    }

    private function fail(BusinessOutboxEvent $event, string $error): string
    {
        $backoff = array_values((array) config('amial_business.webhook_backoff_seconds', []));
        $attempts = $event->attempts + 1;

        if ($attempts > count($backoff)) {
            $event->update([
                'status' => BusinessOutboxEvent::STATUS_DEAD,
                'attempts' => $attempts,
                'claimed_until' => null,
                'last_error' => $error,
            ]);
            Log::error('AMIAL-BUSINESS-GATEWAY: event delivery exhausted', [
                'event_id' => $event->event_ulid,
                'event_type' => $event->event_type,
                'error' => $error,
            ]);

            return 'dead';
        }

        $event->update([
            'attempts' => $attempts,
            'next_attempt_at' => now()->addSeconds((int) $backoff[$attempts - 1]),
            'claimed_until' => null,
            'last_error' => $error,
        ]);

        return 'failed';
    }
}
