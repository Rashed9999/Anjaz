<?php

namespace App\Services;

use App\Models\FxRate;
use App\Support\Money\Currencies;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * AMIAL-MULTI-CURRENCY-002 — **مصدرُ سعر الصرف الوحيد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * كلُّ تحويلٍ في المنصّة يمرّ من هنا، ويحمل معه **ثلاثةَ أشياءَ لا ينفصل
 * عنها**: الرقمَ، ومصدرَه، ولحظةَ سريانه. ومن أخذ الرقمَ وحدَه فقد أخذ
 * نصفَ الحقيقة.
 *
 * **ولا سعرَ افتراضيّ.** عملةٌ لم يُضبَط لها سعرٌ ترمي ولا تُقرأ «١»:
 * فسعرُ ١ للدولار مقابل الريال يجعل مئةَ دولارٍ مئةَ ريال — أي محوَ
 * ٩٩٫٨٪ من المال في سطرٍ واحدٍ لا يُخرج خطأً. (القاعدة السابعة: «غير
 * معروف» ليس واحداً كما ليس صفراً.)
 */
class FxRateService
{
    /**
     * السعرُ الساري في لحظةٍ ما — والافتراضُ الآن.
     *
     * @throws RuntimeException إن لم يُضبَط سعرٌ لتلك العملة بعد.
     */
    public function rateAt(string $currency, ?Carbon $at = null): FxRate
    {
        $cur = Currencies::normalize($currency);

        if (Currencies::isBase($cur)) {
            throw new RuntimeException('لا يُطلَب سعرُ الأساس — هو ١ بالتعريف.');
        }

        $at ??= Carbon::now();

        $rate = FxRate::where('currency', $cur)
            ->where('base_currency', Currencies::BASE)
            ->where('effective_at', '<=', $at)
            ->orderByDesc('effective_at')->orderByDesc('id')
            ->first();

        if (!$rate) {
            throw new RuntimeException(sprintf(
                'لا سعرَ صرفٍ مضبوطٌ لـ%s حتّى %s — تُضبَط الأسعارُ من لوحة الإدارة قبل القبض بها.',
                Currencies::nameAr($cur), $at->toDateTimeString()
            ));
        }

        return $rate;
    }

    /** «١ وحدةٍ من العملة = كم من الأساس» — نصّاً لا عائماً. */
    public function rateToBase(string $currency, ?Carbon $at = null): string
    {
        if (Currencies::isBase($currency)) {
            return '1';
        }

        return (string) $this->rateAt($currency, $at)->rate_to_base;
    }

    /** مكافئُ مبلغٍ بالعملة الأساس — بأربع منازلَ كسائر المال في المنصّة. */
    public function toBase(string $amount, string $currency, ?Carbon $at = null): string
    {
        return bcmul((string) $amount, $this->rateToBase($currency, $at), 4);
    }

    /** والعكس: كم من العملة يساوي مبلغاً بالأساس. */
    public function fromBase(string $baseAmount, string $currency, ?Carbon $at = null): string
    {
        $rate = $this->rateToBase($currency, $at);
        if (bccomp($rate, '0', 8) <= 0) {
            throw new RuntimeException('سعرُ صرفٍ غيرُ صالح');
        }

        return bcdiv((string) $baseAmount, $rate, 4);
    }

    /**
     * ضبطُ سعرٍ جديد — **إضافةٌ لا تعديل.**
     *
     * يظهر في : لوحة الإدارة ← الإعدادات ← أسعار الصرف · وفي التطبيق:
     *   نافذةُ التحويل بين المحافظ تعرض السعرَ ومصدرَه قبل التأكيد.
     */
    public function setRate(
        string $currency,
        string $rateToBase,
        string $source = FxRate::SOURCE_ADMIN,
        ?string $note = null,
        ?int $byUserId = null,
        ?Carbon $effectiveAt = null,
    ): FxRate {
        return FxRate::create([
            'currency' => Currencies::normalize($currency),
            'base_currency' => Currencies::BASE,
            'rate_to_base' => $rateToBase,
            'source' => $source,
            'source_note' => $note,
            'effective_at' => $effectiveAt ?? Carbon::now(),
            'created_by_user_id' => $byUserId,
        ]);
    }

    /**
     * الأسعارُ السارية الآن لكلّ عملةٍ مدعومة — ومعها **ما لم يُضبَط**.
     *
     * فعملةٌ بلا سعرٍ تُقال صراحةً ولا تُحذَف من القائمة: حذفُها يُقرأ «كلُّ
     * شيءٍ مضبوط» وهو «لم يُنظَر».
     *
     * @return array<string, array{rate: ?string, source: ?string, at: ?string, missing: bool}>
     */
    public function current(?Carbon $at = null): array
    {
        $out = [];
        foreach (Currencies::codes() as $code) {
            if (Currencies::isBase($code)) {
                $out[$code] = ['rate' => '1', 'source' => 'base', 'at' => null, 'missing' => false];
                continue;
            }

            try {
                $r = $this->rateAt($code, $at);
                $out[$code] = [
                    'rate' => (string) $r->rate_to_base,
                    'source' => (string) $r->source,
                    'at' => $r->effective_at?->toIso8601String(),
                    'missing' => false,
                ];
            } catch (RuntimeException) {
                $out[$code] = ['rate' => null, 'source' => null, 'at' => null, 'missing' => true];
            }
        }

        return $out;
    }
}
