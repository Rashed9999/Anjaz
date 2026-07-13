<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** AMIAL-SUPPLIERS-001 — بند أمر شراء. */
class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id', 'product_id', 'name',
        'quantity', 'received_quantity', 'unit_cost',
    ];
}
