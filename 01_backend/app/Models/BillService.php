<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillService extends Model
{
    protected $table = 'bill_services';
    protected $fillable = [
        'provider_id', 'code', 'name', 'display_name_ar',
        'service_type', 'icon_url', 'is_active',
        'requires_account_number', 'account_validation_rules',
    ];
    protected $casts = [
        'is_active' => 'boolean',
        'requires_account_number' => 'boolean',
        'account_validation_rules' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(BillProvider::class, 'provider_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(BillServiceProduct::class, 'service_id');
    }
}
