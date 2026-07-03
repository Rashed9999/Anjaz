<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WithdrawalMethod extends Model
{
    protected $fillable = [
        'method_name',
        'method_fields'
    ];

    protected $casts = [
        'method_fields' => 'array',
    ];
}
