<?php

namespace App\Models;

use App\Models\Retail\SaleLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-CASHIER-001 — سجل بيع واحد (نقد / أجل / أميال باي).
 *
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ١: **أسطرُه في `lines()`**، وعمودُ
 * `items` مرآةٌ باقيةٌ للتوافق لا مصدرَ حقيقة.
 */
class MerchantSale extends Model
{
    protected $table = 'merchant_sales';

    protected $fillable = [
        'sale_ulid', 'client_uuid', 'merchant_user_id', 'pos_user_id',
        'total_amount', 'discount_amount', 'promotion_id',
        'cash_amount', 'wallet_amount',
        'payment_method', 'status', 'items',
        'customer_name', 'customer_phone', 'paid_transaction_id',
        'settled_at', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'pos_user_id' => 'integer',
        'promotion_id' => 'integer',
        'items' => 'array',
        'settled_at' => 'datetime',
    ];

    public const METHODS = ['cash', 'credit', 'amial_pay', 'corporate', 'mixed'];
    public const STATUSES = ['completed', 'credit_unpaid', 'credit_paid', 'pending_payment'];

    /** أسطرُ المبيعة — **مصدرُ الحقيقة** منذ المرحلة ١. */
    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class, 'sale_id');
    }
}
