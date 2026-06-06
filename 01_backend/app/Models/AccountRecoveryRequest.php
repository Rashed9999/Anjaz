<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-RECOVERY-001
 */
class AccountRecoveryRequest extends Model
{
    protected $table = 'account_recovery_requests';

    protected $fillable = [
        'request_ulid',
        'user_id',
        'request_type',
        'old_phone',
        'new_phone',
        'status',
        'identification_documents',
        'user_notes',
        'admin_notes',
        'otp_old_phone',
        'otp_new_phone',
        'otp_expires_at',
        'otp_old_verified',
        'otp_new_verified',
        'risk_score',
        'reviewed_by',
        'reviewed_at',
        'ip_address',
        'user_agent',
        'expires_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'identification_documents' => 'array',
        'otp_expires_at' => 'datetime',
        'otp_old_verified' => 'boolean',
        'otp_new_verified' => 'boolean',
        'risk_score' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** نخفي حقول OTP عن JSON responses */
    protected $hidden = [
        'otp_old_phone',
        'otp_new_phone',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by', 'id');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->whereIn('status', ['pending_otp', 'pending_review']);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', ['pending_otp', 'pending_review', 'approved'])
            ->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isOtpValid(): bool
    {
        return $this->otp_expires_at !== null && $this->otp_expires_at->isFuture();
    }
}
