<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleProduct extends Model
{
    protected $table = 'wholesale_products';
    protected $fillable = [
        'business_id', 'sku', 'barcode', 'name', 'manufacturer', 'unit',
        'current_stock', 'low_stock_threshold', 'cost_price', 'base_price',
        'is_active', 'description', 'image_path',
    ];
    protected $casts = [
        'business_id' => 'integer',
        'current_stock' => 'decimal:4', 'cost_price' => 'decimal:4', 'base_price' => 'decimal:4',
        'low_stock_threshold' => 'integer', 'is_active' => 'boolean',
    ];

    public function business(): BelongsTo {
        return $this->belongsTo(WholesaleBusiness::class, 'business_id');
    }
    public function prices(): HasMany {
        return $this->hasMany(WholesaleProductPrice::class, 'product_id');
    }
    public function units(): HasMany {
        return $this->hasMany(WholesaleProductUnit::class, 'product_id');
    }
    public function lots(): HasMany {
        return $this->hasMany(WholesaleProductLot::class, 'product_id');
    }

    /**
     * يعيد السعر الأنسب لـ tier محدّد و quantity محدّدة.
     * يبحث عن أعلى min_quantity ≤ qty (أي أفضل سعر للكمية).
     * إن لم يُعثر على سعر للشريحة، يعيد base_price.
     */
    public function priceFor(int $tierId, float $qty): float
    {
        $price = WholesaleProductPrice::where('product_id', $this->id)
            ->where('tier_id', $tierId)
            ->where('min_quantity', '<=', $qty)
            ->orderByDesc('min_quantity')
            ->value('price');
        return $price !== null ? (float)$price : (float)$this->base_price;
    }

    public function isLowStock(): bool
    {
        return (float)$this->current_stock <= $this->low_stock_threshold;
    }
}
