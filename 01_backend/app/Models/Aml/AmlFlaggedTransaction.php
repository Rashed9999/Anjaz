<?php

namespace App\Models\Aml;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmlFlaggedTransaction extends Model
{
    protected $table = 'aml_flagged_transactions';

    protected $fillable = [
        'flag_ulid',
        'transaction_ulid', 'transaction_type',
        'actor_user_id', 'counterparty_user_id', 'amount',
        'total_risk_score', 'triggered_rules',
        'initial_decision', 'current_status',
        'assigned_to_admin_id', 'reviewed_by_admin_id', 'reviewed_at',
        'review_decision_note',
        'transaction_executed', 'executed_at',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'total_risk_score' => 'decimal:2',
        'triggered_rules' => 'array',
        'metadata' => 'array',
        'reviewed_at' => 'datetime',
        'executed_at' => 'datetime',
        'transaction_executed' => 'boolean',
    ];

    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
    public function counterparty(): BelongsTo { return $this->belongsTo(User::class, 'counterparty_user_id'); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_admin_id'); }

    public function scopePending(Builder $q): Builder { return $q->where('current_status', 'pending_review'); }
    public function scopeNeedsReview(Builder $q): Builder { return $q->whereIn('current_status', ['pending_review', 'escalated']); }

    public function isPending(): bool { return $this->current_status === 'pending_review'; }
}
