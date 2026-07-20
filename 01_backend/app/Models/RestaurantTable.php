<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantTable extends Model
{
    protected $table = 'restaurant_tables';

    protected $fillable = [
        'merchant_user_id', 'label', 'seats', 'status', 'is_active', 'zone_code',
    ];

    protected $casts = [
        'seats' => 'integer',
        'is_active' => 'boolean',
    ];
}
