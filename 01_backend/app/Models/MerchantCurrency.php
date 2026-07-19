<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-MULTI-CURRENCY-001 — عملة تاجر بسعر صرف مقابل الأساس (ر.ي).
 */
class MerchantCurrency extends Model
{
    protected $fillable = [
        'merchant_user_id', 'code', 'name', 'symbol', 'rate_to_base', 'is_active',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'rate_to_base' => 'decimal:6',
        'is_active' => 'boolean',
    ];
}
