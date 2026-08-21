<?php

namespace App\Services\Reconciliation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-LEDGER-FLOW-COVERAGE-001 — **«كلُّ ريال» تُقاس أو لا تُقال.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما تطلبه وثيقةُ المركز الماليّ نصّاً:**
 *
 *     شرط: `wallet-changing operations absent from financial feed = 0`
 *
 * والشاشةُ تكتب فوق التدفّق «حركة المال — كلُّ ريال: من أين أتى وإلى أين
 * ذهب». وهي **دعوى**، وكانت تُثبَت بقائمةٍ مكتوبةٍ باليد في الإعدادات
 * (`reconciliation.blind_spots`) يقول فيها مطوّرٌ ما لا يُرحَّل.
 *
 * **وقائمةٌ باليد تشيخ في الاتّجاهين، وقِيس ذلك مرّتين في يومٍ واحد:**
 *
 * | ما وُجد | الأثر |
 * |---|---|
 * | `SplitBillService` مُعلَنةٌ بقعةً **وهي تُرحّل منذ بُنيت** | التقريرُ يعتذر عن ثغرةٍ لا وجودَ لها، فيُبحث عن الفرق في غير موضعه |
 * | ثلاثةُ مساراتٍ سُدّت **وبقيت في القائمة** حتّى أمسكها حارس | «الدفترُ يرى ٧٠٪» بينما يرى أكثر |
 *
 * **والعلاجُ أن يُقرأ الجوابُ من البيانات لا من نيّة كاتبٍ سابق.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **كيف يُقاس — وما لا يُقاس، صراحةً:**
 *
 * لكلّ نوع معاملةٍ في النافذة: كم صفّاً حرّك مالاً، وكم منها يقابله قيدٌ
 * في الدفتر يشير إليه — بـ`source_id` مطابقاً `transaction_id` أو
 * `ref_trans_id`، أو بـ`metadata.transaction_id` لمسارٍ مفتاحُ قيده شيءٌ
 * آخر (‏كطلب السحب أو `tx_ulid` الصندوق).
 *
 * **وهذا يقيس الوصولَ لا الصحّة.** قيدٌ موجودٌ بمبلغٍ خاطئ يُعدّ مغطّىً
 * هنا — ومطابقةُ المحافظ (`ReconciliationService::wallets`) هي التي
 * تمسك المبلغ. فهما سؤالان: **«أرأى الدفترُ هذا؟»** و**«أرآه صحيحاً؟»**،
 * وخلطُهما يُنتج رقماً لا يجيب أيّاً منهما.
 */
class LedgerFlowCoverageService
{
    /**
     * أنواعُ معاملاتٍ لا تُحرّك محفظةً فلا يُنتظر لها قيد.
     *
     * وكلٌّ بسببه — **والسببُ ليس زينة**: من أضاف سطراً هنا بلا سببٍ
     * حقيقيٍّ فقد أخفى تدفّقاً، وتوقيعُه على ذلك في `git blame`.
     */
    public const NON_MONETARY = [
        'stock_count' => 'جردُ مخزون — كمّيّاتٌ لا مال',
        'stock_waste' => 'تالفُ مخزون — كمّيّاتٌ لا مال',
        'stock_transfer' => 'نقلُ مخزونٍ بين فروع — كمّيّاتٌ لا مال',
    ];

    /**
     * تغطيةُ التدفّقات في نافذةٍ زمنيّة.
     *
     * @return array{
     *   from:string, to:string, measurable:bool, reason:?string,
     *   rows:array<int,array{type:string,moves:int,covered:int,uncovered:int,
     *     amount_uncovered:string,state:string}>,
     *   totals:array{moves:int,covered:int,uncovered:int,amount_uncovered:string,percent:string}
     * }
     */
    public function coverage(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $out = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'measurable' => true,
            'reason' => null,
            'rows' => [],
            'totals' => [
                'moves' => 0, 'covered' => 0, 'uncovered' => 0,
                'amount_uncovered' => '0.0000', 'percent' => '0.0',
            ],
        ];

        // **الغيابُ يُقال ولا يُقرأ صفراً** (القاعدة السابعة). جداولُ دفترٍ
        // غيرُ مهاجَرةٍ تعني «لا نعرف»، لا «كلُّ شيءٍ مغطّى».
        foreach (['transactions', 'ledger_journal_entries', 'ledger_entry_lines'] as $table) {
            if (! Schema::hasTable($table)) {
                return array_merge($out, [
                    'measurable' => false,
                    'reason' => "جدول «{$table}» غيرُ موجود — التغطيةُ غيرُ قابلةٍ للقياس",
                ]);
            }
        }

        $moves = DB::table('transactions')
            ->whereBetween('created_at', [$from, $to])
            ->where(fn ($q) => $q->where('debit', '>', 0)->orWhere('credit', '>', 0))
            ->selectRaw('transaction_type, COUNT(*) AS n')
            ->selectRaw('COALESCE(SUM(debit), 0) + COALESCE(SUM(credit), 0) AS amount')
            ->groupBy('transaction_type')
            ->get();

        foreach ($moves as $row) {
            $type = (string) $row->transaction_type;

            if (isset(self::NON_MONETARY[$type])) {
                continue;
            }

            $covered = $this->coveredCount($type, $from, $to);
            $n = (int) $row->n;
            $uncovered = max(0, $n - $covered);

            $out['rows'][] = [
                'type' => $type,
                'moves' => $n,
                'covered' => $covered,
                'uncovered' => $uncovered,
                // النصيبُ التقريبيُّ غيرِ المغطّى — ويُقال إنّه تقريبٌ:
                // القيمةُ الدقيقةُ تحتاج ربطاً صفّاً بصفّ، وهو ما تفعله
                // مطابقةُ المحافظ.
                'amount_uncovered' => $n === 0 ? '0.0000' : bcmul(
                    bcdiv((string) $uncovered, (string) $n, 6),
                    number_format((float) $row->amount, 4, '.', ''), 4),
                'state' => match (true) {
                    $covered === 0 => 'غيرُ مرحَّل',
                    $uncovered > 0 => 'جزئيّ',
                    default => 'مرحَّل',
                },
            ];

            $out['totals']['moves'] += $n;
            $out['totals']['covered'] += $covered;
            $out['totals']['uncovered'] += $uncovered;
            $out['totals']['amount_uncovered'] = bcadd(
                $out['totals']['amount_uncovered'],
                end($out['rows'])['amount_uncovered'], 4);
        }

        usort($out['rows'], fn ($a, $b) => $b['uncovered'] <=> $a['uncovered']);

        $out['totals']['percent'] = $out['totals']['moves'] === 0
            ? '—'  // لا حركةَ في النافذة: ليست «١٠٠٪ تغطية»
            : number_format(
                $out['totals']['covered'] * 100 / $out['totals']['moves'], 1);

        return $out;
    }

    /**
     * كم صفّاً من هذا النوع يقابله قيدٌ يشير إليه.
     *
     * ويُبحث بثلاثة مفاتيح لأنّ المسارات لا تُرقّم قيودَها بطريقةٍ واحدة:
     * أكثرُها يضع `transaction_id` في `source_id`، وبعضُها يضع مرجعَ
     * عمليّته (‏رقمَ طلب السحب، أو `tx_ulid` الصندوق) ويحمل رقمَ المعاملة
     * في `metadata`. **ومطابقةٌ بمفتاحٍ واحدٍ تُخرج إنذاراً كاذباً** عن
     * مسارٍ مغطّىً — وإنذارٌ كاذبٌ يُفقد التقريرَ قيمتَه كلَّها.
     */
    private function coveredCount(string $type, \DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) DB::table('transactions as t')
            ->whereBetween('t.created_at', [$from, $to])
            ->where('t.transaction_type', $type)
            ->where(fn ($q) => $q->where('t.debit', '>', 0)->orWhere('t.credit', '>', 0))
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('ledger_journal_entries as e')
                    ->whereColumn('e.source_id', 't.transaction_id')
                    ->orWhereColumn('e.source_id', 't.ref_trans_id')
                    ->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(e.metadata, '$.transaction_id')) = t.transaction_id")
                    // **وساقُ العمليّة تُربط بأصلها لا بنفسها.** العمليّةُ
                    // الواحدة تكتب صفوفاً عدّة — العميلُ والوكيلُ وربحُ
                    // المنصّة — لكلٍّ `transaction_id` خاصٌّ به و`ref_trans_id`
                    // واحدٌ يجمعها. والقيدُ يغطّي العمليّة كلَّها لا ساقاً
                    // منها، فمطابقةٌ تنسى المرجعَ تقرأ «جزئيّ» عن مسارٍ
                    // مغطّىً تماماً — **وهو الإنذارُ الكاذبُ نفسُه بصورةٍ
                    // أخفّ، وأخطرُ لأنّه يبدو معقولاً.**
                    ->orWhereRaw(
                        "JSON_UNQUOTE(JSON_EXTRACT(e.metadata, '$.transaction_id')) = t.ref_trans_id");
            })
            ->count();
    }

    /**
     * التدفّقاتُ غيرُ المرحَّلة — **مقيسةً لا مُعلَنة**.
     *
     * @return array<int,array{type:string,moves:int,uncovered:int}>
     */
    public function uncoveredFlows(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $c = $this->coverage($from, $to);

        if (! $c['measurable']) {
            return [];
        }

        return array_values(array_map(
            fn ($r) => ['type' => $r['type'], 'moves' => $r['moves'], 'uncovered' => $r['uncovered']],
            array_filter($c['rows'], fn ($r) => $r['uncovered'] > 0)));
    }
}
