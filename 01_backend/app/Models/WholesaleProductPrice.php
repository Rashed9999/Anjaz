<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleProductPrice extends Model
{
    protected $table = 'wholesale_product_prices';
    protected $fillable = ['product_id', 'tier_id', 'price', 'min_quantity'];
    protected $casts = [
        'product_id' => 'integer', 'tier_id' => 'integer',
        'price' => 'decimal:4', 'min_quantity' => 'decimal:4',
    ];

    public function product(): BelongsTo {
        return $this->belongsTo(WholesaleProduct::class, 'product_id');
    }
    public function tier(): BelongsTo {
        return $this->belongsTo(WholesalePriceTier::class, 'tier_id');
    }
}
