<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MerchantAssetAdjustment extends Model
{
    protected $fillable = [
        'adjustment_ulid', 'merchant_user_id', 'asset_id', 'purchase_return_id',
        'type', 'quantity', 'cost_amount', 'salvage_amount',
        'depreciation_reversed', 'effective_on', 'note', 'created_by',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'asset_id' => 'integer',
        'purchase_return_id' => 'integer',
        'quantity' => 'decimal:3',
        'cost_amount' => 'decimal:4',
        'salvage_amount' => 'decimal:4',
        'depreciation_reversed' => 'decimal:4',
        'effective_on' => 'date',
        'created_by' => 'integer',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(MerchantFixedAsset::class, 'asset_id');
    }

    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id');
    }
}
