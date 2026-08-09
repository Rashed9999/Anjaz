<?php

namespace App\Models\Fuel;

use App\Models\FuelStation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * مصالحةُ المخزون الرطب — نتيجةُ المعادلة، لا مصدرُها.
 *
 *   Opening + Deliveries − Sales = Expected
 *   Expected − Actual Dip        = Variance
 *
 * **والنتيجةُ تحقيقٌ لا اتّهام.** فقدُ تسعين لتراً قد يكون قراءةً خاطئة،
 * أو توريداً غير مرحَّل، أو عدّاداً معطوباً، أو تسرُّباً — والنظامُ يفتح
 * ملفّاً ولا يحكم.
 */
class FuelStockReconciliation extends Model
{
    protected $table = 'fuel_stock_reconciliations';

    protected $fillable = [
        'recon_ulid', 'station_id', 'tank_id', 'shift_id',
        'period_start', 'period_end',
        'opening_liters', 'delivered_liters', 'sold_liters',
        'expected_closing_liters', 'actual_closing_liters',
        'variance_liters', 'variance_percent',
        'status', 'investigation_note', 'resolved_by_user_id', 'resolved_at',
        'created_by_user_id', 'zone_code',
    ];

    protected $casts = [
        'station_id' => 'integer',
        'tank_id' => 'integer',
        'shift_id' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'opening_liters' => 'decimal:3',
        'delivered_liters' => 'decimal:3',
        'sold_liters' => 'decimal:3',
        'expected_closing_liters' => 'decimal:3',
        'actual_closing_liters' => 'decimal:3',
        'variance_liters' => 'decimal:3',
        'variance_percent' => 'decimal:4',
        'resolved_at' => 'datetime',
    ];

    public const STATUSES = ['within_tolerance', 'investigating', 'resolved', 'written_off'];

    public function tank(): BelongsTo
    {
        return $this->belongsTo(FuelTank::class, 'tank_id');
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(FuelStation::class, 'station_id');
    }

    public function isLoss(): bool
    {
        return bccomp((string) $this->variance_liters, '0', 3) < 0;
    }
}
