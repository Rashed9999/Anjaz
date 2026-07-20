<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GiftCard extends Model
{
    protected $table = 'gift_cards';

    protected $fillable = [
        'merchant_user_id', 'code', 'initial_balance', 'balance', 'status',
        'issued_to_phone', 'issued_to_name', 'expires_at', 'created_by', 'zone_code',
    ];

    protected $casts = [
        'initial_balance' => 'decimal:2',
        'balance' => 'decimal:2',
        'expires_at' => 'date',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(GiftCardTransaction::class, 'gift_card_id');
    }
}
