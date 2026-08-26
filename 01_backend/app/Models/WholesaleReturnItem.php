<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleReturnItem extends Model
{
    protected $table = 'wholesale_return_items';
    protected $fillable = [
        'return_id', 'invoice_item_id', 'product_id', 'product_name', 'unit',
        'quantity', 'unit_price', 'discount_per_unit', 'line_total',
    ];
    protected $casts = [
        'return_id' => 'integer', 'invoice_item_id' => 'integer', 'product_id' => 'integer',
        'quantity' => 'decimal:4', 'unit_price' => 'decimal:4',
        'discount_per_unit' => 'decimal:4', 'line_total' => 'decimal:4',
    ];
    public function returnRequest(): BelongsTo { return $this->belongsTo(WholesaleReturn::class, 'return_id'); }
}
