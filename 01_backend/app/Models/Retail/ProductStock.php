<?php

namespace App\Models\Retail;

use App\Models\MerchantProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * رصيدُ صنفٍ في موقع — **لقطةٌ لا مصدر**.
 *
 * المصدرُ `stock_movements`، وهذه تُقرأ سريعاً في نقطة البيع وشاشة
 * المخزون. ويُحرَس تطابقُها مع مجموع الحركات — فلقطةٌ تنحرف عن مصدرها
 * بلا حارسٍ تصير رقماً يُقرأ حقيقةً وهو ليس كذلك. (القاعدة السادسة.)
 */
class ProductStock extends Model
{
    protected $table = 'product_stocks';

    protected $fillable = [
        'product_id', 'location_id', 'on_hand', 'reserved',
        'reorder_level', 'max_level', 'last_counted_at', 'last_movement_at',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'location_id' => 'integer',
        'on_hand' => 'decimal:3',
        'reserved' => 'decimal:3',
        'reorder_level' => 'decimal:3',
        'max_level' => 'decimal:3',
        'last_counted_at' => 'datetime',
        'last_movement_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(MerchantProduct::class, 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(MerchantLocation::class, 'location_id');
    }

    /** المتاحُ للبيع — **الموجود ناقصَ المحجوز**، لا الموجود وحدَه. */
    public function available(): string
    {
        return bcsub((string) $this->on_hand, (string) $this->reserved, 3);
    }

    public function isLow(): bool
    {
        return bccomp((string) $this->reorder_level, '0', 3) > 0
            && bccomp($this->available(), (string) $this->reorder_level, 3) <= 0;
    }

    /** **«لم يُجرَد» ليس «مطابق»** — يُقال صراحةً. */
    public function neverCounted(): bool
    {
        return $this->last_counted_at === null;
    }
}
