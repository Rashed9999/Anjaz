<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FuelStation extends Model
{
    protected $table = 'fuel_stations';

    protected $fillable = [
        'merchant_user_id', 'station_name', 'license_number',
        'city', 'address', 'latitude', 'longitude',
        'is_active', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'is_active' => 'boolean',
    ];

    public function pumps(): HasMany
    {
        return $this->hasMany(FuelPump::class, 'station_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(FuelProduct::class, 'station_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(FuelSale::class, 'station_id');
    }
}
