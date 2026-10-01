<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantExpense extends Model
{
    protected $table = 'merchant_expenses';

    protected $fillable = [
        'expense_ulid', 'merchant_user_id', 'category', 'title', 'amount',
        'payment_source', 'cashier_shift_id', 'status', 'spent_on',
        'note', 'voided_at', 'void_reason', 'created_by', 'zone_code',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'cashier_shift_id' => 'integer',
        'spent_on' => 'date',
        'voided_at' => 'datetime',
    ];

    public const CATEGORIES = ['rent', 'salary', 'utilities', 'supplies', 'transport', 'other'];
}
