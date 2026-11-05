<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-CUSTOMER-CREDIT-001 — قيد على حساب عميل ائتماني (append-only).
 */
class CustomerCreditMovement extends Model
{
    protected $table = 'customer_credit_movements';

    protected $fillable = [
        'movement_ulid', 'account_id', 'type', 'amount', 'balance_after',
        'due_date', 'reference_type', 'reference_id', 'reference_number',
        'sale_movement_ulid',
        'note', 'created_by_user_id', 'zone_code',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'due_date' => 'date',
        'created_by_user_id' => 'integer',
    ];

    public const TYPES = ['sale', 'payment', 'return', 'adjustment'];

    /**
     * AMIAL-BUSINESS-CREDIT-001 — بعد تسليم الآجل لأميال أعمال لا يتحرّك هنا
     * أي رصيد دين. الحارس على النموذج لا على الخدمة، فيمرّ به كل مسار.
     */
    protected static function booted(): void
    {
        static::creating(function (self $movement) {
            app(\App\Services\BusinessGateway\CreditHandoverService::class)->assertOpen(
                CustomerCreditAccount::whereKey($movement->account_id)->value('merchant_user_id')
            );
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditAccount::class, 'account_id');
    }
}
