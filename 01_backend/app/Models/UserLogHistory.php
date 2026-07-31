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
        // AMIAL-DEVICE-TRUST-001
        'is_trusted',
        'is_blocked',
        'blocked_at',
        'blocked_by_user_id',
        'block_reason',
        'last_seen_at',
        'app_version',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_trusted' => 'boolean',
        'is_blocked' => 'boolean',
        'blocked_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
