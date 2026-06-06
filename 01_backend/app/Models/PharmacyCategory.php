<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PharmacyCategory extends Model
{
    protected $table = 'pharmacy_categories';

    protected $fillable = [
        'pharmacy_id', 'name', 'icon', 'color_hex', 'sort_order',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(PharmacyProduct::class, 'category_id');
    }
}
