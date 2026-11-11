<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** طلب مراجعة يقرره مراجعو موثّق (ترقية فئة أو إثبات سكن)؛ تطبيق القرار يبقى في أميال باي. */
class MuwathaqReviewRequest extends Model
{
    public const KIND_TIER_UPGRADE = 'tier_upgrade';
    public const KIND_RESIDENCE = 'residence_review';

    public const OPEN = ['submitted', 'under_review', 'needs_more_info'];
    public const FINAL = ['approved', 'rejected', 'superseded'];

    protected $fillable = [
        'request_ulid', 'kind', 'subject_id', 'user_id', 'target_tier', 'status', 'open_key', 'document_ids', 'event_ulid', 'payload',
        'delivery_status', 'attempts', 'last_attempted_at', 'delivered_at', 'response_status', 'failure_code',
        'last_decision_seq', 'decision_reference', 'decision_reason', 'decision_context', 'apply_code', 'decided_at', 'applied_at',
    ];

    protected $casts = [
        'user_id' => 'integer', 'subject_id' => 'integer', 'target_tier' => 'integer', 'document_ids' => 'array', 'payload' => 'array',
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

    /** هل يملك موثّق قرار ترقية هذا المستخدم الآن؟ (يمنع قراراً مزدوجاً من المسار القديم) */
    public static function ownsOpenCaseFor(int $userId): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('muwathaq_review_requests')
            && static::query()->where('kind', self::KIND_TIER_UPGRADE)->where('user_id', $userId)->whereIn('status', self::OPEN)->exists();
    }

    /** هل يملك موثّق مراجعة طلب السكن هذا؟ */
    public static function ownsResidenceVerification(int $verificationId): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('muwathaq_review_requests')
            && static::query()->where('kind', self::KIND_RESIDENCE)->where('subject_id', $verificationId)->whereIn('status', self::OPEN)->exists();
    }
}
