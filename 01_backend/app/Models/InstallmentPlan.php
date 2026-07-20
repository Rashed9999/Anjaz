<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InstallmentPlan extends Model
{
    protected $table = 'installment_plans';

    protected $fillable = [
        'merchant_user_id', 'is_active', 'min_amount', 'max_amount',
        'down_payment_percent', 'durations', 'markup_percent',
        'late_fee_percent', 'grace_days', 'require_kyc', 'require_guarantor', 'zone_code',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'down_payment_percent' => 'decimal:2',
        'durations' => 'array',
        'markup_percent' => 'decimal:2',
        'late_fee_percent' => 'decimal:2',
        'grace_days' => 'integer',
        'require_kyc' => 'boolean',
        'require_guarantor' => 'boolean',
    ];
}
