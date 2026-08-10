<?php

namespace App\Models\Retail;

use App\Models\MerchantSale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٦ — مرتجعٌ **بأسطره**.
 *
 * وكان المرتجعُ مبلغاً حرّاً لا يُنسب إلى صنف: يُرتجَع «٥٠٠ ريال» من
 * فاتورةٍ فيها خمسةُ أصناف، فلا يُعرف ما عاد إلى الرفّ ولا ما نقص من
 * مبيعات أيّ صنف.
 *
 * **والمالُ ليس هنا**: `MerchantSaleRefundService` يملكه، وهذا يملك
 * البضاعة. وبابان للمال يُنتجان مبلغين.
 */
class SaleReturn extends Model
{
    protected $table = 'sale_returns';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'sale_id', 'sale_ulid', 'location_id',
        'total_amount', 'refund_method', 'status', 'created_by', 'approved_by',
        'approved_at', 'refund_ulid', 'reason', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'sale_id' => 'integer',
        'location_id' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class, 'return_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(MerchantSale::class, 'sale_id');
    }
}
