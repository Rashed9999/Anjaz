<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GiftCardTransaction extends Model
{
    protected $table = 'gift_card_transactions';

    protected $fillable = [
        'gift_card_id', 'type', 'amount', 'balance_after', 'sale_ulid', 'note', 'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];
}
