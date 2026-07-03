<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Purpose extends Model
{
    protected $appends = ['logo_fullpath'];

    public function getLogoFullPathAttribute(): string
    {
        $logo = $this->logo ?? null;
        $path = dynamicAsset(path: 'public/assets/admin/img/160x160/img1.jpg');

        if (!is_null($logo) && Storage::disk('public')->exists('purpose/' . $logo)) {
            $path = dynamicStorage(path: 'storage/app/public/purpose/' . $logo);
        }
        return $path;
    }
}
