<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-ENTITLEMENTS-001 — قرارٌ لتاجرٍ بعينه: منحةٌ أو منع.
 *
 * **ومنحةٌ بلا أجلٍ تصير باقةً دائمةً مجّانيّة.** فتجربةُ أسبوعين تنتهي
 * وحدَها، ومن أراد الدوام تركه فارغاً عامداً — والفراغُ هنا قرارٌ لا سهو.
 */
class MerchantCapabilityOverride extends Model
{
    protected $table = 'merchant_capability_overrides';

    protected $fillable = [
        'merchant_user_id', 'capability_code', 'effect',
        'expires_at', 'reason', 'granted_by',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'expires_at' => 'datetime',
    ];

    public const GRANT = 'grant';
    public const REVOKE = 'revoke';

    /**
     * القراراتُ **السارية** لتاجر — والمنتهيةُ تُهمَل ولا تُحذف.
     *
     * وتُبقى للسجلّ: «فُتحت له تجربةً في رمضان» سؤالٌ يُسأل بعد شهور.
     *
     * @return array<string,string>  code => grant|revoke
     */
    public static function activeFor(int $merchantUserId): array
    {
        return self::where('merchant_user_id', $merchantUserId)
            ->where(fn ($q) => $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->pluck('effect', 'capability_code')->all();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
