<?php

namespace App\Models\Fuel;

use App\Models\FuelProduct;
use App\Models\FuelPump;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * المسدس — **وهو حاملُ العدّاد، لا المضخّة**.
 *
 * مضخّةٌ بمسدسَي بنزينٍ وديزل لها عدّادٌ واحدٌ في التصميم القديم، فلا
 * يُعرف كم لتراً خرج من أيّ نوع — ولا تُنسب المبيعات إلى خزّاناتها،
 * فتستحيل المصالحة.
 */
class FuelNozzle extends Model
{
    protected $table = 'fuel_nozzles';

    protected $fillable = [
        'pump_id', 'nozzle_number', 'fuel_product_id', 'tank_id',
        'current_meter_reading', 'is_active',
    ];

    protected $casts = [
        'pump_id' => 'integer',
        'nozzle_number' => 'integer',
        'fuel_product_id' => 'integer',
        'tank_id' => 'integer',
        'current_meter_reading' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function pump(): BelongsTo
    {
        return $this->belongsTo(FuelPump::class, 'pump_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class, 'fuel_product_id');
    }

    public function tank(): BelongsTo
    {
        return $this->belongsTo(FuelTank::class, 'tank_id');
    }

    /** مبيعاتُ هذا المسدس — يمنع وجودُها حذفَه. */
    public function sales(): HasMany
    {
        return $this->hasMany(\App\Models\FuelSale::class, 'nozzle_id');
    }
}
