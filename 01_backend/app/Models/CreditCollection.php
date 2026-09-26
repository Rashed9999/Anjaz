<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Collection evidence only. Actual debt stays in customer_credit_movements. */
class CreditCollection extends Model
{
    protected $table = 'customer_credit_collections';

    protected $fillable = [
        'collection_ulid', 'merchant_user_id', 'account_id', 'actor_user_id',
        'pos_user_id', 'cashier_shift_id', 'payment_method', 'status', 'amount',
        'idempotency_key', 'payment_request_id', 'paid_transaction_id',
        'credit_movement_id', 'receipt_id', 'sale_movement_ulid', 'note',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer', 'account_id' => 'integer',
        'actor_user_id' => 'integer', 'pos_user_id' => 'integer',
        'cashier_shift_id' => 'integer', 'payment_request_id' => 'integer',
        'credit_movement_id' => 'integer', 'receipt_id' => 'integer',
        'amount' => 'decimal:4',
    ];
}
