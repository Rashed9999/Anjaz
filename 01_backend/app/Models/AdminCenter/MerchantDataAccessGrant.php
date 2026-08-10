<?php

namespace App\Models\AdminCenter;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-MERCHANT-CENTER-001 — إذنُ اطّلاعٍ مؤقّتٌ على تفصيلٍ يخصّ التاجر.
 *
 * **وإذنٌ دائمٌ ليس إذناً — هو صلاحيّة.** فلكلّ إذنٍ أجلٌ وسببٌ وأثر.
 */
class MerchantDataAccessGrant extends Model
{
    protected $table = 'merchant_data_access_grants';

    protected $fillable = [
        'uuid', 'reference', 'merchant_user_id', 'admin_id', 'scope',
        'reason', 'ticket_ref', 'expires_at', 'revoked_at', 'revoked_by',
        'use_count', 'last_used_at',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'admin_id' => 'integer',
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public const SCOPE_OPERATIONAL = 'operational';
    public const SCOPE_FINANCIAL = 'financial_detail';

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    public function scopeAr(): string
    {
        return $this->scope === self::SCOPE_FINANCIAL
            ? 'تفصيل مالي' : 'تفصيل تشغيلي (أصناف ومخزون)';
    }

    /** الإذنُ السارِي لهذا المدقّق على هذا التاجر — أو `null`. */
    public static function activeFor(int $merchantUserId, int $adminId, string $scope): ?self
    {
        return self::where('merchant_user_id', $merchantUserId)
            ->where('admin_id', $adminId)
            ->where('scope', $scope)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('expires_at')
            ->first();
    }
}
