<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PharmacySale extends Model
{
    protected $table = 'pharmacy_sales';

    protected $fillable = [
        'sale_ulid', 'merchant_user_id', 'pos_user_id', 'created_by_user_id', 'pharmacy_id', 'customer_id',
        'prescription_number', 'prescribing_doctor', 'prescription_date',
        'subtotal', 'discount_amount', 'total_amount',
        // AMIAL-CASH-TENDERED-001 — وغيابُه هنا يُسقطه صامتاً.
        'amount_received',
        'payment_method', 'paid_transaction_id',
        'warnings_acknowledged',
        'status', 'notes', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'pos_user_id' => 'integer',
        'created_by_user_id' => 'integer',
        'pharmacy_id' => 'integer',
        'customer_id' => 'integer',
        'prescription_date' => 'date',
        'subtotal' => 'decimal:4',
        'discount_amount' => 'decimal:4',
        'total_amount' => 'decimal:4',
        'warnings_acknowledged' => 'array',
    ];

    public const PAYMENT_METHODS = ['cash', 'amial_pay', 'credit'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(PharmacyCustomer::class, 'customer_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PharmacySaleItem::class, 'sale_id');
    }
}
