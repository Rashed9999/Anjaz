<?php

namespace App\Models\Retail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ٥ — جردٌ **باعتماد**.
 *
 * ومن عدّ ليس من يعتمد، وإلّا كان الجردُ باباً خلفيّاً لتسوية النقص:
 * يأخذ الموظّفُ عشراً ويكتب في الجرد أنّه وجد ناقصاً عشراً فيُقفَل الفرق.
 */
class StockCount extends Model
{
    protected $table = 'stock_counts';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'location_id', 'code', 'kind', 'status',
        'scope_category_id', 'started_by', 'approved_by',
        'started_at', 'counted_at', 'approved_at', 'note', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'location_id' => 'integer',
        'scope_category_id' => 'integer',
        'started_at' => 'datetime',
        'counted_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public const DRAFT = 'draft';
    public const COUNTING = 'counting';
    public const REVIEW = 'review';
    public const APPROVED = 'approved';
    public const CANCELLED = 'cancelled';

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class, 'count_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(MerchantLocation::class, 'location_id');
    }

    public function kindAr(): string
    {
        return match ($this->kind) {
            'cycle' => 'جرد دوري',
            'spot' => 'جرد موضعي',
            default => 'جرد كامل',
        };
    }

    public function statusAr(): string
    {
        return match ($this->status) {
            self::DRAFT => 'مسودّة',
            self::COUNTING => 'جارٍ العدّ',
            self::REVIEW => 'قيد المراجعة',
            self::APPROVED => 'معتمَد',
            default => 'ملغى',
        };
    }
}
