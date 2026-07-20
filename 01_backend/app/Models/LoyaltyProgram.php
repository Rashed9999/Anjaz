<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyProgram extends Model
{
    protected $table = 'loyalty_programs';

    protected $fillable = [
        'merchant_user_id', 'is_active', 'earn_points_per_100',
        'redeem_value_per_point', 'min_redeem_points', 'zone_code',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'earn_points_per_100' => 'decimal:2',
        'redeem_value_per_point' => 'decimal:4',
        'min_redeem_points' => 'integer',
    ];
}
