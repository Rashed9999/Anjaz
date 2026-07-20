<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallmentContract extends Model
{
    protected $table = 'installment_contracts';

    protected $fillable = [
        'merchant_user_id', 'customer_user_id', 'guarantor_user_id', 'sale_ulid', 'item_name',
        'principal', 'down_payment', 'financed_amount', 'markup_amount', 'total_payable',
        'months', 'monthly_amount', 'paid_amount', 'late_fees_total', 'status',
        'started_at', 'completed_at', 'zone_code',
    ];

    protected $casts = [
        'principal' => 'decimal:2', 'down_payment' => 'decimal:2',
        'financed_amount' => 'decimal:2', 'markup_amount' => 'decimal:2',
        'total_payable' => 'decimal:2', 'monthly_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2', 'late_fees_total' => 'decimal:2',
        'months' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function schedules(): HasMany
    {
        return $this->hasMany(InstallmentSchedule::class, 'contract_id')->orderBy('seq');
    }
}
