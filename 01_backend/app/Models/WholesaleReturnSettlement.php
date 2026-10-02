<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleReturnSettlement extends Model
{
    protected $table = 'wholesale_return_settlements';

    protected $fillable = [
        'settlement_ulid', 'return_id', 'business_id', 'merchant_user_id',
        'customer_id', 'paid_by_user_id', 'cashier_shift_id', 'method',
        'amount', 'customer_user_id', 'ledger_entry_ulid', 'reference',
        'idempotency_key', 'note',
    ];

    protected $casts = [
        'return_id' => 'integer',
        'business_id' => 'integer',
        'merchant_user_id' => 'integer',
        'customer_id' => 'integer',
        'paid_by_user_id' => 'integer',
        'cashier_shift_id' => 'integer',
        'customer_user_id' => 'integer',
        'amount' => 'decimal:4',
    ];

    public const METHODS = ['cash', 'amial_pay'];

    public function return(): BelongsTo
    {
        return $this->belongsTo(WholesaleReturn::class, 'return_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(WholesaleCustomer::class, 'customer_id');
    }

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }
}
