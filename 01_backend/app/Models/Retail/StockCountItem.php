<?php

namespace App\Models\Retail;

use App\Models\MerchantProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٥ — سطرُ جرد.
 *
 * **و`counted_quantity = null` تعني «لم يُعدّ»، والصفرُ «عُدّ فلم يوجد»**
 * (القاعدة ٧). وخلطُهما يجعل صنفاً لم يصل إليه العادُّ يُقرأ مفقوداً
 * بالكامل، فيُشطب مخزونٌ موجودٌ على الرفّ.
 */
class StockCountItem extends Model
{
    protected $table = 'stock_count_items';

    protected $fillable = [
        'count_id', 'product_id', 'name', 'system_quantity', 'counted_quantity',
        'variance', 'variance_reason', 'unit_cost', 'note', 'counted_by', 'counted_at',
    ];

    protected $casts = [
        'count_id' => 'integer',
        'product_id' => 'integer',
        'counted_at' => 'datetime',
    ];

    public const REASONS = [
        'damaged', 'expired', 'theft', 'entry_error',
        'unrecorded_sale', 'found', 'other',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(MerchantProduct::class, 'product_id');
    }

    public function wasCounted(): bool
    {
        return $this->counted_quantity !== null;
    }

    public function reasonAr(): string
    {
        return match ($this->variance_reason) {
            'damaged' => 'تلف',
            'expired' => 'انتهاء صلاحية',
            'theft' => 'سرقة',
            'entry_error' => 'خطأ إدخال',
            'unrecorded_sale' => 'بيع غير مسجّل',
            'found' => 'وُجد زائداً',
            'other' => 'أخرى',
            default => 'بلا سبب',
        };
    }
}
