<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-CASHIER-001 — منتج في كتالوج التاجر (اختياري).
 */
class MerchantProduct extends Model
{
    protected $table = 'merchant_products';

    protected $fillable = [
        'merchant_user_id', 'name', 'price', 'cost_price', 'offer_price',
        'quantity', 'production_date', 'expiry_date',
        'category', 'barcode', 'is_active',
        // AMIAL-RETAIL-VERTICAL-001 · المرحلتان ٢ و٣ — محرّك الأصناف.
        // و`category` و`barcode` تبقيان مرآتين لا مصدرَي حقيقة.
        'uuid', 'sku', 'category_id', 'brand_id', 'unit_id',
        'parent_product_id', 'variant_attributes', 'is_variant_parent',
        'track_stock', 'reorder_level',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'is_active' => 'boolean',
        'production_date' => 'date',
        'expiry_date' => 'date',
        'category_id' => 'integer',
        'brand_id' => 'integer',
        'unit_id' => 'integer',
        'parent_product_id' => 'integer',
        'variant_attributes' => 'array',
        'is_variant_parent' => 'boolean',
        'track_stock' => 'boolean',
    ];

    // ── علاقاتُ محرّك الأصناف ─────────────────────────────────────────

    public function category()
    {
        return $this->belongsTo(\App\Models\Retail\MerchantCategory::class, 'category_id');
    }

    public function brand()
    {
        return $this->belongsTo(\App\Models\Retail\MerchantBrand::class, 'brand_id');
    }

    public function unit()
    {
        return $this->belongsTo(\App\Models\Retail\MerchantUnit::class, 'unit_id');
    }

    public function barcodes()
    {
        return $this->hasMany(\App\Models\Retail\ProductBarcode::class, 'product_id');
    }

    /** الأبُ — **لا يُباع ولا يُخزَّن**، هو مِظلّةٌ للمتغيّرات. */
    public function parentProduct()
    {
        return $this->belongsTo(self::class, 'parent_product_id');
    }

    public function variants()
    {
        return $this->hasMany(self::class, 'parent_product_id');
    }

    /**
     * **«قميص · أحمر · L»** — الاسمُ المعروض للمتغيّر.
     *
     * ولولاه ظهرت تسعةُ صفوفٍ باسم «قميص» في شاشة البيع، ولا يُعرف أيُّها
     * الذي في اليد.
     */
    public function displayName(): string
    {
        $attrs = $this->variant_attributes;
        if (! is_array($attrs) || $attrs === []) {
            return (string) $this->name;
        }

        return $this->name . ' · ' . implode(' · ', array_map(
            static fn ($v) => (string) $v, array_values($attrs)
        ));
    }

    protected $appends = ['effective_price'];

    /** السعر الفعّال للبيع = سعر العرض إن وُجد، وإلا سعر البيع. */
    public function getEffectivePriceAttribute(): string
    {
        $offer = $this->offer_price;
        if ($offer !== null && (float) $offer > 0) {
            return (string) $offer;
        }
        return (string) $this->price;
    }

    /** هل المنتج منتهٍ أو قارب الانتهاء خلال عدد أيام. */
    public function isExpiringWithin(int $days = 30): bool
    {
        return $this->expiry_date !== null
            && $this->expiry_date->lte(now()->addDays($days));
    }
}
