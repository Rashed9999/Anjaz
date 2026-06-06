<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-CUSTOMER-CREDIT-001 — حساب ائتماني لعميل عند تاجر.
 */
class CustomerCreditAccount extends Model
{
    protected $table = 'customer_credit_accounts';

    protected $fillable = [
        'merchant_user_id', 'customer_phone', 'customer_user_id', 'customer_name',
        'credit_limit', 'current_balance', 'classification',
        'last_payment_at', 'is_active', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'customer_user_id' => 'integer',
        'is_active' => 'boolean',
        'last_payment_at' => 'datetime',
    ];

    public const CLASSIFICATIONS = ['bronze', 'silver', 'gold'];

    public function movements(): HasMany
    {
        return $this->hasMany(CustomerCreditMovement::class, 'account_id');
    }

    /** نسبة استهلاك الحد (0-100+). 0 إن لم يكن للحد قيمة. */
    public function utilizationPercent(): float
    {
        $limit = (float) $this->credit_limit;
        if ($limit <= 0) return 0.0;
        return round(((float) $this->current_balance / $limit) * 100, 2);
    }

    public function isOverLimit(): bool
    {
        $limit = (float) $this->credit_limit;
        if ($limit <= 0) return false;
        return (float) $this->current_balance > $limit;
    }
}
