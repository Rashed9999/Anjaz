<?php

namespace App\Models\Fuel;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** قياسُ الخزّان — **الحقيقةُ التي يُقاس عليها الدفتر**، لا العكس. */
class FuelTankDip extends Model
{
    protected $table = 'fuel_tank_dips';

    protected $fillable = [
        'tank_id', 'shift_id', 'dip_type', 'dip_liters',
        'temperature_c', 'taken_by_user_id', 'note', 'taken_at',
    ];

    protected $casts = [
        'tank_id' => 'integer',
        'shift_id' => 'integer',
        'dip_liters' => 'decimal:3',
        'temperature_c' => 'decimal:2',
        'taken_at' => 'datetime',
    ];

    public const TYPES = ['opening', 'closing', 'spot', 'delivery'];

    public function tank(): BelongsTo
    {
        return $this->belongsTo(FuelTank::class, 'tank_id');
    }

    public function takenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'taken_by_user_id');
    }
}
