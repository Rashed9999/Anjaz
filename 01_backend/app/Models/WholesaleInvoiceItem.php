<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleInvoiceItem extends Model
{
    protected $table = 'wholesale_invoice_items';
    protected $fillable = [
        'invoice_id', 'product_id', 'product_unit_id',
        'product_name', 'product_sku', 'unit',
        'quantity', 'unit_factor', 'base_quantity', 'unit_price', 'discount_per_unit', 'line_total',
        'tier_id',
    ];
    protected $casts = [
        'invoice_id' => 'integer', 'product_id' => 'integer', 'product_unit_id' => 'integer', 'tier_id' => 'integer',
        'quantity' => 'decimal:4', 'unit_factor' => 'decimal:4', 'base_quantity' => 'decimal:4', 'unit_price' => 'decimal:4',
        'discount_per_unit' => 'decimal:4', 'line_total' => 'decimal:4',
    ];

    public function invoice(): BelongsTo { return $this->belongsTo(WholesaleInvoice::class, 'invoice_id'); }
    public function product(): BelongsTo { return $this->belongsTo(WholesaleProduct::class, 'product_id'); }
    public function productUnit(): BelongsTo { return $this->belongsTo(WholesaleProductUnit::class, 'product_unit_id'); }
    public function lotAllocations(): HasMany { return $this->hasMany(WholesaleInvoiceItemLot::class, 'invoice_item_id'); }
}
