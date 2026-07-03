<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $casts = [
        'country' => 'string',
        'currency_code' => 'string',
        'currency_symbol' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
}
