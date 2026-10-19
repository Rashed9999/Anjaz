<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** حالة فتح الحساب التي يملك قرارها نظام «موثّق». */
class MuwathaqAccountOpeningApplication extends Model
{
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_NEEDS_MORE_INFO = 'needs_more_info';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'application_ulid', 'user_id', 'subject_kind', 'verification_path',
        'business_type', 'requested_tier', 'status', 'submitted_at', 'decided_at',
        'decision_reference', 'decision_reason', 'decision_context',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'requested_tier' => 'integer',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'decision_context' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_REJECTED], true);
    }
}
