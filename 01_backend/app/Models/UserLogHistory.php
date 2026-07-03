<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserLogHistory extends Model
{
    protected $fillable = [
        'ip_address',
        'device_id',
        'browser',
        'os',
        'device_model',
        'user_id',
        'is_active',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
