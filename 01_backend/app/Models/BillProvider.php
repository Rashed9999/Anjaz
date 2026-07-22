<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-BILL-PAY-001
 */
class BillProvider extends Model
{
    protected $table = 'bill_providers';
    // AMIAL-SURFACE-002: طلبات المزوّد (للوحة الأدمن)
    public function orders(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BillPaymentOrder::class, 'provider_id');
    }

    protected $fillable = [
        'code', 'name', 'display_name_ar', 'integration_type',
        'endpoint_url', 'api_key_encrypted', 'config',
        'is_active', 'zone_code',
    ];
    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];
    protected $hidden = ['api_key_encrypted'];

    public function services(): HasMany
    {
        return $this->hasMany(BillService::class, 'provider_id');
    }
}

