<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** AMIAL-DAILY-MOVEMENT-001 — سطرُ مرتجعِ شراء. */
class PurchaseReturnItem extends Model
{
    protected $table = 'purchase_return_items';

    protected $fillable = [
        'return_id', 'product_id', 'purchase_order_item_id',
        'name', 'quantity', 'unit_cost', 'line_total',
    ];

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'return_id');
    }
}
