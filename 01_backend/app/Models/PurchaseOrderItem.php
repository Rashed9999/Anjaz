<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** AMIAL-SUPPLIERS-001 — بند أمر شراء. */
class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'name',
        'quantity', 'received_quantity', 'returned_quantity', 'unit_cost',
    ];

    /** ما بقي قابلاً للردّ من هذا البند — **مُستلَمٌ ناقصُ ما رُدّ**. */
    public function returnableQuantity(): string
    {
        return bcsub((string) $this->received_quantity,
            (string) ($this->returned_quantity ?? '0'), 3);
    }
}
