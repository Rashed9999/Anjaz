<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Builder;

class Banner extends Model
{
    protected $appends = ['image_fullpath'];

    public function getImageFullPathAttribute(): string
    {
        $image = $this->image ?? null;
        $path = dynamicAsset(path: 'public/assets/admin/img/1920x400/img2.jpg');

        if (!is_null($image) && Storage::disk('public')->exists('banner/' . $image)) {
            $path = dynamicStorage(path: 'storage/app/public/banner/' . $image);
        }
        return $path;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '=', 1);
    }

    public function scopeAgentAndAll(Builder $query): Builder
    {
        return $query->where('receiver', 'agents')
            ->orWhere('receiver', 'all');
    }

    public function scopeCustomerAndAll(Builder $query): Builder
    {
        return $query->where('receiver', 'customers')
            ->orWhere('receiver', 'all');
    }
}
