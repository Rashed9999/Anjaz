<?php

namespace App\Models\Fuel;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** مورِّدُ الوقود — التوريدُ يأتي من جهةٍ معلومة لا من العدم. */
class FuelSupplier extends Model
{
    protected $table = 'fuel_suppliers';

    protected $fillable = [
        'merchant_user_id', 'name', 'phone', 'tax_number', 'notes', 'is_active',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(FuelDelivery::class, 'supplier_id');
    }
}
