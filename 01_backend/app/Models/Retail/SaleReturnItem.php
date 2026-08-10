<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٦ — سطرُ مرتجع.
 *
 * **و`restock` قرارٌ لا افتراض**: مرتجعٌ سليمٌ يعود للرفّ، وتالفٌ يذهب
 * هالكاً. وإعادةُ الجميع تلقائياً تُعيد التالفَ للبيع.
 */
class SaleReturnItem extends Model
{
    protected $table = 'sale_return_items';

    protected $fillable = [
        'return_id', 'sale_item_id', 'product_id', 'name', 'quantity',
        'unit_price', 'line_total', 'unit_cost', 'condition', 'restock',
    ];

    protected $casts = [
        'return_id' => 'integer',
        'sale_item_id' => 'integer',
        'product_id' => 'integer',
        'restock' => 'boolean',
    ];

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class, 'sale_item_id');
    }

    public function conditionAr(): string
    {
        return match ($this->condition) {
            'damaged' => 'تالف',
            'expired' => 'منتهي الصلاحية',
            default => 'سليم',
        };
    }
}
