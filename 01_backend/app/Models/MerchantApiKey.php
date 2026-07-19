<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** AMIAL-API-ACCESS-001 — مفتاح API لتاجر (يُخزَّن هاشه فقط). */
class MerchantApiKey extends Model
{
    protected $fillable = [
        'merchant_user_id', 'label', 'prefix', 'key_hash', 'is_active', 'last_used_at',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];
}
