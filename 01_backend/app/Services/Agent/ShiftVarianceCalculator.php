<?php

namespace App\Services\Agent;

use App\Models\Agent\AgentShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * AMIAL-TRUTH-001 — عجزُ الورديّات وفائضُها: حسابٌ واحد لا نسختان.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما كشفه تدقيق `amial-financial-truth`:**
 *
 * خدمتان تحسبان عجزَ يومِ الوكيل وفائضَه **بالكتلة نفسِها حرفاً بحرف**:
 *
 *   AgentSettlementEngine::dailySettlement()      ← ملفُّ الوكيل في اللوحة
 *   AgentDailySettlementService::computeDay()     ← لوحةُ اليوم، والبوّابة،
 *                                                    وبوت واتساب، والتذكير
 *
 * **وقِيس فوجدا متطابقين اليوم** — حتّى على حافّة اليوم. فليسا مصدرَي
 * حقيقةٍ متنازعَين بعد. **والخطرُ في الغد لا في اليوم**: من يُصلح إشارةً
 * أو يُغيّر شرطَ الإغلاق في واحدةٍ يترك الأخرى، **فيرى المشرفُ عجزاً في
 * «ملفّ الوكيل» وعجزاً آخرَ في «لوحة اليوم»** — ولا يعرف أيّهما الحقّ.
 *
 * وهذا ما تمنعه المهارةُ صراحةً: «Do not duplicate settlement
 * calculations».
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ حاسبةٌ لا وراثةٌ ولا سمة:**
 *
 * الخدمتان تختلفان في كلّ شيءٍ آخر — الأولى تقرأ المراكز والشبكة،
 * والثانية تُقفل اليومَ وترفعه وتقبله. **وما يشتركان فيه رقمٌ واحد**،
 * فيُستخرج وحدَه. (لا خدمةَ إله — والمهارةُ العشرون تقول: `NO GOD
 * SERVICE`.)
 *
 * **ولا تُغيَّر النتيجة**: هذه استخراجٌ لا إصلاح. والعقدُ الحيّ الذي
 * يُثبّت التطابق في `SettlementSingleTruthTest`.
 */
class ShiftVarianceCalculator
{
    /**
     * حدُّ اليوم — **واحدٌ للجميع**.
     *
     * كانت الأولى تكتب `'23:59:59'` نصّاً والثانية `endOfDay()`. وقِيس
     * فلم يفترقا على مخطّط MySQL الحاليّ (DATETIME بلا كسور الثانية).
     * **لكنّ عمودَ `datetime(6)` غداً يجعلهما يفترقان** — وورديّةٌ تُحسب
     * هنا وتسقط هناك فرقٌ يظهر مرّةً في الشهر فيُظنّ خطأَ إدخال.
     *
     * فيُثبَّت الحدُّ هنا مرّةً واحدة.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    public function dayWindow(string $date): array
    {
        return [
            Carbon::parse($date)->startOfDay(),
            Carbon::parse($date)->endOfDay(),
        ];
    }

    /**
     * ورديّاتُ اليوم لفروعٍ بعينها.
     *
     * @param  array<int,int>  $branchIds
     * @return Collection<int,AgentShift>
     */
    public function shiftsOfDay(array $branchIds, string $date): Collection
    {
        if ($branchIds === []) {
            return collect();
        }

        [$from, $to] = $this->dayWindow($date);

        return AgentShift::whereIn('branch_id', $branchIds)
            ->whereBetween('opened_at', [$from, $to])
            ->get();
    }

    /**
     * أرقامُ العجز والفائض من ورديّاتٍ محسوبةٍ سلفاً.
     *
     * ══════════════════════════════════════════════════════════════════
     * **والمغلقةُ وحدها تُحسب.** فورديّةٌ مفتوحةٌ لم يُعدّ صندوقُها بعد،
     * و`variance` فيها ليس عجزاً — هو صفرٌ لم يُملأ. وعدُّه عجزاً يُنتج
     * فرقاً وهميّاً كلَّ مساء. (القاعدة السابعة: «غير معروف» ليس صفراً.)
     *
     * **والمجموعُ بالقيمة المطلقة**: «عجزٌ قدرُه ١٢٥٠» أوضحُ من «‎-١٢٥٠»،
     * والإشارةُ محمولةٌ في اسم الحقل لا في الرقم.
     *
     * @param  Collection<int,AgentShift>  $shifts
     * @return array{shifts_total:int,shifts_closed:int,shifts_open:int,
     *               shortage_count:int,shortage_total:string,
     *               overage_count:int,overage_total:string}
     */
    public function summarise(Collection $shifts): array
    {
        $closed = $shifts->where('status', AgentShift::STATUS_CLOSED);
        $open = $shifts->where('status', AgentShift::STATUS_OPEN);

        $shortages = $closed->filter(
            static fn ($s): bool => bccomp((string) $s->variance, '0', 4) < 0);

        $overages = $closed->filter(
            static fn ($s): bool => bccomp((string) $s->variance, '0', 4) > 0);

        $absSum = static fn (Collection $c): string => (string) $c->reduce(
            static fn ($carry, $s) => bcadd($carry, ltrim((string) $s->variance, '-'), 4),
            '0',
        );

        return [
            'shifts_total' => $shifts->count(),
            'shifts_closed' => $closed->count(),
            'shifts_open' => $open->count(),

            'shortage_count' => $shortages->count(),
            'shortage_total' => $absSum($shortages),
            'overage_count' => $overages->count(),
            'overage_total' => $absSum($overages),
        ];
    }

    /**
     * الطريقُ القصير: فروعٌ وتاريخٌ ← الأرقام.
     *
     * @param  array<int,int>  $branchIds
     * @return array<string,int|string>
     */
    public function forDay(array $branchIds, string $date): array
    {
        return $this->summarise($this->shiftsOfDay($branchIds, $date));
    }
}
