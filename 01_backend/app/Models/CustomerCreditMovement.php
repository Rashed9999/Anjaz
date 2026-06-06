<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-CUSTOMER-CREDIT-001 — قيد على حساب عميل ائتماني (append-only).
 */
class CustomerCreditMovement extends Model
{
    protected $table = 'customer_credit_movements';

    protected $fillable = [
        'movement_ulid', 'account_id', 'type', 'amount', 'balance_after',
        'due_date', 'reference_type', 'reference_id', 'reference_number',
        'note', 'created_by_user_id', 'zone_code',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'due_date' => 'date',
        'created_by_user_id' => 'integer',
    ];

    public const TYPES = ['sale', 'payment', 'return', 'adjustment'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditAccount::class, 'account_id');
    }
}
