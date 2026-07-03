<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneVerification extends Model
{
    protected $casts = [
        'phone' => 'string',
        'otp' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
