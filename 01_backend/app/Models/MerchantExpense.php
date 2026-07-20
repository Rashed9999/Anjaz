<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MerchantExpense extends Model
{
    protected $table = 'merchant_expenses';

    protected $fillable = [
        'merchant_user_id', 'category', 'title', 'amount', 'spent_on',
        'note', 'created_by', 'zone_code',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'spent_on' => 'date',
    ];

    public const CATEGORIES = ['rent', 'salary', 'utilities', 'supplies', 'transport', 'other'];
}
