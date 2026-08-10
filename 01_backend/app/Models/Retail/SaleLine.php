<?php

namespace App\Models\Retail;

use App\Models\MerchantProduct;
use App\Models\MerchantSale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — سطرُ مبيعة.
 *
 * **وهو لقطةٌ لا مرجع.** الاسمُ والسعرُ والتكلفةُ تُكتب هنا لحظةَ البيع،
 * فتغيُّرُ المنتج بعدها لا يُعيد كتابة فاتورةٍ صدرت.
 */
class SaleLine extends Model
{
    protected $table = 'merchant_sale_items';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'sale_id', 'sale_ulid', 'product_id',
        'name', 'barcode', 'quantity', 'unit_price', 'line_discount',
        'line_total', 'unit_cost', 'line_cost', 'cost_source',
        'returned_quantity', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'sale_id' => 'integer',
        'product_id' => 'integer',
    ];

    /** التكلفةُ محسوبةٌ من التقاطٍ حقيقيّ — لا تقديرَ ولا فراغ. */
    public const COST_CAPTURED = 'captured';

    /** قُدّرت في الهجرة من التكلفة الحاليّة — رقمٌ يُقرأ بحذر. */
    public const COST_ESTIMATED = 'estimated_backfill';

    /** **غيرُ معروفة — وليست صفراً** (القاعدة ٧). */
    public const COST_UNKNOWN = 'unknown';

    public function sale(): BelongsTo
    {
        return $this->belongsTo(MerchantSale::class, 'sale_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MerchantProduct::class, 'product_id');
    }

    /** هل تكلفةُ هذا السطر رقمٌ يُبنى عليه ربح؟ */
    public function hasKnownCost(): bool
    {
        return $this->cost_source === self::COST_CAPTURED && $this->line_cost !== null;
    }

    /** الكمّيّةُ التي لم تُرتجَع بعد — بها يُحدّ المرتجعُ الجزئيّ. */
    public function refundableQuantity(): string
    {
        return bcsub((string) $this->quantity, (string) $this->returned_quantity, 3);
    }

    public function costSourceAr(): string
    {
        return match ($this->cost_source) {
            self::COST_CAPTURED => 'مُلتقَطة لحظة البيع',
            self::COST_ESTIMATED => 'مُقدَّرة من تكلفة لاحقة',
            default => 'غير معروفة',
        };
    }
}
