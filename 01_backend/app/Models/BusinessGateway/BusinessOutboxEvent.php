<?php

namespace App\Models\BusinessGateway;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-BUSINESS-GATEWAY-001 — حدثٌ ينتظر التسليم إلى أميال أعمال.
 *
 * يُكتب داخل معاملة سببه نفسها: إن رُدّت المعاملة رُدّ الحدث معها، فلا
 * يُبلَّغ عن دفعٍ لم يقع، ولا يُفقد إبلاغ دفعٍ وقع.
 */
class BusinessOutboxEvent extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_DEAD = 'dead';

    protected $table = 'business_outbox_events';

    protected $fillable = [
        'event_ulid', 'business_link_id', 'event_type', 'payload', 'status',
        'attempts', 'next_attempt_at', 'claimed_until', 'last_error', 'delivered_at',
    ];

    protected $casts = [
        'business_link_id' => 'integer',
        'payload' => 'array',
        'attempts' => 'integer',
        'next_attempt_at' => 'datetime',
        'claimed_until' => 'datetime',
        'delivered_at' => 'datetime',
    ];
}
