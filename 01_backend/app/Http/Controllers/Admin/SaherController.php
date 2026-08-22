<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Saher\Collectors\GuardCoverageCollector;
use App\Saher\Findings\FindingStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SAHER-FOUNDATION-008 — **شاشةُ ساهر: ما يحتاج تدخّلاً الآن.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * §22 من المخطّط: «الأولوية ليست الأرقام بل قسم: **أهم ما يحتاج تدخل
 * الآن**».
 *
 * **وهذا ليس ذوقاً في التصميم.** لوحةٌ تبدأ بأربعة أرقامٍ كبيرة تُعوّد
 * القارئَ أن يقرأ الرقمَ ولا يفتح شيئاً. وقد وقع هذا في المشروع: لافتةُ
 * «كُسرت السلسلة في ٤٢ موضعاً» على صفوفٍ لم يمسّها أحد **عوّدت القارئَ
 * أن يتجاهلها يومَ تصدق**.
 *
 * فالصفحةُ تبدأ بالقائمة المرتَّبة بالخطر، والأرقامُ تحتها.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وصحّةُ ساهر نفسِه في رأس الصفحة.** (§35)
 *
 * لوحةٌ خضراءُ فوق راصدٍ ميّتٍ أسوأ من حمراء — والدرسُ مكتوبٌ في
 * `CLAUDE.md` عن جدول النبض الفارغ. فإن لم تجرِ جولةٌ منذ يومٍ تقول
 * الصفحةُ «بائت» ولا تعرض صفراً مطمئنّاً.
 */
class SaherController extends Controller
{
    public function index(Request $request)
    {
        $sources = DB::table('saher_sources')->orderBy('code')->get()
            ->map(function ($s) {
                // **«غير معروف» ليس صفراً** — والبياتُ يُحسب لا يُفترَض.
                $s->is_stale = $s->last_success_at !== null
                    && now()->diffInMinutes($s->last_success_at) > $s->stale_after_minutes;

                $s->display_health = $s->last_success_at === null
                    ? 'NOT_CONFIGURED'
                    : ($s->is_stale ? 'STALE' : $s->health);

                return $s;
            });

        $q = DB::table('saher_findings')
            ->whereNotIn('status', ['RESOLVED', 'FALSE_POSITIVE', 'SUPPRESSED']);

        if ($request->filled('severity')) {
            $q->where('severity', $request->query('severity'));
        }

        if ($request->filled('category')) {
            $q->where('category', $request->query('category'));
        }

        if ($request->filled('status')) {
            $q->where('status', $request->query('status'));
        }

        $findings = (clone $q)->orderByDesc('risk_score')
            ->orderByDesc('severity')->limit(200)->get();

        // **العودةُ أولى بالنظر من الجديد** — إصلاحٌ ناقصٌ أو انحدار.
        $reopened = (clone $q)->where('status', 'REOPENED')->count();

        $byseverity = (clone $q)->select('severity', DB::raw('count(*) as n'))
            ->groupBy('severity')->pluck('n', 'severity')->all();

        $runs = DB::table('saher_scan_runs')
            ->orderByDesc('started_at')->limit(10)->get();

        return view('admin-views.amial.saher.index', [
            'sources' => $sources,
            'findings' => $findings,
            'severityCounts' => $byseverity,
            'reopened' => $reopened,
            'runs' => $runs,
            'canSeeEvidence' => (bool) $request->user()
                ?->hasPlatformPermission('saher.evidence.view'),
            'filters' => $request->only(['severity', 'category', 'status']),
        ]);
    }

    /**
     * تفصيلُ اكتشاف — **والدليلُ خلف صلاحيّته**.
     */
    public function show(Request $request, int $id)
    {
        $finding = DB::table('saher_findings')->where('id', $id)->first();

        abort_if($finding === null, 404);

        $canSeeEvidence = (bool) $request->user()
            ?->hasPlatformPermission('saher.evidence.view');

        return view('admin-views.amial.saher.show', [
            'finding' => $finding,
            'canSeeEvidence' => $canSeeEvidence,
            'evidence' => $canSeeEvidence
                ? DB::table('saher_finding_evidence')->where('finding_id', $id)->get()
                : collect(),
            'events' => DB::table('saher_finding_events')
                ->where('finding_id', $id)->orderByDesc('occurred_at')->get(),
        ]);
    }

    /**
     * تشغيلُ جولةٍ يدويّاً — **وفشلُها يُقال، ولا يُقرأ صفراً**.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ويشغّل الجوامعَ كلَّها لا واحداً منها.**
     *
     * كان يشغّل `guards` وحدَه، وجامعا `gate` و`data_truth` **بلا زرٍّ
     * واحدٍ يبلغهما** — يُشغَّلان بأمرٍ في الطرفيّة لا غير. وهو نمطُ
     * العطل الأكثرُ تكراراً في هذا المشروع: مبنيٌّ ولا يُوصَل إليه،
     * واقعاً على الأداة التي بُنيت لتمسكه.
     *
     * **وكلُّ جامعٍ يُحاسَب وحدَه**: سقوطُ واحدٍ لا يُسقط الباقين، ولا
     * يُبتلَع في رسالةٍ واحدةٍ تقول «تمّ». فالصمتُ عن مصدرٍ ساقطٍ
     * يُنتج شاشةً خضراءَ فوق رادارٍ نصفُه ميّت.
     */
    public function scan(Request $request, FindingStore $store): RedirectResponse
    {
        /** @var list<array{0:string,1:object,2:string}> */
        $collectors = [
            [GuardCoverageCollector::SOURCE, app(GuardCoverageCollector::class), 'مسارَ كتابة'],
            [\App\Saher\Collectors\GateCoverageCollector::SOURCE,
                app(\App\Saher\Collectors\GateCoverageCollector::class), 'التزاماً'],
            [\App\Saher\Collectors\DataTruthCollector::SOURCE,
                app(\App\Saher\Collectors\DataTruthCollector::class), 'أصلَ بيانات'],
        ];

        $done = [];
        $failed = [];

        foreach ($collectors as [$source, $collector, $unit]) {
            $runId = $store->beginRun($source, 'manual', $request->user()?->id);

            try {
                $result = $collector->collect();

                if ($result['assets_seen'] === 0) {
                    // **وصفرُ اكتشافاتٍ من فحصٍ أعمى ليس سلامة.**
                    $store->failRun($runId, $source, 'جولةٌ عمياء — صفرُ أصول');
                    $failed[] = "{$source}: جولةٌ عمياء (صفرُ أصول)";

                    continue;
                }

                $c = $store->commitRun($runId, $source,
                    $result['findings'], $result['assets_seen']);

                $done[] = sprintf('%s — %d %s · جديد %d · عاد %d · أُغلق %d',
                    $source, $result['assets_seen'], $unit,
                    $c['opened'], $c['reopened'], $c['resolved']);
            } catch (\Throwable $e) {
                $store->failRun($runId, $source, $e->getMessage());
                $failed[] = "{$source}: " . $e->getMessage();
            }
        }

        // **والفشلُ يُقال أوّلاً** — ولافتةُ نجاحٍ فوق مصدرٍ ساقطٍ تُطمئن
        // ولا تحرس.
        if ($failed !== []) {
            return back()->with('error',
                'سقط ' . count($failed) . ' من ' . count($collectors) . ': '
                . implode(' · ', $failed)
                . ($done === [] ? '' : ' — ونجح: ' . implode(' · ', $done))
                . ' والاكتشافاتُ السابقةُ لمن سقط تبقى مفتوحة.');
        }

        return back()->with('success', implode(' · ', $done));
    }
}
