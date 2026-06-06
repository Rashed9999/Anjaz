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
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'is_active' => 'boolean',
        'production_date' => 'date',
        'expiry_date' => 'date',
    ];

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
