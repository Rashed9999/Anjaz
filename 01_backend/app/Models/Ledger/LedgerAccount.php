<?php

namespace App\Models\Ledger;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-LEDGER-001 (v1.7)
 *
 * حساب محاسبي في الـ ledger.
 *
 * **أنواع الحسابات:**
 *   - asset (أصول): user wallets, escrow holds — debit-normal
 *   - liability (التزامات): platform owes to users — credit-normal
 *   - revenue (إيرادات): platform fees — credit-normal
 *   - expense (مصروفات): refunds, costs — debit-normal
 */
class LedgerAccount extends Model
{
    protected $table = 'ledger_accounts';

    protected $fillable = [
        'account_code', 'account_type', 'name_ar',
        'owner_user_id', 'owner_type',
        'current_balance', 'normal_balance',
        'currency', 'zone_code', 'is_active',
    ];

    protected $casts = [
        'current_balance' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LedgerEntryLine::class, 'account_id');
    }

    public function scopeForUser(Builder $q, int $userId): Builder
    {
        return $q->where('owner_user_id', $userId);
    }

    /**
     * هل هذا الحساب debit-normal؟
     * (assets + expenses → الرصيد يزيد بالـ debit)
     */
    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }

    /**
     * احسب أثر القيد على الرصيد حسب طبيعة الحساب.
     */
    public function applyDirection(string $currentBalance, string $direction, string $amount): string
    {
        // debit-normal: debit يزيد، credit ينقص
        // credit-normal: credit يزيد، debit ينقص
        $isIncrease = ($this->normal_balance === 'debit' && $direction === 'debit')
            || ($this->normal_balance === 'credit' && $direction === 'credit');

        return $isIncrease
            ? bcadd($currentBalance, $amount, 4)
            : bcsub($currentBalance, $amount, 4);
    }
}
