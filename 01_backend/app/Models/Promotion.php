<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    protected $table = 'promotions';

    protected $fillable = [
        'merchant_user_id', 'name', 'type', 'value', 'code',
        'min_order_amount', 'max_discount_amount', 'is_active',
        'starts_at', 'ends_at', 'usage_limit', 'used_count', 'zone_code',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'min_order_amount' => 'decimal:2',
        'max_discount_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'usage_limit' => 'integer',
        'used_count' => 'integer',
    ];
}
