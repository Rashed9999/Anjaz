<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillProviderRequest extends Model
{
    protected $table = 'bill_provider_requests';
    public $timestamps = false;
    protected $fillable = [
        'order_id', 'provider_id', 'request_type',
        'request_payload', 'response_payload',
        'http_status', 'latency_ms', 'was_successful', 'error_message',
        'created_at',
    ];
    protected $casts = [
        'request_payload' => 'array',
        'response_payload' => 'array',
        'was_successful' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo { return $this->belongsTo(BillPaymentOrder::class, 'order_id'); }
    public function provider(): BelongsTo { return $this->belongsTo(BillProvider::class, 'provider_id'); }
}
