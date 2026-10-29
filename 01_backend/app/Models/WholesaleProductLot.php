<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleProductLot extends Model
{
    public const STATUSES = ['active', 'quarantined', 'expired', 'disposed'];

    protected $table = 'wholesale_product_lots';

    protected $fillable = [
        'lot_ulid', 'product_id', 'lot_number', 'location', 'received_at', 'expiry_date',
        'quantity_received', 'quantity_available', 'cost_per_base_unit',
        'supplier_reference', 'status',
    ];

    protected $casts = [
        'product_id' => 'integer', 'received_at' => 'date', 'expiry_date' => 'date',
        'quantity_received' => 'decimal:4', 'quantity_available' => 'decimal:4',
        'cost_per_base_unit' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(WholesaleProduct::class, 'product_id');
    }
}
