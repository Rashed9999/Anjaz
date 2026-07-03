<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FAQ extends Model
{
    protected $table = 'faqs';

    protected $fillable = [
        'readable_id',
        'category_id',
        'question',
        'answer',
        'status',
    ];

    protected $casts = [
        'status' => 'integer'
    ];

    public function ScopeActive(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    public function faqCategory(): BelongsTo
    {
        return $this->belongsTo(FAQCategory::class, 'category_id', 'id');
    }

    protected static function boot(): void
    {
        parent::boot();

        self::creating(function ($model) {
            $lastReadableId = self::max('readable_id') ?? 100000;
            $model->readable_id = $lastReadableId + 1;
        });
    }
}
