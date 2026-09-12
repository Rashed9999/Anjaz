<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-MULTI-CURRENCY-001 — عملة تاجر بسعر صرف مقابل الأساس (ر.ي).
 */
class MerchantCurrency extends Model
{
    protected $fillable = [
        // AMIAL-MULTI-CURRENCY-002: `accepts_payments` هو ما يقرأه القبضُ الآن.
        // و`rate_to_base` بقي للصفوف القديمة **ولا يُقرأ في تسعير**: السعرُ
        // صار مركزيّاً في `fx_rates` بمصدرٍ وطابعٍ زمنيّ. ومصدران للسعر
        // يعنيان رقمين مختلفين على الورقة الواحدة.
        'merchant_user_id', 'code', 'name', 'symbol', 'rate_to_base',
        'is_active', 'accepts_payments',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'rate_to_base' => 'decimal:6',
        'is_active' => 'boolean',
        'accepts_payments' => 'boolean',
    ];
}
