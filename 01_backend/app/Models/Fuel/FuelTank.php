<?php

namespace App\Models\Fuel;

use App\Models\FuelProduct;
use App\Models\FuelStation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * خزّانُ وقود — المورِدُ الذي تُقاس عليه المصالحة.
 *
 * **و`book_liters` كميّةٌ دفتريّةٌ لا حقيقة.** الحقيقةُ آخرُ قياسٍ في
 * `fuel_tank_dips`، والفرقُ بينهما هو ما نبحث عنه. (القاعدة السادسة:
 * الرقم يُحسب من مصدره لا من عمودٍ مخزَّن.)
 */
class FuelTank extends Model
{
    protected $table = 'fuel_tanks';

    protected $fillable = [
        'station_id', 'tank_number', 'name', 'fuel_product_id',
        'capacity_liters', 'book_liters', 'min_alert_liters', 'is_active',
    ];

    protected $casts = [
        'station_id' => 'integer',
        'tank_number' => 'integer',
        'fuel_product_id' => 'integer',
        'capacity_liters' => 'decimal:3',
        'book_liters' => 'decimal:3',
        'min_alert_liters' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function station(): BelongsTo
    {
        return $this->belongsTo(FuelStation::class, 'station_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class, 'fuel_product_id');
    }

    public function nozzles(): HasMany
    {
        return $this->hasMany(FuelNozzle::class, 'tank_id');
    }

    public function dips(): HasMany
    {
        return $this->hasMany(FuelTankDip::class, 'tank_id');
    }

    /** أدنى من حدّ التنبيه — يُقرأ في مركز العمليّات. */
    public function isLow(): bool
    {
        return bccomp((string) $this->book_liters, (string) $this->min_alert_liters, 3) < 0;
    }

    /** نسبةُ الامتلاء — للعرض وحدَه، لا يُبنى عليها قرار. */
    public function fillPercent(): float
    {
        $cap = (float) $this->capacity_liters;

        return $cap > 0 ? round(((float) $this->book_liters / $cap) * 100, 1) : 0.0;
    }
}
