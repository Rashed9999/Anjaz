<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HelpTopic extends Model
{
    protected $casts = [

        'ranking' => 'integer',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    protected $fillable = [
        'question',
        'answer',
        'status',
        'ranking',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 1);
    }
}
