<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessSetting extends Model
{
    protected $casts = [
        'key' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];
}
