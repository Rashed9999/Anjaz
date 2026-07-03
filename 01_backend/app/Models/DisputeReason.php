<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class DisputeReason extends Model
{
    protected $fillable = [
        'reason',
        'user_type',
        'status',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '=', 1);
    }

    public function scopeCustomer(Builder $query): Builder
    {
        return $query->where('user_type', '=', 'customer');
    }

    public function scopeAgent(Builder $query): Builder
    {
        return $query->where('user_type', '=', 'agent');
    }
}
