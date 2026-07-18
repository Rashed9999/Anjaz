<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-FUEL-PRICE-HISTORY-001 — سجلّ تغيّر سعر وقود واحد.
 */
class FuelPriceHistory extends Model
{
    protected $table = 'fuel_price_history';
    public $timestamps = false;

    protected $fillable = [
        'fuel_product_id', 'station_id', 'changed_by_user_id',
        'old_price', 'new_price', 'delta', 'note', 'created_at',
    ];

    protected $casts = [
        'old_price' => 'decimal:4',
        'new_price' => 'decimal:4',
        'delta' => 'decimal:4',
        'created_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(FuelProduct::class, 'fuel_product_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
