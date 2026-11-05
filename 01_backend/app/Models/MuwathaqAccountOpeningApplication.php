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
    public const STATUS_AWAITING_ADMIN_APPROVAL = 'awaiting_admin_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'application_ulid', 'user_id', 'origin', 'subject_kind', 'verification_path',
        'business_type', 'requested_tier', 'status', 'opening_access_token_hash', 'opening_access_expires_at', 'evidence_completed_at', 'submitted_at', 'decided_at',
        'decision_reference', 'decision_reason', 'decision_context',
        'muwathaq_decided_at', 'admin_decided_by', 'admin_decided_at', 'admin_decision_reason',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'origin' => 'string',
        'requested_tier' => 'integer',
        'opening_access_expires_at' => 'datetime',
        'evidence_completed_at' => 'datetime',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'muwathaq_decided_at' => 'datetime',
        'admin_decided_by' => 'integer',
        'admin_decided_at' => 'datetime',
        'decision_context' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [
            self::STATUS_AWAITING_ADMIN_APPROVAL,
            self::STATUS_APPROVED,
            self::STATUS_REJECTED,
        ], true);
    }

    public function evidences(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MuwathaqOpeningEvidence::class, 'application_id');
    }
}
