<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable inbox record for a provider callback.
 *
 * The callback is evidence that a provider sent an update, not authority to
 * change wallet or ledger state. BillPayService always confirms it by querying
 * the provider using our original transaction ID.
 */
class BillProviderWebhookEvent extends Model
{
    public const RESULT_QUEUED = 'queued';
    public const RESULT_IGNORED = 'ignored';
    public const RESULT_PROCESSED = 'processed';
    public const RESULT_FAILED = 'failed';

    public $timestamps = false;

    protected $fillable = [
        'provider_id', 'order_id', 'event_fingerprint',
        'provider_transaction_id', 'provider_reference', 'operation_status',
        'price', 'message', 'payload', 'received_at', 'processed_at',
        'processing_result', 'processing_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'price' => 'decimal:4',
        'received_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(BillProvider::class, 'provider_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(BillPaymentOrder::class, 'order_id');
    }
}
