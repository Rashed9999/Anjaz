<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillPaymentOrder extends Model
{
    protected $table = 'bill_payment_orders';
    protected $fillable = [
        'order_ulid', 'user_id', 'provider_id', 'service_id', 'product_id',
        'subscriber_account', 'subscriber_extra',
        'amount', 'fee', 'total_debited',
        'status', 'wallet_transaction_id', 'provider_reference', 'provider_message',
        'zone_code', 'completed_at', 'reversed_at', 'reverse_reason',
    ];
    protected $casts = [
        'subscriber_extra' => 'array',
        'amount' => 'decimal:4',
        'fee' => 'decimal:4',
        'total_debited' => 'decimal:4',
        'completed_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }
    public function provider(): BelongsTo { return $this->belongsTo(BillProvider::class, 'provider_id'); }
    public function service(): BelongsTo { return $this->belongsTo(BillService::class, 'service_id'); }
    public function product(): BelongsTo { return $this->belongsTo(BillServiceProduct::class, 'product_id'); }
    public function requests(): HasMany { return $this->hasMany(BillProviderRequest::class, 'order_id'); }

    public function isPending(): bool { return in_array($this->status, ['pending', 'processing', 'pending_provider_confirmation']); }
    public function isSuccessful(): bool { return $this->status === 'success'; }
}
