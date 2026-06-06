<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmialNotification extends Model
{
    protected $table = 'amial_notifications';

    protected $fillable = [
        'user_id', 'type', 'title', 'body',
        'icon', 'action_url', 'data', 'read_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'data' => 'array',
        'read_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
