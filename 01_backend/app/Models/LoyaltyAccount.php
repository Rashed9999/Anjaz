<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyAccount extends Model
{
    protected $table = 'loyalty_accounts';

    protected $fillable = [
        'merchant_user_id', 'customer_phone', 'customer_name',
        'points_balance', 'total_earned', 'total_redeemed', 'zone_code',
    ];

    protected $casts = [
        'points_balance' => 'decimal:2',
        'total_earned' => 'decimal:2',
        'total_redeemed' => 'decimal:2',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(LoyaltyMovement::class, 'loyalty_account_id');
    }
}
