<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class Setting extends Model
{
    use HasUuid;

    protected $table = 'addon_settings';

    protected $casts = [
        'live_values' => 'array',
        'test_values' => 'array',
        'is_active' => 'integer',
    ];

    protected $fillable = ['key_name', 'live_values', 'test_values', 'settings_type', 'mode', 'is_active', 'additional_data'];
}
