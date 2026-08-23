<?php

namespace App\Services\Reconciliation;

use App\Services\LedgerReportService;
use App\Models\Agent\AgentShift;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-RECON-NIGHTLY-001 — كلّ ليلة: يُحسب الرقمُ من مصدرين ويُقارَنان.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاثةُ أسئلة، وكلٌّ منها يقارن مصدرين مستقلَّين:**
 *
 *   ١) محافظُ العملاء  :  `e_money.current_balance`  ↔  مجموعُ سطور الدفتر
 *   ٢) الدفترُ نفسُه    :  مجموعُ كلّ مدين            ↔  مجموعُ كلّ دائن
 *   ٣) خزائنُ النقد     :  `cash_on_hand`            ↔  مجموعُ حركة النقد
 *
 * **ولا يُقارَن رقمٌ مخزَّنٌ برقمٍ مخزَّن** (القاعدة السادسة): طرفٌ يُحسب
 * من الحركة دائماً. ومقارنةُ `cash_on_hand` بعمودٍ آخر نُسخ عنه تُخرج
 * الفرق صفراً أبداً — فتُطمئن ولا تفحص.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والعمى يُعلَن، لا يُخفى ولا يُصرَخ به.**
 *
 * ستُّ خدماتٍ ما زالت لا تُرحّل إلى الدفتر — دَينٌ معلومٌ مقيَّدٌ في
 * `LedgerCoverageGuardTest::EXEMPT`. فلو صمتت المصالحة عنها لأعطت
 * طمأنينةً كاذبة، ولو صرخت لصارت تصرخ كلَّ ليلةٍ بلا سبب — **وإنذارٌ
 * يصرخ كلّ ليلةٍ يُصمَّت بعد أسبوع، ثمّ يصرخ يوماً بحقٍّ فلا يسمعه أحد.**
 *
 * فيُكتب ما لم يُفحص ولماذا، ويُقرأ التقرير بحدوده.
 */
class ReconciliationService
{
    public function __construct(
        private readonly LedgerReportService $reports,
        private readonly ReconciliationCaseService $cases,
    ) {}

    /**
     * تُجرى المصالحة كاملةً.
     *
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $started = microtime(true);

        $wallets = $this->wallets();
        $ledger  = $this->ledgerBalance();
        $tills   = $this->tills();
        // A run creates/updates cases; a dashboard GET must remain read-only.
        $this->cases->recordCashResults($tills['divergences'] ?? []);
        // **وانحرافُ العمود المخبّأ يُفتح له قضيّةٌ كسواه** — كان يُعرَض
        // في بطاقةٍ ويُنسى مع إغلاق الصفحة. (المحور ٢٢: «افتح Case».)
        $this->cases->recordLedgerDrift($ledger['drift'] ?? []);

        $diverged = $wallets['diverged'] > 0
            || $ledger['unbalanced'] > 0
            || bccomp($ledger['net'], '0', 4) !== 0
            || count($ledger['drift'] ?? []) > 0
            || $tills['diverged'] > 0;

        return [
            'status'      => $diverged ? 'diverged' : 'clean',
            'wallets'     => $wallets,
            'ledger'      => $ledger,
            'tills'       => $tills,
            'blind_spots' => $this->blindSpots(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // ١) محافظ العملاء مقابل الدفتر
    // ══════════════════════════════════════════════════════════════

    /**
     * **بلا حدٍّ للعدد.**
     *
     * `LedgerReportService::walletReconciliation()` تأخذ `limit = 200`
     * لأنّها بُنيت لشاشةٍ تُعرض. وشاشةٌ تعرض مئتين مقبولة؛ **ومصالحةٌ
     * تفحص مئتين وتسكت عن الباقي تكذب**: تقول «لا فرق» وهي لم تنظر.
     */
    public function wallets(): array
    {
        $count = (int) DB::table('e_money')->count();

        $r = $this->reports->walletReconciliation(max($count, 1));
        // الحالة تُفتح أو تتصعّد هنا، في التشغيل الليلي الذي اكتشف الفرق؛
        // لا في GET للوحة، ولا في زر «تحديث»، كي لا تكون القراءة فعلاً مالياً.
        $this->cases->recordWalletResults($r['rows'] ?? []);

        /**
         * **مفاتيحُ في الجذر لا تحت `summary`.**
         *
         * كتبتُ أوّلاً `$r['summary']['divergent']` — ومفتاحٌ غيرُ موجودٍ
         * يُعطي `null` فيصير `0` بعد التحويل. فكانت المصالحة تُبلغ «لا
         * فرق» **أبداً**، ولا خطأ في أيّ سجلّ.
         *
         * وهو `حارسٌ يكذب أسوأ من غيابه` في أخطر موضعٍ ممكن: مصالحةٌ
         * ماليّةٌ تقول «سليم» ولم تنظر. أمسكه اختبارٌ يزرع فرقاً معلوم
         * المقدار — ولو اكتفيتُ بـ«تعمل على قاعدةٍ سليمة» لمرّ.
         */
        return [
            'checked'  => (int) ($r['checked'] ?? count($r['rows'] ?? [])),
            'diverged' => (int) ($r['divergent'] ?? 0),
            'gap'      => (string) ($r['total_gap'] ?? '0'),
            'worst'    => array_slice(array_values(array_filter(
                $r['rows'] ?? [], fn ($x) => $x['diverged'] ?? false
            )), 0, 5),
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // ٢) الدفترُ متوازنٌ في ذاته
    // ══════════════════════════════════════════════════════════════

    /**
     * مدينُ النظام كلِّه يساوي دائنَه — **وإلّا فقيدٌ ناقصُ الطرف**.
     *
     * وهذا فحصٌ مستقلٌّ عن الأوّل: محافظُ العملاء قد تطابق الدفتر وهو
     * غيرُ متوازنٍ في ذاته، لو كان النقصُ في حسابٍ ليس محفظةَ عميل.
     */
    private function ledgerBalance(): array
    {
        $sums = DB::table('ledger_entry_lines')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='debit'  THEN amount ELSE 0 END),0) d")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END),0) c")
            ->first();

        $net = bcsub((string) ($sums->d ?? '0'), (string) ($sums->c ?? '0'), 4);

        return [
            'debit'      => (string) ($sums->d ?? '0'),
            'credit'     => (string) ($sums->c ?? '0'),
            'net'        => $net,
            'unbalanced' => count($this->reports->unbalancedEntries(50)),
            // AMIAL-LEDGER-DRIFT-CASE-001 — انحرافُ العمود المخبّأ عن سطوره.
            'drift'      => $this->accountDrift(),
        ];
    }

    /**
     * حساباتٌ عمودُها المخبّأ يخالف مجموعَ سطورها.
     *
     * **ويُحسب من السطور لا من العمود** — والقاعدةُ السادسة هي كلُّ
     * المسألة: مقارنةُ العمود بنفسه تُخرج صفراً دائماً.
     *
     * ولا حدَّ للعدد: **مصالحةٌ تفحص مئةً وتسكت عن الباقي تكذب.**
     *
     * @return array<int,array{account_id:int,account_code:string,stored:string,computed:string,drift:string}>
     */
    public function accountDrift(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('ledger_accounts')) {
            return [];
        }

        return DB::table('ledger_accounts as a')
            ->leftJoin('ledger_entry_lines as l', 'l.account_id', '=', 'a.id')
            ->groupBy('a.id', 'a.account_code', 'a.normal_balance', 'a.current_balance')
            ->selectRaw("a.id, a.account_code, a.normal_balance, a.current_balance,
                COALESCE(SUM(CASE WHEN l.direction = 'debit'  THEN l.amount ELSE 0 END), 0) AS d,
                COALESCE(SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE 0 END), 0) AS c")
            ->get()
            ->map(function ($r) {
                $d = number_format((float) $r->d, 4, '.', '');
                $c = number_format((float) $r->c, 4, '.', '');
                $computed = $r->normal_balance === 'debit'
                    ? bcsub($d, $c, 4) : bcsub($c, $d, 4);
                $stored = number_format((float) $r->current_balance, 4, '.', '');

                return [
                    'account_id' => (int) $r->id,
                    'account_code' => (string) $r->account_code,
                    'stored' => $stored,
                    'computed' => $computed,
                    'drift' => bcsub($computed, $stored, 4),
                ];
            })
            ->filter(fn ($r) => bccomp($r['drift'], '0', 4) !== 0)
            ->values()
            ->all();
    }

    // ══════════════════════════════════════════════════════════════
    // ٣) خزائنُ النقد الورقيّ
    // ══════════════════════════════════════════════════════════════

    /**
     * `cash_on_hand` مقابل **مجموعِ الحركة** لا مقابل عمودٍ آخر.
     *
     * والنقدُ الورقيّ هو ما يُسرق فعلاً في الصرافة — لا الإلكترونيّ.
     */
    public function tills(): array
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('agent_cash_tills')) {
            return ['checked' => 0, 'diverged' => 0, 'gap' => '0', 'worst' => [], 'divergences' => []];
        }

        // The branch safe and a teller drawer are separate physical places.
        // Mixing their movements made an open drawer look like a safe variance.
        $safeMovements = DB::table('agent_cash_movements')
            ->where('is_drawer', false)
            ->groupBy('branch_id')
            ->selectRaw("branch_id,
                COALESCE(SUM(CASE WHEN direction='in'  THEN amount ELSE 0 END),0)
              - COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) as net")
            ->pluck('net', 'branch_id');

        $checked = 0;
        $diverged = 0;
        $gap = '0';
        $divergences = [];

        foreach (DB::table('agent_cash_tills')->get() as $till) {
            $checked++;
            $held = (string) ($till->cash_on_hand ?? '0');
            $expected = (string) ($safeMovements[$till->branch_id] ?? '0');
            $delta = bcsub($held, $expected, 4);

            if (bccomp($delta, '0', 4) !== 0) {
                $diverged++;
                $gap = bcadd($gap, $delta, 4);
                $divergences[] = [
                    'kind' => 'branch_safe',
                    'dimension' => ['branch_id' => (int) $till->branch_id, 'till_id' => (int) $till->id],
                    'held' => $held, 'expected' => $expected, 'gap' => $delta,
                ];
            }
        }

        // Open teller drawers are reconciled against their own shift movements.
        // A closed shift has a separate counted-cash variance workflow.
        if (\Illuminate\Support\Facades\Schema::hasTable('agent_shifts')) {
            foreach (AgentShift::where('status', AgentShift::STATUS_OPEN)->get() as $shift) {
                $checked++;
                $held = (string) $shift->cash_on_hand;
                $expected = $shift->expectedCash();
                $delta = bcsub($held, $expected, 4);

                if (bccomp($delta, '0', 4) !== 0) {
                    $diverged++;
                    $gap = bcadd($gap, $delta, 4);
                    $divergences[] = [
                        'kind' => 'teller_drawer',
                        'dimension' => [
                            'branch_id' => (int) $shift->branch_id,
                            'shift_id' => (int) $shift->id,
                            'staff_id' => (int) $shift->staff_id,
                        ],
                        'held' => $held, 'expected' => $expected, 'gap' => $delta,
                    ];
                }
            }
        }

        usort($divergences, fn ($a, $b) => bccomp(
            ltrim($b['gap'], '-'), ltrim($a['gap'], '-'), 4
        ));

        return [
            'checked' => $checked, 'diverged' => $diverged,
            'gap' => $gap, 'worst' => array_slice($divergences, 0, 5),
            // The run consumes all findings; the dashboard may show only five.
            'divergences' => $divergences,
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // العمى المُعلَن
    // ══════════════════════════════════════════════════════════════

    /**
     * التدفّقاتُ التي نعرف أنّها لا تُرحّل بعد.
     *
     * **وتُقرأ من الإعدادات لا تُخمَّن** — والقائمةُ مربوطةٌ بديون الدفتر
     * في `LedgerCoverageGuardTest::EXEMPT`، ويحرس تطابقَهما
     * `ReconciliationBlindSpotTest`: من سدّد ديناً ولم يحذفه من هنا يبقى
     * التقريرُ يعتذر عن ثغرةٍ أُغلقت — **واعتذارٌ باطلٌ يُخفي فرقاً حقيقيّاً**.
     *
     * @return array<int,array{service:string,why:string}>
     */
    public function blindSpots(): array
    {
        return array_values(array_map(
            fn ($s, $w) => ['service' => $s, 'why' => $w],
            array_keys((array) config('amial.reconciliation.blind_spots', [])),
            array_values((array) config('amial.reconciliation.blind_spots', []))
        ));
    }
}
