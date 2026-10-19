<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** رسالة إنشاء قضية تنتظر التسليم إلى موثّق أو تم تسليمها. */
class MuwathaqApplicationSubmission extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';

    protected $fillable = [
        'application_id', 'event_ulid', 'payload', 'status', 'attempts',
        'last_attempted_at', 'delivered_at', 'response_status', 'failure_code',
    ];

    protected $casts = [
        'application_id' => 'integer',
        'payload' => 'array',
        'attempts' => 'integer',
        'response_status' => 'integer',
        'last_attempted_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(MuwathaqAccountOpeningApplication::class, 'application_id');
    }

    public function isDelivered(): bool
    {
        return $this->status === self::STATUS_DELIVERED;
    }
}
