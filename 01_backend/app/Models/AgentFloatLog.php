<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-AGENT-NETWORK-001 (v2.3)
 *
 * سجل سيولة الوكيل اليومي.
 */
class AgentFloatLog extends Model
{
    protected $table = 'agent_float_logs';

    protected $fillable = [
        'agent_user_id', 'log_date',
        'opening_float', 'cash_in_total', 'cash_out_total',
        'topup_total', 'commission_earned', 'closing_float',
        'transaction_count',
    ];

    protected $casts = [
        'log_date' => 'date',
        'opening_float' => 'decimal:4',
        'cash_in_total' => 'decimal:4',
        'cash_out_total' => 'decimal:4',
        'topup_total' => 'decimal:4',
        'commission_earned' => 'decimal:4',
        'closing_float' => 'decimal:4',
        'transaction_count' => 'integer',
    ];

    public function agent(): BelongsTo { return $this->belongsTo(User::class, 'agent_user_id'); }
}
