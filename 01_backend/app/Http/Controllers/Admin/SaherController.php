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
     * **الفرزُ فعلٌ يُسجَّل على فاعله، لا زرٌّ يُخفي صفّاً.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `HUMAN_HELD` مبنيّةٌ في `FindingStore` وتنجو من كلّ مسح، والشاشةُ
     * تترجم حالاتِها الثلاث، والصلاحيّتان معرَّفتان — **ولم يكن ثمّة ما
     * يضعها**. فبقيت ٩٦ نتيجةً تُقرأ من الصفر في كلّ تدقيق، ومعها
     * إغراءُ الحذف الجَماعيّ.
     *
     * وثلاثةُ شروطٍ تجعل الحكمَ حكماً لا كتماناً:
     *
     *   ① **سببٌ نصّيٌّ لا علامة.** حكمٌ بلا سببٍ يُقرأ بعد شهرين
     *      «أحدُهم رآه ولم يقل لماذا» — وهو أسوأ من قائمةٍ مفتوحة.
     *
     *   ② **ينتهي بتغيّر الشيفرة.** يُحفَظ تجزيءُ الملفّ المُصرِّح وقتَ
     *      الحكم؛ فحكمٌ صدر على شيفرةٍ لا يسري على خَلَفٍ لم يره أحد.
     *      («وإقرارٌ لا ينتهي صمتٌ مؤجَّل» — درسُ `gradle-floor-waiver`.)
     *
     *   ③ **وأثرٌ في السجلّ وفي التدقيق.** فمن كتم يُعرَف ومتى ولماذا.
     */
    public function rule(Request $request, int $id): RedirectResponse
    {
        $finding = DB::table('saher_findings')->where('id', $id)->first();

        abort_if($finding === null, 404);

        $data = $request->validate([
            'status' => 'required|in:FALSE_POSITIVE,ACCEPTED_RISK,SUPPRESSED,OPEN',
            // **وسببٌ من كلمةٍ ليس سبباً.** عشرون محرفاً حدٌّ أدنى يمنع
            // «تمام» و«معروف» — وهما كتمانٌ بثوب حكم.
            'reason' => 'required|string|min:20|max:2000',
        ]);

        $isHold = $data['status'] !== 'OPEN';

        // **وتجزيءُ الملفّ يُقرأ عند الحكم لا عند القراءة.** فإن غاب
        // الملفُّ فلا تجزيء — والحكمُ يبقى، لكنّه لا يدّعي ما لا يعرف.
        $path = $finding->file_path ? base_path($finding->file_path) : null;
        $hash = ($isHold && $path && is_file($path))
            ? hash_file('sha256', $path)
            : null;

        DB::transaction(function () use ($finding, $data, $isHold, $hash, $request, $id) {
            DB::table('saher_findings')->where('id', $id)->update([
                'status' => $data['status'],
                'ruling_reason' => $data['reason'],
                'ruling_by_user_id' => $request->user()?->id,
                'ruled_at' => now(),
                'ruling_source_hash' => $hash,
                'updated_at' => now(),
            ]);

            DB::table('saher_finding_events')->insert([
                'finding_id' => $id,
                'event' => $isHold ? 'RULED_HELD' : 'REOPENED_BY_HUMAN',
                'from_status' => $finding->status,
                'to_status' => $data['status'],
                'note' => mb_substr($data['reason'], 0, 2000),
                'actor_user_id' => $request->user()?->id,
                'actor_type' => 'platform_user',
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 3);

        app(\App\Services\AuditService::class)->record([
            'actor_type' => 'platform_user',
            'actor_user_id' => $request->user()?->id,
            'subject_type' => 'saher_finding',
            'subject_id' => (string) $id,
            'action' => $isHold ? 'SAHER_FINDING_HELD' : 'SAHER_FINDING_REOPENED',
            'decision_code' => $data['status'],
            'reason' => mb_substr($data['reason'], 0, 500),
            'severity' => $isHold ? 'warning' : 'info',
            'context' => [
                'rule_id' => $finding->rule_id,
                'symbol' => $finding->symbol,
                'source_hash' => $hash,
            ],
        ]);

        return back()->with('success', $isHold
            ? 'سُجّل الحكم — ويُرفَع من تلقائه إن تغيّرت الشيفرة.'
            : 'أُعيد فتحُ الاكتشاف.');
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
        $unavailable = [];

        foreach ($collectors as [$source, $collector, $unit]) {
            $runId = $store->beginRun($source, 'manual', $request->user()?->id);

            try {
                $result = $collector->collect();

                // ══════════════════════════════════════════════════
                // SAHER-GATE-005 — **ثلاثُ حالاتٍ لا اثنتان.**
                //
                //   نجح · **غيرُ متاحٍ هنا** · جولةٌ عمياء
                //
                // و«غيرُ متاح» ليس فشلاً: مصدرُ البوّابة يقرأ تاريخَ git،
                // وصورةُ النشر تُبنى بلا `.git`. فكان يُرفع أحمرَ في كلّ
                // ضغطة، **ولا شيءَ مكسور** — ولافتةٌ حمراءُ دائمةٌ تُعوّد
                // القارئَ تجاهلَها يومَ تصدق.
                // ══════════════════════════════════════════════════
                if (isset($result['unavailable'])) {
                    $store->failRun($runId, $source, $result['unavailable']);
                    $unavailable[] = "{$source}: " . $result['unavailable'];

                    continue;
                }

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
                . ($unavailable === [] ? ''
                    : ' — وغيرُ متاحٍ هنا: ' . implode(' · ', $unavailable))
                . ' والاكتشافاتُ السابقةُ لمن سقط تبقى مفتوحة.');
        }

        // **وغيرُ المتاح يُقال ولا يُرفع أحمر.** لافتةٌ تحذيريّةٌ تُفرّق
        // بين «مصدرٌ لا يعمل في هذه البيئة» و«مصدرٌ انكسر».
        if ($unavailable !== []) {
            return back()->with('warning',
                'نجح ' . count($done) . ' من ' . count($collectors) . ': '
                . implode(' · ', $done)
                . ' — وغيرُ متاحٍ في هذه البيئة: ' . implode(' · ', $unavailable));
        }

        return back()->with('success', implode(' · ', $done));
    }
}
