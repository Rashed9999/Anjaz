<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FuelShift extends Model
{
    protected $table = 'fuel_shifts';

    protected $fillable = [
        'shift_ulid', 'station_id',
        'opened_by_user_id', 'closed_by_user_id',
        'opened_at', 'closed_at',
        'opening_cash', 'expected_cash', 'actual_cash', 'variance',
        'total_cash_sales', 'total_amial_pay_sales', 'total_company_sales',
        'total_liters', 'total_sales_count',
        'status', 'variance_reason', 'opening_notes', 'closing_notes',
        'requires_admin_review', 'reviewed_by_admin_id', 'reviewed_at',
        'zone_code',
    ];

    protected $casts = [
        'station_id' => 'integer',
        'opened_by_user_id' => 'integer',
        'closed_by_user_id' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'opening_cash' => 'decimal:4',
        'expected_cash' => 'decimal:4',
        'actual_cash' => 'decimal:4',
        'variance' => 'decimal:4',
        'total_cash_sales' => 'decimal:4',
        'total_amial_pay_sales' => 'decimal:4',
        'total_company_sales' => 'decimal:4',
        'total_liters' => 'decimal:4',
        'requires_admin_review' => 'boolean',
    ];

    public const STATUSES = ['open', 'closed', 'closed_with_variance'];

    public function station(): BelongsTo
    {
        return $this->belongsTo(FuelStation::class, 'station_id');
    }

    public function pumpSummaries(): HasMany
    {
        return $this->hasMany(FuelShiftPumpSummary::class, 'shift_id');
    }

    public function varianceRecords(): HasMany
    {
        return $this->hasMany(FuelVarianceRecord::class, 'shift_id');
    }
}
