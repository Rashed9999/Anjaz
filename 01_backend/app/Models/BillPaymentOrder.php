<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillPaymentOrder extends Model
{
    protected $table = 'bill_payment_orders';
    protected $fillable = [
        'order_ulid', 'idempotency_key', 'correlation_id', 'user_id', 'provider_id', 'service_id', 'product_id',
        'subscriber_account', 'subscriber_extra',
        'amount', 'fee', 'total_debited', 'funds_state',
        'status', 'wallet_transaction_id', 'provider_reference', 'provider_message',
        'provider_attempt_count', 'last_provider_check_at', 'next_reconciliation_at',
        'fee_scheme_id', 'fee_scheme_version', 'fee_configuration_state',
        'zone_code', 'completed_at', 'reversed_at', 'reverse_reason',
    ];
    protected $casts = [
        'subscriber_extra' => 'array',
        'amount' => 'decimal:4',
        'fee' => 'decimal:4',
        'total_debited' => 'decimal:4',
        'provider_attempt_count' => 'integer',
        'last_provider_check_at' => 'datetime',
        'next_reconciliation_at' => 'datetime',
        'fee_scheme_id' => 'integer',
        'fee_scheme_version' => 'integer',
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
