<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AMIAL-REFACTOR-CORE-001 — REPLACE
 *
 * تغييرات عن الأصلي:
 *  - $casts: float:4 → decimal:4 (يقرأ من DECIMAL في DB، يحافظ على precision كـ string في PHP)
 *  - $guarded = [] → $fillable صريح (منع mass assignment)
 *  - إضافة pending_balance, held_balance, zone_code, version
 *  - إضافة scope `lockedFor` للقفل المُوحد
 *  - إضافة attribute mutator يضمن عدم تسرب رصيد سالب (defense in depth)
 *
 * مهم: التعديل المباشر على current_balance يجب أن يمر FinancialGuardService.
 * هذا الـ model لا يفرض ذلك (لا يمكن)، لكن FinancialGuardService::assertInTransaction
 * يضمن أن أي خصم يكون داخل DB::transaction مع lockForUpdate.
 */
class EMoney extends Model
{
    protected $table = 'e_money';

    // AMIAL-REFACTOR-CORE-001: $fillable بدل $guarded = []
    protected $fillable = [
        'user_id',
        'currency',
        'current_balance',
        'charge_earned',
        'pending_balance',
        'held_balance',
        'zone_code',
        'version',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'currency' => 'string',
        // AMIAL-REFACTOR-CORE-001: decimal:4 بدل float:4
        // Laravel يعيد القيمة كـ string — هذا متعمد ليُستهلك مع bcmath
        'current_balance' => 'decimal:4',
        'charge_earned' => 'decimal:4',
        'pending_balance' => 'decimal:4',
        'held_balance' => 'decimal:4',
        'zone_code' => 'string',
        'version' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * AMIAL-REFACTOR-CORE-001: scope موحّد للقراءة مع قفل.
     * استخدام:
     *   $wallet = EMoney::lockedFor($userId)->first();
     */
    public function scopeLockedFor(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId)->lockForUpdate();
    }

    // ═════════════════════════════════════════════════════════════════
    // AMIAL-MULTI-CURRENCY-002 — **محفظةٌ لكلّ عملة، وصمتٌ يعني الريال.**
    //
    // **الثمنُ الذي كان سيُدفع:** في المشروع **١٦٤ موضعاً** تستعلم
    // `e_money`، وكلُّها كُتبت حين كان للمستخدم محفظةٌ واحدة. فمجرّدُ
    // إضافة صفٍّ بالدولار يجعل `EMoney::where('user_id', $x)->first()`
    // تُرجع **أيَّ المحفظتين** — وعهدةُ الوكيل والتسويةُ والمصالحةُ
    // والحدودُ كلُّها تقرأ بهذه الصيغة.
    //
    // **ولا يمسكه مُصرِّفٌ ولا اختبار**: الصيغةُ سليمة، والصفُّ سليم،
    // والرقمُ يُقرأ — لكنّه من المحفظة الخطأ. ويظهر بعد أن يُنشئ أوّلُ
    // تاجرٍ محفظةَ دولار، أي في الإنتاج لا هنا.
    //
    // **فالافتراضُ يُثبَّت في النموذج لا في ١٦٤ نداءً**: نطاقٌ عامٌّ يقصر
    // كلَّ استعلامٍ على الريال، ومن أراد غيرَه **يقوله صراحةً**. فالشيفرةُ
    // القائمةُ تعني اليومَ ما كانت تعنيه أمس، حرفاً بحرف.
    // ═════════════════════════════════════════════════════════════════

    /** اسمُ النطاق — يُنزَع بالاسم نفسِه. */
    public const BASE_SCOPE = 'amial_base_currency';

    protected static function bootedCurrencyScope(): void
    {
        static::addGlobalScope(self::BASE_SCOPE, function (Builder $q) {
            $q->where($q->getModel()->getTable().'.currency', \App\Support\Money\Currencies::BASE);
        });
    }

    /**
     * محفظةُ عملةٍ بعينها. `EMoney::inCurrency('USD')->where('user_id', $x)`
     *
     * **ويُطبَّع الرمزُ فيرمي على المجهول** — فمحفظةٌ بعملةٍ لا نعرفها
     * تُنشأ ولا يجدها أحدٌ بعد ذلك، والمالُ فيها يختفي عن كلّ تقرير.
     */
    public function scopeInCurrency(Builder $query, string $code): Builder
    {
        return $query->withoutGlobalScope(self::BASE_SCOPE)
            ->where($query->getModel()->getTable().'.currency', \App\Support\Money\Currencies::normalize($code));
    }

    /**
     * كلُّ العملات — **للعرض والجرد لا للحساب.**
     *
     * فجمعُ أرصدةٍ بعملاتٍ مختلفةٍ في رقمٍ واحدٍ كذبةٌ حسابيّة: ١٠٠ دولارٍ
     * و١٠٠ ريالٍ ليسا ٢٠٠ من شيء. من ناداها يجمع بالعملة أو يحوّل بسعرٍ
     * مذكورِ المصدر.
     */
    public function scopeAnyCurrency(Builder $query): Builder
    {
        return $query->withoutGlobalScope(self::BASE_SCOPE);
    }

    /**
     * AMIAL-ZONE-001 (preparation): فلتر المحافظ ضمن zone معينة.
     */
    public function scopeInZone(Builder $query, string $zoneCode): Builder
    {
        return $query->where('zone_code', $zoneCode);
    }

    /**
     * Defense-in-depth: قبل save، نتحقق ألا يصبح أي رصيد سالباً.
     * هذا حارس إضافي — FinancialGuardService يجب أن يلتقطها قبل، لكن
     * لو تجاوزها كود قديم/مهمل، هذا يمنع الضرر.
     */
    protected static function booted(): void
    {
        self::bootedCurrencyScope();

        // **والعملةُ تُحرَس عند الحفظ**: رمزٌ خارج القائمة يُنشئ محفظةً لا
        // يجدها أحدٌ بعد ذلك — لا تقريرٌ ولا مصالحةٌ ولا شاشة.
        static::saving(function (EMoney $wallet) {
            \App\Support\Money\Currencies::normalize($wallet->currency ?? \App\Support\Money\Currencies::BASE);
        });

        static::saving(function (EMoney $wallet) {
            if ($wallet->current_balance < 0) {
                throw new \LogicException(
                    "AMIAL-GUARD: refusing to save EMoney#{$wallet->id} with negative current_balance ({$wallet->current_balance})"
                );
            }
            if ($wallet->pending_balance < 0) {
                throw new \LogicException(
                    "AMIAL-GUARD: refusing to save EMoney#{$wallet->id} with negative pending_balance"
                );
            }
            if ($wallet->held_balance < 0) {
                throw new \LogicException(
                    "AMIAL-GUARD: refusing to save EMoney#{$wallet->id} with negative held_balance"
                );
            }
        });
    }
}
