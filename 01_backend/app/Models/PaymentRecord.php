<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRecord extends Model
{
    use HasUuid;

    protected $casts = [
        'merchant_user_id' => 'integer',
        'user_id' => 'integer',
        'transaction_id' => 'string',
        'amount' => 'float:4',
        'callback' => 'string',
        'is_paid' => 'integer',
        'expired_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id')->where(['type' => CUSTOMER_TYPE]);
    }

    public function merchant_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merchant_user_id', 'id')->where(['type' => MERCHANT_TYPE]);
    }
}
