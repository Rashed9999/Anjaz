<?php

namespace App\Models\Retail;

use App\Models\MerchantProduct;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * حركةُ مخزون — **مصدرُ الحقيقة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان المخزونُ رقماً يُكتب فوقه، فلا يُعرف **من أين نقص**: بيعٌ؟ هالك؟
 * تحويل؟ خطأُ إدخال؟ والجردُ يُخرج فرقاً بلا أثرٍ يُراجَع — فيُقرأ
 * سرقةً أو يُهمَل، وكلاهما خطأ.
 *
 * **والسببُ إلزاميّ في المخطّط لا في الشيفرة**: `enum` لا `string`، فلا
 * يمرّ صفٌّ بسببٍ فارغٍ ولو نسي كاتبُ النداء.
 */
class StockMovement extends Model
{
    protected $table = 'stock_movements';

    protected $fillable = [
        'uuid', 'merchant_user_id', 'product_id', 'location_id',
        'reason', 'quantity_delta', 'balance_after', 'unit_cost',
        'source_type', 'source_id', 'actor_user_id', 'note', 'zone_code',
    ];

    protected $casts = [
        'merchant_user_id' => 'integer',
        'product_id' => 'integer',
        'location_id' => 'integer',
        'quantity_delta' => 'decimal:3',
        'balance_after' => 'decimal:3',
        'unit_cost' => 'decimal:4',
        'source_id' => 'integer',
    ];

    public const REASONS = [
        'sale', 'sale_return', 'purchase_receive', 'purchase_return',
        'transfer_out', 'transfer_in',
        'count_adjustment', 'waste', 'opening_balance', 'correction',
    ];

    /** أسبابٌ **تُنقص** دائماً — تُفحص ضدّ الإشارة فلا تنقلب. */
    public const OUTBOUND = ['sale', 'transfer_out', 'waste', 'purchase_return'];

    /** وأسبابٌ **تزيد** دائماً. */
    public const INBOUND = ['sale_return', 'purchase_receive', 'transfer_in', 'opening_balance'];

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->uuid ??= (string) Str::uuid();
        });
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(MerchantProduct::class, 'product_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(MerchantLocation::class, 'location_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function reasonAr(): string
    {
        return match ($this->reason) {
            'sale' => 'بيع',
            'sale_return' => 'مرتجع عميل',
            'purchase_receive' => 'استلام مشتريات',
            'purchase_return' => 'مرتجع لمورد',
            'transfer_out' => 'تحويل صادر',
            'transfer_in' => 'تحويل وارد',
            'count_adjustment' => 'تسوية جرد',
            'waste' => 'هالك',
            'opening_balance' => 'رصيد افتتاحي',
            'correction' => 'تصحيح',
            default => $this->reason,
        };
    }
}
