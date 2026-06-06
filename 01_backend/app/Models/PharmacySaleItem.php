<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PharmacySaleItem extends Model
{
    protected $table = 'pharmacy_sale_items';

    protected $fillable = [
        'sale_id', 'product_id', 'batch_id',
        'product_trade_name', 'quantity',
        'unit_price', 'total_price',
        'required_prescription',
    ];

    protected $casts = [
        'sale_id' => 'integer',
        'product_id' => 'integer',
        'batch_id' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'total_price' => 'decimal:4',
        'required_prescription' => 'boolean',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PharmacySale::class, 'sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(PharmacyProduct::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PharmacyBatch::class, 'batch_id');
    }
}
