<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pharmacy extends Model
{
    protected $table = 'pharmacies';

    protected $fillable = [
        'merchant_user_id', 'pharmacy_name', 'license_number',
        'pharmacist_name', 'pharmacist_license',
        'city', 'address', 'is_active', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(PharmacyProduct::class, 'pharmacy_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(PharmacyCustomer::class, 'pharmacy_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(PharmacyCategory::class, 'pharmacy_id');
    }
}
