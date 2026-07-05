<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-MAINT-001 — مفتاح ميزة (تشغيل/إيقاف من لوحة الصيانة).
 */
class FeatureFlag extends Model
{
    protected $fillable = [
        'key', 'name_ar', 'description_ar', 'category', 'money_source',
        'enabled', 'is_core', 'updated_by_admin_id', 'last_note', 'disabled_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'is_core' => 'boolean',
        'updated_by_admin_id' => 'integer',
        'disabled_at' => 'datetime',
    ];
}
