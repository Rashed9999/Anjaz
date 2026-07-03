<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestMoney extends Model
{
    protected $casts = [
        'from_user_id' => 'integer',
        'to_user_id' => 'integer',
        'type' => 'string',
        'amount' => 'float:4',
        //'note' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function withdrawal_method(): BelongsTo
    {
        return $this->belongsTo(WithdrawalMethod::class, 'withdrawal_method_id');
    }
}
