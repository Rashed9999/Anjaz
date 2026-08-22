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
     */
    public function scan(Request $request, FindingStore $store,
        GuardCoverageCollector $collector): RedirectResponse
    {
        $source = GuardCoverageCollector::SOURCE;
        $runId = $store->beginRun($source, 'manual', $request->user()?->id);

        try {
            $result = $collector->collect();

            if ($result['assets_seen'] === 0) {
                $store->failRun($runId, $source, 'جولةٌ عمياء — صفرُ مسارات');

                return back()->with('error',
                    'جولةٌ عمياء: لم يُقرأ مسارٌ واحد. لم تُسجَّل نتيجة — '
                    . 'وصفرُ اكتشافاتٍ من فحصٍ أعمى ليس سلامة.');
            }

            $c = $store->commitRun($runId, $source, $result['findings'], $result['assets_seen']);
        } catch (\Throwable $e) {
            $store->failRun($runId, $source, $e->getMessage());

            return back()->with('error', 'سقط الفحص: ' . $e->getMessage()
                . ' — والاكتشافاتُ السابقةُ تبقى مفتوحة.');
        }

        return back()->with('success', sprintf(
            'فُحص %d مسارَ كتابة · جديد %d · عاد %d · أُغلق %d',
            $result['assets_seen'], $c['opened'], $c['reopened'], $c['resolved'],
        ));
    }
}
