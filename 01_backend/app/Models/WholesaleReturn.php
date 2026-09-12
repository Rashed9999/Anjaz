<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleReturn extends Model
{
    protected $table = 'wholesale_returns';
    protected $fillable = [
        'return_ulid', 'business_id', 'invoice_id', 'customer_id',
        'requested_by_user_id', 'reviewed_by_user_id', 'status', 'settlement_type',
        'subtotal_amount', 'discount_amount', 'tax_amount', 'total_amount',
        'credited_amount', 'refund_due_amount', 'reason', 'decision_note', 'resolved_at',
    ];
    protected $casts = [
        'business_id' => 'integer', 'invoice_id' => 'integer', 'customer_id' => 'integer',
        'requested_by_user_id' => 'integer', 'reviewed_by_user_id' => 'integer',
        'subtotal_amount' => 'decimal:4', 'discount_amount' => 'decimal:4',
        'tax_amount' => 'decimal:4', 'total_amount' => 'decimal:4',
        'credited_amount' => 'decimal:4', 'refund_due_amount' => 'decimal:4',
        'resolved_at' => 'datetime',
    ];
    public const STATUSES = ['requested', 'approved', 'rejected'];

    public function invoice(): BelongsTo { return $this->belongsTo(WholesaleInvoice::class, 'invoice_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(WholesaleCustomer::class, 'customer_id'); }
    public function items(): HasMany { return $this->hasMany(WholesaleReturnItem::class, 'return_id'); }
}
