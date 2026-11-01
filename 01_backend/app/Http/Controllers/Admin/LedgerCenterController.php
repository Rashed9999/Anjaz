<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LedgerReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-LEDGER-CENTER-001 — مركز الدفتر (الفصل ١٧).
 *
 * أُصلح الدفتر بالكامل في هذا المشروع ولم تكن تقرؤه شاشة. وميزان المراجعة
 * أوّل ما يطلبه المدقّق أو المنظّم — ولم يكن يُخرجه النظام إطلاقاً.
 */
class LedgerCenterController extends Controller
{
    public function __construct(
        private readonly LedgerReportService $reports,
    ) {
    }

    public function page()
    {
        return view('admin-views.amial.ledger.index');
    }

    public function trialBalance(Request $request): JsonResponse
    {
        return $this->ok($this->reports->trialBalance(
            $request->query('from'), $request->query('to'),
        ));
    }

    public function accounts(Request $request): JsonResponse
    {
        // AMIAL-CHART-IDENTITY-001 — الدليلُ صار مجموعاً بفئاته ومالكيه.
        // ويُردّ مسطوحاً أيضاً في `items` **لئلّا ينكسر مستهلكٌ قائم**:
        // تغييرُ شكل ردٍّ تقرؤه شاشةٌ يُفرّغها بلا خطأٍ في أيّ سجلّ.
        $chart = $this->reports->chartOfAccounts($request->query('type'));

        $flat = [];

        foreach ($chart['groups'] as $g) {
            foreach ($g['subgroups'] as $s) {
                foreach ($s['accounts'] as $a) {
                    $flat[] = $a;
                }
            }
        }

        return $this->ok($chart + ['items' => $flat]);
    }

    public function statement(Request $request, int $id): JsonResponse
    {
        return $this->ok($this->reports->accountStatement(
            $id, $request->query('from'), $request->query('to'),
        ));
    }

    public function reconciliation(): JsonResponse
    {
        return $this->ok($this->reports->walletReconciliation());
    }

    /**
     * AMIAL-LEDGER-FLOW-COVERAGE-001 — **تغطيةُ التدفّقات، مقيسةً.**
     *
     * تجيب سؤالَ الوثيقة: هل يرى الدفترُ **كلَّ** ما حرّك محفظةً؟ والجوابُ
     * يُقرأ من البيانات لا من قائمةٍ مكتوبةٍ باليد تشيخ في الاتّجاهين.
     *
     * والنافذةُ ثلاثون يوماً افتراضاً: نافذةٌ أقصرُ تُخفي تدفّقاً موسميّاً
     * (‏كالتسوية الشهريّة)، وأطولُ تُبطئ الصفحة بلا فائدةٍ تشغيليّة.
     */
    public function flowCoverage(Request $request): JsonResponse
    {
        $days = max(1, min(180, (int) $request->query('days', 30)));

        return $this->ok(
            app(\App\Services\Reconciliation\LedgerFlowCoverageService::class)
                ->coverage(now()->subDays($days), now())
        );
    }

    /** القضايا التي أنشأها التشغيل الليلي؛ القراءة لا تنشئ ولا تصلح فرقاً. */
    public function reconciliationCases(Request $request): JsonResponse
    {
        return $this->ok([
            'items' => app(\App\Services\Reconciliation\ReconciliationCaseService::class)
                ->recent((int) $request->query('limit', 100)),
        ]);
    }

    /**
     * AMIAL-RECON-NIGHTLY-001 — تاريخُ المصالحات الليليّة.
     *
     * **والقاعدة الثانية عشرة هي سببُ وجود هذه الدالّة:** بُني الجدول
     * `reconciliation_runs` ولم يكن يُقرأ من أيّ مكان. ومصالحةٌ لا يقرؤها
     * أحدٌ هي `مبنيٌّ ولا يُوصَل إليه` في أخطر موضع.
     *
     * **والسلسلةُ هي المقصود لا الليلةُ الأخيرة:** رقمٌ واحدٌ لا يقول إن
     * كان الانحراف بدأ اليوم أم يكبر منذ أسبوع. ويقول الغيابُ نفسُه شيئاً:
     * ليلةٌ بلا صفٍّ تعني أنّ المهمّة لم تعمل — لا أنّ الحساب سليم.
     */
    public function reconciliationRuns(Request $request): JsonResponse
    {
        $rows = DB::table('reconciliation_runs')
            ->orderByDesc('ran_at')
            ->limit(min((int) $request->get('limit', 30), 180))
            ->get()
            ->map(function ($r) {
                $r->blind_spots = json_decode((string) $r->blind_spots, true) ?: [];

                return $r;
            });

        $last = $rows->first();

        return $this->ok([
            'rows' => $rows,

            // **متى فُحص آخرَ مرّة** — فصمتُ الإنذار وحده لا يكفي دليلاً.
            'last_run_at'  => $last->ran_at ?? null,
            'stale'        => !$last || \Illuminate\Support\Carbon::parse($last->ran_at)->lt(now()->subHours(30)),
            'blind_spots'  => app(\App\Services\Reconciliation\ReconciliationService::class)->blindSpots(),
        ]);
    }

    public function entries(Request $request): JsonResponse
    {
        return $this->ok([
            'items' => $this->reports->searchEntries($request->only([
                // `idempotency_key` — الوصلةُ من حركةٍ ماليّةٍ إلى قيدها،
                // ليعمل التنقّلُ من تقرير الأرباح إلى الدفتر (AMIAL-FEE-TRUTH-019).
                'ulid', 'source_type', 'from', 'to', 'min_amount', 'idempotency_key',
                // AMIAL-LEDGER-SEARCH-001 — **المحقّقُ لا يبدأ من رقم
                // القيد**، بل ممّا في يده: رقمِ معاملةٍ يشتكي منها عميل،
                // أو هاتفٍ على شاشة الدعم، أو معرّفِ مستخدم.
                'source_id', 'phone', 'user_id', 'max_amount', 'status',
            ])),
            'source_types' => $this->reports->sourceTypes(),
        ]);
    }

    private function ok(array $meta): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => 'OK', 'message' => 'OK',
            'errors' => (object) [], 'meta' => $meta,
        ]);
    }
}
