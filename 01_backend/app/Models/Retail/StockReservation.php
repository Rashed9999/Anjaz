<?php

namespace App\Models\Retail;

use App\Models\MerchantProduct;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٩ — حجزٌ ينتظر الدفع.
 *
 * **والبضاعةُ موجودةٌ وغيرُ متاحة**: `on_hand` لا يتغيّر و`reserved` يزيد.
 * فإن دُفع صار الحجزُ حركةَ بيعٍ حقيقيّة، وإن لم يُدفع أُفرج عنه.
 */
class StockReservation extends Model
{
    protected $table = 'stock_reservations';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'product_id', 'location_id',
        'sale_id', 'sale_ulid', 'quantity', 'status',
        'expires_at', 'resolved_at', 'release_reason', 'actor_user_id',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'product_id' => 'integer',
        'location_id' => 'integer',
        'sale_id' => 'integer',
        'expires_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public const HELD = 'held';
    public const CONSUMED = 'consumed';
    public const RELEASED = 'released';

    public function product(): BelongsTo
    {
        return $this->belongsTo(MerchantProduct::class, 'product_id');
    }

    /** **وحجزٌ بلا أجلٍ أسوأ من غيابه** — انظر الهجرة. */
    public function isExpired(): bool
    {
        return $this->status === self::HELD
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }
}
