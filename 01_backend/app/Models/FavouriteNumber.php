<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FavouriteNumber extends Model
{
    protected $casts = [
        'user_id' => 'string',
        'type' => 'string',
        'name' => 'string',
        'phone' => 'string',
    ];

    protected $fillable = [
        'user_id',
        'type',
        'name',
        'phone'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
