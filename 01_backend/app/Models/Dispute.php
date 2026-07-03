<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    protected $casts = [
        'sender_id' => 'integer',
        'disputed_user_id' => 'integer',
        'sender_type' => 'string',
        'amount' => 'float',
        'transaction_id' => 'string',
        'sending_time' => 'datetime',
        'status' => 'string',
        'trx_id' => 'string',
        'report_reason' => 'array',
    ];

    protected $fillable = [
        'sender_id',
        'disputed_user_id',
        'sender_type',
        'amount',
        'transaction_id',
        'sending_time',
        'report_reason',
        'comment',
        'denied_note',
        'status',
        'trx_id',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
