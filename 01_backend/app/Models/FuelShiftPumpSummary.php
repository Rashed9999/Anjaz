<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelShiftPumpSummary extends Model
{
    protected $table = 'fuel_shift_pump_summaries';

    protected $fillable = [
        'shift_id', 'pump_id',
        'opening_meter', 'closing_meter',
        'expected_liters', 'recorded_liters', 'liters_variance',
        'total_amount', 'sales_count',
    ];

    protected $casts = [
        'shift_id' => 'integer',
        'pump_id' => 'integer',
        'opening_meter' => 'decimal:3',
        'closing_meter' => 'decimal:3',
        'expected_liters' => 'decimal:4',
        'recorded_liters' => 'decimal:4',
        'liters_variance' => 'decimal:4',
        'total_amount' => 'decimal:4',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(FuelShift::class, 'shift_id');
    }

    public function pump(): BelongsTo
    {
        return $this->belongsTo(FuelPump::class, 'pump_id');
    }
}
