<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MerchantFixedAsset extends Model
{
    protected $fillable = [
        'asset_ulid', 'merchant_user_id', 'supplier_id', 'purchase_order_id',
        'purchase_order_item_id', 'name', 'category', 'quantity',
        'acquisition_cost', 'salvage_value', 'useful_life_months',
        'depreciation_method', 'acquired_on', 'depreciation_starts_on',
        'status', 'disposed_on', 'disposal_proceeds', 'disposal_book_value',
        'disposal_gain_loss', 'disposal_payment_source', 'disposal_cashier_shift_id',
        'disposal_reason',
        'created_by', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'supplier_id' => 'integer',
        'purchase_order_id' => 'integer',
        'purchase_order_item_id' => 'integer',
        'quantity' => 'decimal:3',
        'acquisition_cost' => 'decimal:4',
        'salvage_value' => 'decimal:4',
        'useful_life_months' => 'integer',
        'acquired_on' => 'date',
        'depreciation_starts_on' => 'date',
        'disposed_on' => 'date',
        'disposal_proceeds' => 'decimal:4',
        'disposal_book_value' => 'decimal:4',
        'disposal_gain_loss' => 'decimal:4',
        'disposal_cashier_shift_id' => 'integer',
    ];

    public const CATEGORIES = [
        'furniture', 'equipment', 'computer', 'vehicle', 'machinery',
        'fixtures', 'building_improvement', 'other',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(MerchantAssetAdjustment::class, 'asset_id')
            ->orderBy('id');
    }

    public function depreciations(): HasMany
    {
        return $this->hasMany(MerchantAssetDepreciation::class, 'asset_id')
            ->orderBy('period');
    }
}
