<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** AMIAL-SUPPLIERS-001 — أمر شراء. */
class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number', 'merchant_user_id', 'supplier_id', 'status',
        'total_amount', 'notes', 'approved_at', 'completed_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
