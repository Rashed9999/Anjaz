<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FuelCompanyAccount extends Model
{
    protected $table = 'fuel_company_accounts';

    protected $fillable = [
        'merchant_user_id', 'company_name', 'contact_person', 'contact_phone',
        'tax_number', 'credit_limit', 'current_balance', 'monthly_limit',
        'last_payment_at', 'is_active', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'credit_limit' => 'decimal:4',
        'current_balance' => 'decimal:4',
        'monthly_limit' => 'decimal:4',
        'last_payment_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id');
    }

    public function isOverLimit(): bool
    {
        $limit = (float) $this->credit_limit;
        if ($limit <= 0) return false;
        return (float) $this->current_balance > $limit;
    }
}
