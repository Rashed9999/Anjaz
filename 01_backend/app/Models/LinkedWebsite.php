<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class LinkedWebsite extends Model
{
    protected $casts = [
        'name' => 'string',
        'image' => 'string',
        'url' => 'string',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $appends = ['image_fullpath'];

    public function getImageFullPathAttribute(): string
    {
        $image = $this->image ?? null;
        $path = dynamicAsset(path: 'public/assets/admin/img/160x160/img2.jpg');

        if (!is_null($image) && Storage::disk('public')->exists('website/' . $image)) {
            $path = dynamicStorage(path: 'storage/app/public/website/' . $image);
        }
        return $path;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 1);
    }
}
