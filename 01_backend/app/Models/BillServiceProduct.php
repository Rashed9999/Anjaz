<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillServiceProduct extends Model
{
    protected $table = 'bill_service_products';
    protected $fillable = [
        'service_id', 'product_code', 'name', 'amount_type',
        'fixed_amount', 'min_amount', 'max_amount',
        'fee_amount', 'fee_percent', 'is_active', 'sort_order',
    ];
    protected $casts = [
        'fixed_amount' => 'decimal:4',
        'min_amount' => 'decimal:4',
        'max_amount' => 'decimal:4',
        'fee_amount' => 'decimal:4',
        'fee_percent' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(BillService::class, 'service_id');
    }
}
