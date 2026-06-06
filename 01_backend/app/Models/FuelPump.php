<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FuelPump extends Model
{
    protected $table = 'fuel_pumps';

    protected $fillable = [
        'station_id', 'pump_number', 'pump_name', 'pump_type',
        'current_meter_reading', 'is_active',
    ];

    protected $casts = [
        'station_id' => 'integer',
        'pump_number' => 'integer',
        'current_meter_reading' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public const TYPES = ['mechanical', 'electronic'];

    public function station(): BelongsTo
    {
        return $this->belongsTo(FuelStation::class, 'station_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            FuelProduct::class, 'fuel_pump_products',
            'pump_id', 'fuel_product_id',
        )->withPivot('nozzle_number');
    }

    public function isMechanical(): bool
    {
        return $this->pump_type === 'mechanical';
    }
}
