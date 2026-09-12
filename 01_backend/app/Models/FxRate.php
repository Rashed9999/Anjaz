<?php

namespace App\Models;

use App\Support\Money\Currencies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-MULTI-CURRENCY-002 — **سعرُ صرفٍ لا يُعدَّل ولا يُحذَف.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كان السعرُ عموداً واحداً في `merchant_currencies` يكتبه التاجرُ بيده،
 * **بلا مصدرٍ ولا طابعٍ زمنيّ ولا تاريخ**. فتغييرُه اليومَ كان يُعيد كتابةَ
 * مكافئِ فاتورةِ الأمس على الورقة نفسِها.
 *
 * **وهذا العطلُ وقع في المشروع من قبل بصورةٍ أفدح**: `PLAN_PRICES_SAR`
 * بالريال السعوديّ عُرضت «ر.ي» — رقمٌ صحيحٌ بعملةٍ كاذبة، والفرقُ سبعون
 * ضعفاً. والدرسُ المكتوب حينها: **«التحويلُ يحتاج سعرَ صرفٍ ومصدرَه
 * وطابعَه الزمنيّ — وثلاثتُها غيرُ موجودة، فالتحويلُ الصامتُ يستبدل كذبةً
 * بأطولَ منها عمراً.»**
 *
 * فصار الجدولُ **مُلحَقاً فقط**: سعرٌ جديدٌ صفٌّ جديد، والقديمُ يبقى ليُقرأ
 * به ما وقع في زمنه.
 */
class FxRate extends Model
{
    protected $table = 'fx_rates';

    protected $fillable = [
        'currency', 'base_currency', 'rate_to_base',
        'source', 'source_note', 'effective_at', 'created_by_user_id',
    ];

    protected $casts = [
        'rate_to_base' => 'decimal:8',
        'effective_at' => 'datetime',
        'created_by_user_id' => 'integer',
    ];

    public const SOURCE_ADMIN = 'manual_admin';
    public const SOURCE_SEED = 'initial_seed';

    protected static function booted(): void
    {
        // **مُلحَقٌ فقط** — التعديلُ والحذفُ يمحوان تاريخاً يُدقَّق به.
        static::updating(function () {
            throw new \LogicException(
                'AMIAL-GUARD: سعرُ الصرف لا يُعدَّل — أضف سعراً جديداً بتاريخِ سريانه.'
            );
        });
        static::deleting(function () {
            throw new \LogicException(
                'AMIAL-GUARD: سعرُ الصرف لا يُحذَف — به تُقرأ معاملاتُ زمنه.'
            );
        });

        static::creating(function (FxRate $r) {
            $r->currency = Currencies::normalize($r->currency);
            $r->base_currency = Currencies::normalize($r->base_currency ?: Currencies::BASE);

            if ($r->currency === $r->base_currency) {
                throw new \LogicException('لا سعرَ للأساس مقابل نفسِه — هو ١ بالتعريف.');
            }
            if (bccomp((string) $r->rate_to_base, '0', 8) <= 0) {
                throw new \LogicException('سعرُ الصرف يجب أن يكون موجباً.');
            }
            if (trim((string) $r->source) === '') {
                throw new \LogicException('سعرٌ بلا مصدرٍ لا يُدقَّق — المصدرُ إلزاميّ.');
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
