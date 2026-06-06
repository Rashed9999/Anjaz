<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-SCALE-FEES-001 — قيد رسم منفرد (append-only).
 */
class PlatformFeeEntry extends Model
{
    protected $table = 'platform_fee_entries';

    protected $fillable = [
        'admin_user_id', 'amount', 'source_type', 'transaction_id',
        'from_user_id', 'zone_code', 'reconciled', 'reconciled_at',
    ];

    protected $casts = [
        'admin_user_id' => 'integer',
        'reconciled' => 'boolean',
        'reconciled_at' => 'datetime',
    ];
}
