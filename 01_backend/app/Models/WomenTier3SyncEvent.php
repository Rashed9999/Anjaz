<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-WOMEN-TIER3-001 — إشعارٌ صادرٌ من أميال باي إلى موثّق بقرارٍ اتُّخذ هنا.
 * ليس طلب مراجعة (قارن بـ MuwathaqReviewRequest) — القرار منتهٍ فعلاً؛ هذا سجلّ التسليم فقط.
 */
class WomenTier3SyncEvent extends Model
{
    protected $table = 'women_tier3_sync_events';

    public const METHOD_VIDEO_CALL = 'video_call';
    public const METHOD_IN_PERSON = 'in_person';
    public const METHODS = [self::METHOD_VIDEO_CALL, self::METHOD_IN_PERSON];

    protected $fillable = [
        'event_ulid', 'user_id', 'decided_by', 'verification_method', 'verification_reference',
        'sync_status', 'attempts', 'last_attempted_at', 'synced_at', 'response_status', 'failure_code',
    ];

    protected $casts = [
        'user_id' => 'integer', 'decided_by' => 'integer', 'attempts' => 'integer',
        'response_status' => 'integer', 'last_attempted_at' => 'datetime', 'synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
