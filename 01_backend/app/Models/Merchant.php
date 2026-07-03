<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Merchant extends Model
{
    protected $table = 'merchants';

    protected $casts = [
        'user_id' => 'integer',
        'store_name' => 'string',
        'callback' => 'string',
        'logo' => 'string',
        'address' => 'string',
        'bin' => 'string',
        'public_key' => 'string',
        'secret_key' => 'string',
        'merchant_number' => 'string',
    ];

    protected $appends = ['logo_fullpath'];

    public function getLogoFullPathAttribute(): string
    {
        $logo = $this->logo ?? null;
        $path = dynamicAsset(path: 'public/assets/admin/img/400x400/img2.jpg');

        if (!is_null($logo) && Storage::disk('public')->exists('merchant/' . $logo)) {
            $path = dynamicStorage(path: 'storage/app/public/merchant/' . $logo);
        }
        return $path;
    }

    public function merchant_user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
