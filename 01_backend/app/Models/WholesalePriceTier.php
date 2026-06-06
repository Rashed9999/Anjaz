<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesalePriceTier extends Model
{
    protected $table = 'wholesale_price_tiers';
    protected $fillable = ['business_id', 'code', 'name', 'sort_order', 'is_default', 'is_active'];
    protected $casts = [
        'business_id' => 'integer', 'sort_order' => 'integer',
        'is_default' => 'boolean', 'is_active' => 'boolean',
    ];

    public function business(): BelongsTo {
        return $this->belongsTo(WholesaleBusiness::class, 'business_id');
    }
}
