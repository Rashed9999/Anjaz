<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PharmacyStockAlert extends Model
{
    protected $table = 'pharmacy_stock_alerts';

    protected $fillable = [
        'pharmacy_id', 'product_id', 'batch_id',
        'alert_type', 'severity', 'message', 'details',
        'status', 'dismissed_at',
    ];

    protected $casts = [
        'pharmacy_id' => 'integer',
        'product_id' => 'integer',
        'batch_id' => 'integer',
        'details' => 'array',
        'dismissed_at' => 'datetime',
    ];

    public const TYPES = ['low_stock', 'near_expiry', 'expired'];
    public const SEVERITIES = ['info', 'warning', 'critical'];
    public const STATUSES = ['active', 'dismissed', 'resolved'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(PharmacyProduct::class, 'product_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(PharmacyBatch::class, 'batch_id');
    }
}
