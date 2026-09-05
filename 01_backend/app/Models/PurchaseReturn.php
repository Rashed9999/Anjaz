<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-DAILY-MOVEMENT-001 — **مرتجعُ الشراء بأسطره.**
 *
 * تُردُّ بضاعةٌ إلى المورد: تالفةٌ أو منتهيةٌ أو زائدةٌ عن الأمر. وله
 * وجهان لا يُخلطان — **بضاعةٌ تنقص من الرفّ**، و**قيمةٌ إمّا تُخصَم من
 * دين المورد وإمّا تُستردّ نقداً**.
 *
 * **والبضاعةُ لا تتحرّك قبل الاعتماد** — كما في `SaleReturnService`
 * بالحرف: طلبٌ يُراجَع، ثمّ حركةُ مخزونٍ بسببها `purchase_return`.
 */
class PurchaseReturn extends Model
{
    protected $table = 'purchase_returns';

    /** يُخصَم من دين المورد. */
    public const SETTLE_CREDIT_NOTE = 'credit_note';

    /** استُرِدّ نقداً من المورد. */
    public const SETTLE_CASH_REFUND = 'cash_refund';

    public const SETTLEMENTS = [self::SETTLE_CREDIT_NOTE, self::SETTLE_CASH_REFUND];

    protected $fillable = [
        'return_ulid', 'merchant_user_id', 'supplier_id', 'purchase_order_id',
        'location_id', 'status', 'settlement_type', 'total_amount', 'reason',
        'created_by', 'approved_by', 'approved_at', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'supplier_id' => 'integer',
        'purchase_order_id' => 'integer',
        'location_id' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class, 'return_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }
}
