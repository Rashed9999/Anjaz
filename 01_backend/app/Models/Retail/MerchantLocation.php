<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * موقعُ تخزين — متجرٌ أو مستودع.
 *
 * **والمستودعُ ليس متجراً.** يستلم ويخزّن ويجهّز تحويلاتٍ ولا يبيع.
 * وجعلُه فرعاً يُدخله في تقارير المبيعات بصفرٍ دائم، ويُفسد متوسّطَ
 * الفاتورة وترتيبَ الفروع.
 */
class MerchantLocation extends Model
{
    use SoftDeletes;

    protected $table = 'merchant_locations';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'kind', 'name', 'code',
        'branch_id', 'city', 'address', 'is_active', 'is_default', 'created_by',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'branch_id' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public const KINDS = ['store', 'warehouse'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->uuid ??= (string) Str::uuid();
        });
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(ProductStock::class, 'location_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'location_id');
    }

    public function isWarehouse(): bool
    {
        return $this->kind === 'warehouse';
    }
}
