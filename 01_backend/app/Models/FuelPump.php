<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

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

    /**
     * **المسدساتُ صارت كياناً**، والجدولُ الوسيط `fuel_pump_products`
     * أُلغي: كان يحمل رقمَ الفوّهة بلا عدّادٍ ولا خزّان، فلا تُنسب اللترات
     * إلى مصدرها. (AMIAL-FUEL-VERTICAL-001 · المرحلة ١)
     */
    public function nozzles(): HasMany
    {
        return $this->hasMany(\App\Models\Fuel\FuelNozzle::class, 'pump_id');
    }

    /** أنواعُ الوقود المتاحة على هذه المضخّة — تُشتقّ من مسدساتها. */
    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(
            FuelProduct::class,
            \App\Models\Fuel\FuelNozzle::class,
            'pump_id', 'id', 'id', 'fuel_product_id',
        );
    }

    public function isMechanical(): bool
    {
        return $this->pump_type === 'mechanical';
    }
}
