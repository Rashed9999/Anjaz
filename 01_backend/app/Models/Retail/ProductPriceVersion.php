<?php

namespace App\Models\Retail;

use App\Models\MerchantProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٧ — نسخةُ سعرٍ بسريانٍ واعتماد.
 *
 * **تعميمٌ لِما بُني في الوقود** (`fuel_price_versions`) — والمنطقُ واحد،
 * وبناؤه مرّتين يعني إصلاحَ عطلٍ في أحدهما وبقاءَه في الآخر.
 */
class ProductPriceVersion extends Model
{
    protected $table = 'product_price_versions';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'product_id', 'location_id',
        'price', 'offer_price', 'cost_price_at_time',
        'effective_from', 'effective_to', 'status',
        'created_by_user_id', 'approved_by_user_id', 'approved_at', 'reason',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'product_id' => 'integer',
        'location_id' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public const PROPOSED = 'proposed';
    public const APPROVED = 'approved';
    public const ACTIVE = 'active';
    public const EXPIRED = 'expired';
    public const REJECTED = 'rejected';

    public function product(): BelongsTo
    {
        return $this->belongsTo(MerchantProduct::class, 'product_id');
    }

    public function statusAr(): string
    {
        return match ($this->status) {
            self::PROPOSED => 'مقترَح',
            self::APPROVED => 'معتمَد وينتظر السريان',
            self::ACTIVE => 'ساري',
            self::EXPIRED => 'منتهٍ',
            default => 'مرفوض',
        };
    }
}
