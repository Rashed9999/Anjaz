<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelCompanyCard extends Model
{
    protected $table = 'fuel_company_cards';

    protected $fillable = [
        'company_account_id', 'card_number', 'card_label',
        'vehicle_plate', 'driver_name', 'driver_phone',
        'daily_limit', 'monthly_limit', 'is_active',
    ];

    protected $casts = [
        'company_account_id' => 'integer',
        'daily_limit' => 'decimal:4',
        'monthly_limit' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(FuelCompanyAccount::class, 'company_account_id');
    }
}
