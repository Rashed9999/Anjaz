<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PharmacyBatch extends Model
{
    protected $table = 'pharmacy_batches';

    protected $fillable = [
        'batch_ulid', 'product_id',
        'batch_number', 'expiry_date', 'received_date', 'manufactured_at',
        'quantity_received', 'quantity_remaining',
        'cost_per_unit', 'supplier_name', 'supplier_invoice',
        'status', 'recall_reason', 'recalled_by_user_id', 'recalled_at',
        'disposition_type', 'disposition_reason', 'disposed_by_user_id', 'disposed_at',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'expiry_date' => 'date',
        'received_date' => 'date',
        'manufactured_at' => 'date',
        'quantity_received' => 'decimal:4',
        'quantity_remaining' => 'decimal:4',
        'cost_per_unit' => 'decimal:4',
        'recalled_by_user_id' => 'integer', 'recalled_at' => 'datetime',
        'disposed_by_user_id' => 'integer', 'disposed_at' => 'datetime',
    ];

    public const STATUSES = ['active', 'exhausted', 'expired', 'recalled', 'returned', 'destroyed'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(PharmacyProduct::class, 'product_id');
    }

    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->isPast();
    }

    public function daysUntilExpiry(): ?int
    {
        if (!$this->expiry_date) return null;
        return (int) now()->startOfDay()->diffInDays($this->expiry_date->startOfDay(), false);
    }

    public function isNearExpiry(int $thresholdDays = 30): bool
    {
        $days = $this->daysUntilExpiry();
        return $days !== null && $days >= 0 && $days <= $thresholdDays;
    }
}
