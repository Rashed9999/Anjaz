<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PharmacyProduct extends Model
{
    protected $table = 'pharmacy_products';

    protected $fillable = [
        'pharmacy_id', 'category_id',
        'sku', 'barcode', 'trade_name', 'generic_name', 'manufacturer', 'unit',
        'sale_price', 'cost_price',
        'requires_prescription', 'is_active',
        'low_stock_threshold', 'description', 'dosage_instructions',
        'current_stock', 'image_path',
    ];

    protected $casts = [
        'pharmacy_id' => 'integer',
        'category_id' => 'integer',
        'sale_price' => 'decimal:4',
        'cost_price' => 'decimal:4',
        'current_stock' => 'decimal:4',
        'requires_prescription' => 'boolean',
        'is_active' => 'boolean',
        'low_stock_threshold' => 'integer',
    ];

    public function pharmacy(): BelongsTo
    {
        return $this->belongsTo(Pharmacy::class, 'pharmacy_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PharmacyCategory::class, 'category_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(PharmacyBatch::class, 'product_id');
    }

    /** Batches متاحة للبيع، مُرتّبة FIFO (الأقدم انتهاءً أولاً). */
    public function activeBatches(): HasMany
    {
        return $this->hasMany(PharmacyBatch::class, 'product_id')
            ->where('status', 'active')
            ->where('quantity_remaining', '>', 0)
            ->orderBy('expiry_date'); // FIFO على أساس تاريخ الانتهاء
    }

    public function isLowStock(): bool
    {
        return (float)$this->current_stock <= $this->low_stock_threshold;
    }
}
