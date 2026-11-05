<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleInvoiceItemLot extends Model
{
    protected $table = 'wholesale_invoice_item_lots';

    protected $fillable = ['invoice_item_id', 'lot_id', 'base_quantity'];

    protected $casts = [
        'invoice_item_id' => 'integer', 'lot_id' => 'integer', 'base_quantity' => 'decimal:4',
    ];

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(WholesaleInvoiceItem::class, 'invoice_item_id');
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(WholesaleProductLot::class, 'lot_id');
    }
}
