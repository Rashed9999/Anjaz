<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantExpenseReversal extends Model
{
    protected $fillable = [
        'reversal_ulid', 'merchant_user_id', 'expense_id', 'amount',
        'effective_on', 'reason', 'created_by',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'expense_id' => 'integer',
        'amount' => 'decimal:4',
        'effective_on' => 'date',
        'created_by' => 'integer',
    ];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(MerchantExpense::class, 'expense_id');
    }
}
