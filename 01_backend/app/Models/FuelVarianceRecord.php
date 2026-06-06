<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelVarianceRecord extends Model
{
    protected $table = 'fuel_variance_records';

    protected $fillable = [
        'record_ulid', 'shift_id', 'station_id', 'reported_by_user_id',
        'variance_type', 'direction', 'amount',
        'reason', 'admin_note',
        'resolution_status', 'resolved_by_admin_id', 'resolved_at',
        'zone_code',
    ];

    protected $casts = [
        'shift_id' => 'integer',
        'station_id' => 'integer',
        'reported_by_user_id' => 'integer',
        'resolved_by_admin_id' => 'integer',
        'amount' => 'decimal:4',
        'resolved_at' => 'datetime',
    ];

    public const TYPES = ['cash_variance', 'liters_variance'];
    public const DIRECTIONS = ['shortage', 'surplus'];
    public const RESOLUTIONS = [
        'pending', 'accepted', 'covered_by_employee',
        'charged_to_petty_cash', 'written_off',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(FuelShift::class, 'shift_id');
    }

    public function isShortage(): bool { return $this->direction === 'shortage'; }
    public function isSurplus(): bool { return $this->direction === 'surplus'; }
}
