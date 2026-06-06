<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WholesaleCustomer extends Model
{
    protected $table = 'wholesale_customers';
    protected $fillable = [
        'business_id', 'default_tier_id', 'full_name', 'company_name',
        'phone', 'email', 'city', 'address', 'tax_number',
        'credit_limit', 'current_balance', 'payment_terms_days',
        'total_purchases', 'last_purchase_date',
        'is_active', 'notes',
    ];
    protected $casts = [
        'business_id' => 'integer', 'default_tier_id' => 'integer',
        'credit_limit' => 'decimal:4', 'current_balance' => 'decimal:4',
        'total_purchases' => 'decimal:4',
        'payment_terms_days' => 'integer',
        'last_purchase_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function business(): BelongsTo { return $this->belongsTo(WholesaleBusiness::class, 'business_id'); }
    public function defaultTier(): BelongsTo { return $this->belongsTo(WholesalePriceTier::class, 'default_tier_id'); }
    public function invoices(): HasMany { return $this->hasMany(WholesaleInvoice::class, 'customer_id'); }
    public function collections(): HasMany { return $this->hasMany(WholesaleCollection::class, 'customer_id'); }

    /** الرصيد المتاح للائتمان (credit_limit - current_balance). */
    public function availableCredit(): float
    {
        return max(0, (float)$this->credit_limit - (float)$this->current_balance);
    }

    /** هل يستطيع شراء بقيمة معيّنة على الحساب؟ */
    public function canChargeCredit(float $amount): bool
    {
        if ((float)$this->credit_limit <= 0) return false; // نقد فقط
        return ((float)$this->current_balance + $amount) <= (float)$this->credit_limit;
    }
}
