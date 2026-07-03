<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawRequest extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'request_status',
        'is_paid',
        'sender_note',
        'admin_note',
        'withdrawal_method_id',
        'withdrawal_method_fields',
    ];

    protected $casts = [
        'withdrawal_method_fields' => 'array',
    ];

    public function withdrawal_method(): BelongsTo
    {
        return $this->belongsTo(WithdrawalMethod::class, 'withdrawal_method_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
