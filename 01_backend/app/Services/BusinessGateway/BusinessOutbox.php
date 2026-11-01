<?php

namespace App\Services\BusinessGateway;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\BusinessGateway\BusinessOutboxEvent;
use Illuminate\Support\Str;

/**
 * يكتب حدثاً للتسليم لاحقاً. يُستدعى داخل معاملة السبب، ولا يرسل شيئاً
 * بنفسه؛ التسليم مهمة BusinessWebhookDispatcher.
 */
class BusinessOutbox
{
    public function record(?BusinessLink $link, string $eventType, array $data): BusinessOutboxEvent
    {
        $eventUlid = (string) Str::ulid();

        return BusinessOutboxEvent::create([
            'event_ulid' => $eventUlid,
            'business_link_id' => $link?->id,
            'event_type' => $eventType,
            'payload' => [
                'event_id' => $eventUlid,
                'event_type' => $eventType,
                'event_version' => 1,
                'occurred_at' => now()->toIso8601String(),
                'link_id' => $link?->link_ulid,
                'data' => $data,
            ],
            'status' => BusinessOutboxEvent::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt_at' => now(),
        ]);
    }
}
