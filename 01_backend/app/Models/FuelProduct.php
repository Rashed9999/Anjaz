<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelProduct extends Model
{
    protected $table = 'fuel_products';

    protected $fillable = [
        'station_id', 'name', 'product_code',
        'price_per_liter', 'color_hex', 'is_active',
    ];

    protected $casts = [
        'station_id' => 'integer',
        'price_per_liter' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(FuelStation::class, 'station_id');
    }
}
