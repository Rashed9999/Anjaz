<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Notification extends Model
{
    protected $casts = [
        'title' => 'string',
        'description' => 'string',
        'image' => 'string',
        'status' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $appends = ['image_fullpath'];

    public function getImageFullPathAttribute(): string
    {
        $image = $this->image ?? null;
        $path = dynamicAsset(path: 'public/assets/admin/img/160x160/img2.jpg');

        if (!is_null($image) && Storage::disk('public')->exists('notification/' . $image)) {
            $path = dynamicStorage(path: 'storage/app/public/notification/' . $image);
        }
        return $path;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 1);
    }
}
