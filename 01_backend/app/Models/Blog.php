<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Blog extends Model
{
    protected $fillable = [
        'readable_id',
        'category_id',
        'writer',
        'title',
        'description',
        'image',
        'publish_date',
        'status',
        'is_draft',
        'draft_data',
        'click_count',
        'is_published',
    ];

    protected $casts = [
        'draft_data' => 'array',
        'status' => 'integer',
        'is_draft' => 'integer',
        'click_count' => 'integer',
        'is_published' => 'boolean'
    ];

    public function getImageFullPathAttribute(): string
    {
        $image = $this->image ?? null;
        $path = dynamicAsset(path: 'public/assets/admin/img/1920x400/img2.jpg');

        if (!is_null($image) && Storage::disk('public')->exists('blog/' . $image)) {
            $path = dynamicStorage(path: 'storage/app/public/blog/' . $image);
        }
        return $path;
    }

    public function getDraftImageFullPathAttribute(): string
    {
        $image = $this->draft_data['image'] ?? null;
        $path = dynamicAsset(path: 'public/assets/admin/img/1920x400/img2.jpg');

        if (!is_null($image) && Storage::disk('public')->exists('blog/' . $image)) {
            $path = dynamicStorage(path: 'storage/app/public/blog/' . $image);
        }
        return $path;
    }

    public function ScopeActive(Builder $query): Builder
    {
        return $query->where('status', 1);
    }

    public function blogCategory(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'category_id', 'id');
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $model): void {
            $lastReadableId = static::max('readable_id') ?? 100000;
            $model->readable_id = $lastReadableId + 1;

            // safer & clearer string formatting
            $slug = sprintf('%s-%d', $model->title, $model->readable_id);
            $model->slug = Str::slug($slug);
        });
    }
}
