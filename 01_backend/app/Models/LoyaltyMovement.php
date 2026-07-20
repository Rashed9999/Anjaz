<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyMovement extends Model
{
    protected $table = 'loyalty_movements';

    protected $fillable = [
        'loyalty_account_id', 'type', 'points', 'balance_after',
        'sale_ulid', 'note', 'created_by',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];
}
