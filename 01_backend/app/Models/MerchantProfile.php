<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-MERCHANT-RISK-001 (v2.9)
 *
 * ملف التاجر — تصنيف + توثيق + حدود.
 */
class MerchantProfile extends Model
{
    protected $table = 'merchant_profiles';

    protected $fillable = [
        'user_id', 'tier', 'risk_category',
        'business_type', 'declared_monthly_volume', 'declared_daily_customers',
        'verification_status',
        'daily_receive_limit', 'single_receive_limit', 'monthly_receive_limit',
        'can_transfer_out', 'requires_settlement_only',
        'verified_by_admin_id', 'verified_at', 'zone_code',
        // CRITICAL-001 — الاشتراكات + الميزات
        'subscription_plan', 'subscription_expires_at', 'subscription_notes', 'extra_features',
        // AMIAL-RECEIPT-SETTINGS-001 — إعدادات الفاتورة/الطباعة
        'receipt_settings',
    ];

    protected $casts = [
        'declared_monthly_volume' => 'decimal:4',
        'declared_daily_customers' => 'integer',
        'daily_receive_limit' => 'decimal:4',
        'single_receive_limit' => 'decimal:4',
        'monthly_receive_limit' => 'decimal:4',
        'can_transfer_out' => 'boolean',
        'requires_settlement_only' => 'boolean',
        'verified_at' => 'datetime',
        // CRITICAL-001
        'subscription_expires_at' => 'datetime',
        'extra_features' => 'array',
        'receipt_settings' => 'array',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class, 'user_id'); }

    public function scopeVerified(Builder $q): Builder
    {
        return $q->where('verification_status', 'verified');
    }

    public function isVerified(): bool { return $this->verification_status === 'verified'; }

    /**
     * حدود التصنيف الافتراضية (تُطبَّق عند تغيير الـ tier).
     */
    public static function defaultLimitsForTier(string $tier): array
    {
        return match ($tier) {
            'micro' => [
                'daily_receive_limit' => '200000',
                'single_receive_limit' => '50000',
                'monthly_receive_limit' => '2000000',
            ],
            'small' => [
                'daily_receive_limit' => '1000000',
                'single_receive_limit' => '200000',
                'monthly_receive_limit' => '10000000',
            ],
            'medium' => [
                'daily_receive_limit' => '5000000',
                'single_receive_limit' => '1000000',
                'monthly_receive_limit' => '50000000',
            ],
            'large' => [
                'daily_receive_limit' => '20000000',
                'single_receive_limit' => '5000000',
                'monthly_receive_limit' => '200000000',
            ],
            default => [
                'daily_receive_limit' => '200000',
                'single_receive_limit' => '50000',
                'monthly_receive_limit' => '2000000',
            ],
        };
    }
}
