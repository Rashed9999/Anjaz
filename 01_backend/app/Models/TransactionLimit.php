<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionLimit extends Model
{
    protected $casts = [
        'user_id' => 'integer',
        'todays_count' => 'integer',
        'todays_amount' => 'float',
        'this_months_count' => 'integer',
        'this_months_amount' => 'float',
        'type' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $fillable = [
        'user_id',
        'todays_count',
        'todays_amount',
        'this_months_count',
        'this_months_amount',
        'type'
    ];
}
