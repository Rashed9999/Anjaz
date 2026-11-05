<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholesaleCollection extends Model
{
    protected $table = 'wholesale_collections';
    protected $fillable = [
        'collection_ulid', 'invoice_id', 'customer_id', 'business_id',
        'received_by_user_id',
        'collection_date', 'amount', 'payment_method',
        'reference_number', 'paid_transaction_id', 'notes',
    ];
    protected $casts = [
        'invoice_id' => 'integer', 'customer_id' => 'integer',
        'business_id' => 'integer', 'received_by_user_id' => 'integer',
        'collection_date' => 'date', 'amount' => 'decimal:4',
    ];

    public const PAYMENT_METHODS = ['cash', 'bank_transfer', 'amial_pay', 'check', 'customer_wallet'];

    public function invoice(): BelongsTo { return $this->belongsTo(WholesaleInvoice::class, 'invoice_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(WholesaleCustomer::class, 'customer_id'); }
    public function business(): BelongsTo { return $this->belongsTo(WholesaleBusiness::class, 'business_id'); }
}
