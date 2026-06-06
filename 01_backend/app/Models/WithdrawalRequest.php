<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-CUSTOMER-WITHDRAW-001 — طلب سحب مبدوء من العميل.
 */
class WithdrawalRequest extends Model
{
    protected $table = 'withdrawal_requests';

    protected $fillable = [
        'op_code', 'customer_user_id', 'agent_user_id',
        'amount', 'fee', 'agent_commission', 'platform_profit', 'total_debit',
        'fee_scheme_id', 'fee_scheme_version',
        'status', 'transaction_id', 'expires_at', 'completed_at', 'zone_code',
    ];

    protected $casts = [
        'customer_user_id' => 'integer',
        'agent_user_id' => 'integer',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public const STATUSES = ['pending', 'completed', 'cancelled', 'expired'];

    public function isLive(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }
}
