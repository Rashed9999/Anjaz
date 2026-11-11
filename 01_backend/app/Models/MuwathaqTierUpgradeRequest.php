<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** طلب ترقية فئة يقرره مراجعو موثّق؛ تطبيق القرار على الحساب يبقى في أميال باي. */
class MuwathaqTierUpgradeRequest extends Model
{
    public const OPEN = ['submitted', 'under_review', 'needs_more_info'];
    public const FINAL = ['approved', 'rejected', 'superseded'];

    protected $fillable = [
        'request_ulid', 'user_id', 'target_tier', 'status', 'open_key', 'document_ids', 'event_ulid', 'payload',
        'delivery_status', 'attempts', 'last_attempted_at', 'delivered_at', 'response_status', 'failure_code',
        'last_decision_seq', 'decision_reference', 'decision_reason', 'decision_context', 'apply_code', 'decided_at', 'applied_at',
    ];

    protected $casts = [
        'user_id' => 'integer', 'target_tier' => 'integer', 'document_ids' => 'array', 'payload' => 'array',
        'decision_context' => 'array', 'attempts' => 'integer', 'last_decision_seq' => 'integer', 'response_status' => 'integer',
        'last_attempted_at' => 'datetime', 'delivered_at' => 'datetime', 'decided_at' => 'datetime', 'applied_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** هل يملك موثّق قرار هذا المستخدم الآن؟ (يُستخدم لمنع قرار مزدوج من المسار القديم) */
    public static function ownsOpenCaseFor(int $userId): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('muwathaq_tier_upgrade_requests')
            && static::query()->where('user_id', $userId)->whereIn('status', self::OPEN)->exists();
    }
}
