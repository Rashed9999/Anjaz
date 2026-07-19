<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-CORPORATE-ACCOUNTS-001 — حساب شركة (B2B) لدى تاجر.
 */
class CorporateAccount extends Model
{
    protected $fillable = [
        'merchant_user_id', 'account_code', 'company_name', 'contact_person',
        'contact_phone', 'tax_number', 'credit_limit', 'monthly_limit',
        'current_balance', 'status', 'last_settlement_at', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'credit_limit' => 'decimal:4',
        'monthly_limit' => 'decimal:4',
        'current_balance' => 'decimal:4',
        'last_settlement_at' => 'datetime',
    ];

    public function merchant(): BelongsTo { return $this->belongsTo(User::class, 'merchant_user_id'); }
    public function members(): HasMany { return $this->hasMany(CorporateAccountMember::class); }
    public function movements(): HasMany { return $this->hasMany(CorporateAccountMovement::class); }

    public function isActive(): bool { return $this->status === 'active'; }

    /** المتاح للشراء = الحدّ − المستحقّ الحالي. */
    public function availableCredit(): string
    {
        return \App\Services\MoneyService::sub((string) $this->credit_limit, (string) $this->current_balance);
    }
}
