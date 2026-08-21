<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-DONATIONS-001 (v1.2)
 */
class CharityOrganization extends Model
{
    protected $table = 'charity_organizations';

    protected $fillable = [
        'org_ulid',
        'name_ar', 'name_en',
        'license_number', 'license_document_path',
        'description_ar',
        'contact_email', 'contact_phone', 'website_url', 'address_ar',
        'bank_name', 'bank_account_number', 'bank_account_holder', 'bank_swift',
        'logo_url', 'cover_image_url',
        'verification_status', 'verified_by_admin_id', 'verified_at',
        'rejection_reason', 'suspension_reason',
        'total_collected', 'total_campaigns', 'total_donors',
        'zone_code', 'is_active', 'created_by_admin_id',
    ];

    protected $casts = [
        'total_collected' => 'decimal:4',
        'total_campaigns' => 'integer',
        'total_donors' => 'integer',
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
        'verified_by_admin_id' => 'integer',
        'created_by_admin_id' => 'integer',
    ];

    // إخفاء معلومات حساسة بنكية من API responses public
    protected $hidden = [
        'bank_account_number',
        'bank_swift',
        'license_document_path',
    ];

    public function campaigns(): HasMany
    {
        return $this->hasMany(CharityCampaign::class, 'org_id');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'org_id');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(CharitySettlement::class, 'org_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_admin_id');
    }

    // Scopes
    public function scopeVerified(Builder $q): Builder
    {
        return $q->where('verification_status', 'verified')->where('is_active', true);
    }

    public function scopePendingVerification(Builder $q): Builder
    {
        return $q->where('verification_status', 'pending_verification');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified' && $this->is_active;
    }
}
