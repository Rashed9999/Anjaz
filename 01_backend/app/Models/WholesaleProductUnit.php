<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleProductUnit extends Model
{
    protected $table = 'wholesale_product_units';

    protected $fillable = ['product_id', 'code', 'name', 'factor_to_base', 'is_base', 'is_active'];

    protected $casts = [
        'product_id' => 'integer', 'factor_to_base' => 'decimal:4',
        'is_base' => 'boolean', 'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(WholesaleProduct::class, 'product_id');
    }
}
