<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-AGENT-NETWORK-001 (v2.3)
 *
 * تسوية الوكيل — شراء/بيع رصيد رقمي مع الموزّع أو الإدارة.
 */
class AgentSettlement extends Model
{
    protected $table = 'agent_settlements';

    protected $fillable = [
        'settlement_ulid', 'agent_user_id', 'settled_with_id',
        'settlement_type', 'amount', 'commission_amount',
        'status', 'payment_method', 'payment_reference',
        'ledger_entry_ulid', 'approved_by_id', 'completed_at',
        'note', 'zone_code',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'commission_amount' => 'decimal:4',
        'completed_at' => 'datetime',
    ];

    public function agent(): BelongsTo { return $this->belongsTo(User::class, 'agent_user_id'); }

    public function scopePending(Builder $q): Builder { return $q->where('status', 'pending'); }

    public function isCompleted(): bool { return $this->status === 'completed'; }
}
