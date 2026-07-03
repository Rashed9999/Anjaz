<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class FAQCategory extends Model
{
    protected $table = 'faq_categories';

    protected $fillable = [
        'name',
        'status',
        'click_count'
    ];

    protected $casts = [
        'status' => 'integer',
        'click_count' => 'integer',
    ];

    public function ScopeActive(Builder $query): Builder
    {
        return $query->where('status', 1);
    }
}

