<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class BlogCategory extends Model
{
    protected $fillable = [
        'name',
        'status',
        'click_count',
    ];

    protected $casts = [
        'status' => 'integer',
        'click_count' => 'integer',
    ];

    public function ScopeActive(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    protected static function boot(): void
    {
        parent::boot();

        self::creating(function ($model) {
            $model->slug = Str::slug($model->name);
        });
    }
}
