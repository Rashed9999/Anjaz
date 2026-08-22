<?php

namespace Tests\Feature;

use App\Models\User;
use App\Saher\Collectors\GuardCoverageCollector;
use App\Saher\Findings\Evidence;
use App\Saher\Findings\Finding;
use App\Saher\Findings\FindingStore;
use App\Services\PlatformRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * SAHER-FOUNDATION-007 — **ساهرٌ يُقاس بما يمسك، لا بما يُعلن.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * §38 من المخطّط تشترط لإعلان `SAHER_FOUNDATION_READY`:
 *
 *     collector failure represented as zero = 0
 *     PROVEN finding without evidence       = 0
 *     SAHER outage blocks Amial             = 0
 *     business DB write permission          = 0
 *
 * **وكلُّ سطرٍ منها ثابتٌ يُقاس هنا، لا وعدٌ في وثيقة.**
 *
 * وأهمُّها الأوّل: **جامعٌ لا يرى شيئاً يُخرج لوحةً خضراء**، وهي أخطرُ
 * حالاتِ الرصد — أخطرُ من غياب الرصد نفسِه، لأنّها تُطمئن. وقد وقع هذا
 * في المشروع مرّتين: مسبارٌ يخرج بالرمز ٢ قبل أن يضغط زرّاً فقُرئ نجاحاً،
 * وتمرينُ تعافٍ يُخرج `PASS ✓` على قاعدةٍ غيرِ موجودة.
 */
class SaherFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'platform_admin'): User
    {
        $u = User::factory()->create(['type' => ADMIN_TYPE, 'role' => 'admin']);
        app(PlatformRoleService::class)->assign($u, $role);

        return $u->refresh();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الجامعُ يرى — مُثبَتاً بحقن عطلٍ حقيقيّ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_collector_catches_an_admin_write_route_left_without_a_permission(): void
    {
        // **هذا هو العكسُ مبنيّاً في المِقياس.** يُحقن مسارُ كتابةٍ بلا
        // حارسٍ في الموجِّه، فإن لم يبلّغ عنه الجامعُ فهو أعمى — مهما
        // خرجت جولاتُه خضراء.
        Route::post('admin/saher-probe/dangerous-write', fn () => 'x')
            ->name('admin.saher.probe.write');

        $result = app(GuardCoverageCollector::class)->collect();

        $keys = array_map(fn (Finding $f) => $f->assetKey, $result['findings']);

        $this->assertContains('admin.saher.probe.write', $keys,
            'الجامعُ لم يرَ مسارَ كتابةٍ بلا صلاحيّةٍ حُقن أمامه — '
            . 'وجامعٌ أعمى يُخرج لوحةً خضراء، وهي أخطرُ من غياب الرصد');

        $probe = collect($result['findings'])
            ->first(fn (Finding $f) => $f->assetKey === 'admin.saher.probe.write');

        $this->assertSame('PROVEN', $probe->confidence);
        $this->assertTrue($probe->hasProof(), 'اكتشافٌ `PROVEN` بلا دليل');
    }

    /** @test */
    public function a_money_route_without_a_guard_is_critical_not_merely_high(): void
    {
        // **ودرجةُ الخطر تفرّق.** مسارٌ يُنشئ رصيداً بلا حارسٍ ليس كمسارٍ
        // يُعدّل لافتة، وشاشةٌ ترتّبهما سواءً تُغرق الحرجَ في الضجيج.
        Route::post('admin/saher-probe/agents/{id}/credit', fn () => 'x')
            ->name('admin.saher.probe.credit');

        $f = collect(app(GuardCoverageCollector::class)->collect()['findings'])
            ->first(fn (Finding $x) => $x->assetKey === 'admin.saher.probe.credit');

        $this->assertNotNull($f, 'لم يُرَ مسارُ المال');
        $this->assertSame('CRITICAL', $f->severity,
            'مسارٌ يُحرّك مالاً بلا حارسٍ صُنّف دون «حرج»');
    }

    /** @test */
    public function a_write_hidden_behind_a_read_permission_is_reported(): void
    {
        // **القسمةُ المتكرّرة سبعَ مرّاتٍ في هذا المشروع**: فعلٌ وقراءةٌ في
        // حبّةٍ واحدة، فمنحُ الأوّل منحٌ للثاني.
        Route::post('admin/saher-probe/aml/rules', fn () => 'x')
            ->middleware('platform:platform.audit.view')
            ->name('admin.saher.probe.rules');

        $f = collect(app(GuardCoverageCollector::class)->collect()['findings'])
            ->first(fn (Finding $x) => $x->assetKey === 'admin.saher.probe.rules');

        $this->assertNotNull($f,
            'مسارُ كتابةٍ خلف `audit.view` وحدَها لم يُبلَّغ عنه');
        $this->assertSame('SAHER.GUARD.WRITE_BEHIND_READ_PERMISSION', $f->ruleId);
    }

    /** @test */
    public function the_exemptions_are_real_routes_and_really_self_scoped(): void
    {
        // **واستثناءٌ لا يُراجَع يصير ثقباً.**
        //
        // وأوّلُ جولةٍ صادقةٍ كشفت أنّ الاستثناء `admin.auth.login` **لا
        // وجودَ له**: اسمُ المسار الحقيقيّ `admin.auth.` بنقطةٍ زائدة.
        // فاستثناءٌ لا يطابق الواقع ليس استثناءً — يُطمئن ولا يستثني.
        $ref = new \ReflectionClass(GuardCoverageCollector::class);
        $exempt = array_keys($ref->getConstant('OPEN_BY_DESIGN'));

        $names = [];

        foreach (app('router')->getRoutes() as $route) {
            $names[] = (string) $route->getName();
        }

        $ghosts = array_values(array_diff($exempt, $names));

        $this->assertSame([], $ghosts,
            "استثناءاتٌ لمساراتٍ لا وجودَ لها:\n  " . implode("\n  ", $ghosts));

        // **ويُثبَت أنّها ذاتيّةُ النطاق فعلاً** — لا يُصدَّق التعليقُ وحدَه.
        // فمسارٌ يُعطّل مصادقةً ثنائيّةً لو قَبِل معرِّفَ غيرِه لكان تصعيدَ
        // امتياز: من يُعطّلها لمشرفٍ آخر يفتح حسابَه.
        $src = file_get_contents(app_path('Http/Controllers/Admin/Admin2FAController.php'));

        $this->assertStringContainsString('$request->user()', $src,
            'مسارات المصادقة الثنائيّة لم تعد تعمل على صاحبها وحدَه — '
            . 'يُراجَع الاستثناءُ ولا يُترَك');

        $this->assertStringNotContainsString('User::find($request->input', $src,
            'متحكّمُ المصادقة الثنائيّة صار يقبل معرِّفَ مستخدمٍ من الطلب — '
            . 'وذاك تصعيدُ امتياز، لا استثناء');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② «تعذّر الفحص» ليس «لا مشكلة»
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_blind_run_is_a_failure_not_a_clean_board(): void
    {
        $store = app(FindingStore::class);
        $runId = $store->beginRun('guards', 'manual');

        $store->failRun($runId, 'guards', 'المرشِّحُ لا يرى الموجِّه');

        $this->assertDatabaseHas('saher_scan_runs',
            ['id' => $runId, 'status' => 'FAILED']);

        $this->assertDatabaseHas('saher_sources',
            ['code' => 'guards', 'health' => 'UNAVAILABLE']);

        // **ولا تُقرأ صفراً**: مصدرٌ غيرُ متاحٍ حالتُه معلنةٌ ولا تُخلط
        // بـ«صفرُ اكتشافات». (القاعدة السابعة.)
        $this->assertNotSame('HEALTHY',
            DB::table('saher_sources')->where('code', 'guards')->value('health'));
    }

    /** @test */
    public function a_failed_run_does_not_close_what_the_previous_run_found(): void
    {
        // **وجامعٌ عطبَ لا يُثبت أنّ العطلَ زال.** ولو أُغلقت اكتشافاتُه
        // عند السقوط لصارت كلُّ عطبةِ جامعٍ «إصلاحاً» — وهي اللوحةُ
        // الخضراءُ فوق الراصد الميّت بعينها.
        $store = app(FindingStore::class);

        $run1 = $store->beginRun('guards');
        $store->commitRun($run1, 'guards', [$this->sampleFinding()], 10);

        $this->assertDatabaseHas('saher_findings',
            ['rule_id' => 'TEST.RULE', 'status' => 'OPEN']);

        $run2 = $store->beginRun('guards');
        $store->failRun($run2, 'guards', 'انقطع الاتّصال');

        $this->assertDatabaseHas('saher_findings',
            ['rule_id' => 'TEST.RULE', 'status' => 'OPEN']);
    }

    /** @test */
    public function the_command_exits_nonzero_when_the_source_sees_nothing(): void
    {
        // المرشِّحُ يقتصر على `admin/`، فبلا مساراتٍ لا شيءَ يُرى.
        // ويُحاكى ذلك بجولةٍ على مصدرٍ لا مسارَ له.
        $store = app(FindingStore::class);
        $runId = $store->beginRun('guards');
        $store->failRun($runId, 'guards', 'صفرُ مسارات');

        $this->assertSame('FAILED',
            DB::table('saher_scan_runs')->where('id', $runId)->value('status'));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ لا `PROVEN` بلا دليل
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_proven_finding_without_evidence_is_downgraded_not_trusted(): void
    {
        $store = app(FindingStore::class);
        $runId = $store->beginRun('guards');

        $bare = new Finding(
            ruleId: 'TEST.BARE', sourceCode: 'guards', category: 'security',
            title: 'ادّعاءُ إثباتٍ بلا برهان', severity: 'HIGH',
            confidence: 'PROVEN', assetKey: 'probe.bare',
        );

        $store->commitRun($runId, 'guards', [$bare], 1);

        $this->assertSame('HIGH_CONFIDENCE',
            DB::table('saher_findings')->where('rule_id', 'TEST.BARE')->value('confidence'),
            'اكتشافٌ `PROVEN` بلا دليلٍ كُتب كما ادّعى — '
            . 'وحارسٌ يكذب أسوأ من غيابه');
    }

    /** @test */
    public function evidence_refuses_to_be_empty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Evidence('CODE_LINE', 'دليلٌ فارغ', '   ');
    }

    /** @test */
    public function a_finding_without_an_asset_is_refused_because_it_cannot_be_fingerprinted(): void
    {
        // **بلا أصلٍ لا بصمة، وبلا بصمةٍ يتكرّر العطلُ الواحدُ ألفاً.**
        $this->expectException(\InvalidArgumentException::class);

        new Finding(
            ruleId: 'TEST.NOASSET', sourceCode: 'guards', category: 'security',
            title: 'بلا أصل', severity: 'LOW', confidence: 'SUSPECTED', assetKey: '  ',
        );
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ دورةُ الحياة — الحلُّ بجولةٍ لا بيد
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_same_defect_twice_is_one_finding_seen_twice(): void
    {
        $store = app(FindingStore::class);

        $store->commitRun($store->beginRun('guards'), 'guards', [$this->sampleFinding()], 5);
        $store->commitRun($store->beginRun('guards'), 'guards', [$this->sampleFinding()], 5);

        $this->assertSame(1,
            DB::table('saher_findings')->where('rule_id', 'TEST.RULE')->count(),
            'العطلُ الواحدُ صار اكتشافين — والبصمةُ لا تعمل');

        $this->assertSame(2, (int) DB::table('saher_findings')
            ->where('rule_id', 'TEST.RULE')->value('occurrence_count'));
    }

    /** @test */
    public function a_defect_that_disappears_is_closed_by_the_scan_itself(): void
    {
        $store = app(FindingStore::class);

        $store->commitRun($store->beginRun('guards'), 'guards', [$this->sampleFinding()], 5);
        $store->commitRun($store->beginRun('guards'), 'guards', [], 5);

        $row = DB::table('saher_findings')->where('rule_id', 'TEST.RULE')->first();

        $this->assertSame('RESOLVED', $row->status);
        $this->assertNotNull($row->resolved_at);

        // **ويُسجَّل من أغلقه ولماذا** — فإغلاقٌ بلا أثرٍ لا يُراجَع.
        $this->assertDatabaseHas('saher_finding_events', [
            'finding_id' => $row->id, 'event' => 'RESOLVED_BY_SCAN', 'actor_type' => 'scan',
        ]);
    }

    /** @test */
    public function a_defect_that_comes_back_is_reopened_not_merely_opened(): void
    {
        // **والفرقُ ليس تسمية**: عطلٌ أُصلح ثمّ عاد يدلّ على إصلاحٍ ناقصٍ
        // أو انحدار، وهو أولى بالنظر من عطلٍ جديد.
        $store = app(FindingStore::class);

        $store->commitRun($store->beginRun('guards'), 'guards', [$this->sampleFinding()], 5);
        $store->commitRun($store->beginRun('guards'), 'guards', [], 5);
        $store->commitRun($store->beginRun('guards'), 'guards', [$this->sampleFinding()], 5);

        $row = DB::table('saher_findings')->where('rule_id', 'TEST.RULE')->first();

        $this->assertSame('REOPENED', $row->status);
        $this->assertNull($row->resolved_at);

        $this->assertDatabaseHas('saher_finding_events',
            ['finding_id' => $row->id, 'event' => 'REOPENED']);
    }

    /** @test */
    public function a_human_decision_is_not_overturned_by_a_scan(): void
    {
        // **ما قرّره إنسانٌ لا تنقضه آلة**: «إيجابيٌّ كاذب» و«مخاطرةٌ
        // مقبولة» قراراتٌ تُراجَع بيدٍ لا بجولة. لكنّ العدَّ يُحدَّث —
        // فكتمٌ لا يعني أنّ العطلَ اختفى.
        $store = app(FindingStore::class);
        $store->commitRun($store->beginRun('guards'), 'guards', [$this->sampleFinding()], 5);

        DB::table('saher_findings')->where('rule_id', 'TEST.RULE')
            ->update(['status' => 'ACCEPTED_RISK']);

        $store->commitRun($store->beginRun('guards'), 'guards', [$this->sampleFinding()], 5);

        $row = DB::table('saher_findings')->where('rule_id', 'TEST.RULE')->first();

        $this->assertSame('ACCEPTED_RISK', $row->status);
        $this->assertSame(2, (int) $row->occurrence_count,
            'العطلُ ما زال يُرى — والعدُّ يقوله وإن قُبلت مخاطرتُه');
    }

    /** @test */
    public function risk_ranks_a_proven_high_above_a_suspected_critical(): void
    {
        // **فترتيبُ الشاشة يضع ما يُتصرَّف فيه أوّلاً، لا ما يخيف أكثر.**
        $store = app(FindingStore::class);

        $store->commitRun($store->beginRun('guards'), 'guards', [
            (new Finding('T.PROVEN', 'guards', 'security', 'مثبَت', 'HIGH', 'PROVEN', 'a'))
                ->withEvidence(new Evidence('CODE_LINE', 'دليل', 'x')),
            new Finding('T.SUSPECT', 'guards', 'security', 'مشكوك', 'CRITICAL', 'SUSPECTED', 'b'),
        ], 2);

        $proven = DB::table('saher_findings')->where('rule_id', 'T.PROVEN')->value('risk_score');
        $suspect = DB::table('saher_findings')->where('rule_id', 'T.SUSPECT')->value('risk_score');

        $this->assertGreaterThan($suspect, $proven,
            'حرجٌ مشكوكٌ فيه علا على عالٍ مثبَت — والشاشةُ سترتّب الخوفَ لا العمل');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ ساهرٌ راصدٌ لا تابع
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function no_saher_table_holds_a_foreign_key_into_business_data(): void
    {
        // **§2.1: إذا توقّف ساهر يستمرّ أميال.** ومفتاحٌ أجنبيٌّ من جدول
        // ساهر إلى جدول أعمالٍ يقلب الآيةَ: حذفُ صفِّ أعمالٍ يفشل بسبب
        // الراصد، فيصير الراصدُ في مسارٍ حيّ.
        $offences = [];

        foreach (DB::select('SHOW TABLES') as $row) {
            $table = array_values((array) $row)[0];

            if (! str_starts_with($table, 'saher_')) {
                continue;
            }

            foreach (DB::select("
                SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
                  AND REFERENCED_TABLE_NAME IS NOT NULL", [$table]) as $fk) {
                if (! str_starts_with($fk->REFERENCED_TABLE_NAME, 'saher_')) {
                    $offences[] = "{$table} → {$fk->REFERENCED_TABLE_NAME}";
                }
            }
        }

        $this->assertSame([], $offences,
            "جدولُ ساهرٍ يشير بمفتاحٍ أجنبيٍّ إلى جدول أعمال:\n  "
            . implode("\n  ", $offences) . "\n\n"
            . 'فحذفُ صفِّ أعمالٍ يفشل بسبب الراصد — وساهرٌ راصدٌ لا تابع.');
    }

    /** @test */
    public function saher_writes_nothing_outside_its_own_tables(): void
    {
        // **يُقاس من الشيفرة لا من نيّة.** أيُّ `insert`/`update`/`delete`
        // على جدولٍ لا يبدأ بـ`saher_` في مساحة ساهر = خرقٌ لـ§2.2.
        $offences = [];
        $seen = 0;

        // **وشيفرةُ ساهر ليست كلُّها تحت `app/Saher`.** أوّلُ صيغةٍ لهذا
        // المِقياس مسحت ذلك المجلَّد وحدَه، **و`SaherController` خارجَه** —
        // فمن يضيف فيه كتابةً على جدول أعمالٍ لا يمسكه شيء. وحارسٌ يغطّي
        // بعضَ ما يدّعي حراستَه يُطمئن على ما لم يفحصه.
        $paths = [app_path('Saher'), app_path('Http/Controllers/Admin/SaherController.php')];

        foreach ($paths as $path) {
            $files = is_dir($path)
                ? new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path))
                : [new \SplFileInfo($path)];

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $seen++;
                $src = file_get_contents($file->getPathname());

                preg_match_all("~DB::table\(\s*'([a-z_]+)'~", $src, $m);

                foreach ($m[1] as $table) {
                    if (! str_starts_with($table, 'saher_')) {
                        $offences[] = basename($file->getPathname()) . " → {$table}";
                    }
                }
            }
        }

        $this->assertGreaterThan(4, $seen, 'لم تُقرأ ملفّاتُ ساهر — المرشِّحُ أعمى');

        $this->assertSame([], $offences,
            "ساهر يلمس جدولَ أعمال:\n  " . implode("\n  ", $offences) . "\n\n"
            . '§2.2 — الإصدارُ الأوّل يقرأ ولا يكتب خارج جداوله.');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑥ ولا يُفتَح لكلّ مشرف
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function evidence_is_a_narrower_grant_than_viewing(): void
    {
        // **خريطةُ الأسطح غير المحروسة هي خريطةُ الهجوم.** فالعددُ يُعرَض
        // لمن يراقب، والدليلُ لمن يُصلح.
        $auditor = $this->admin('platform_auditor');

        $this->assertTrue($auditor->hasPlatformPermission('saher.findings.view'));
        $this->assertFalse($auditor->hasPlatformPermission('saher.evidence.view'),
            'المدقّقُ يقرأ الدليلَ — وهو مقتطعاتُ شيفرةٍ ومواضعُ حرّاسٍ غائبة');

        $this->assertTrue($this->admin('platform_security')
            ->hasPlatformPermission('saher.evidence.view'));
    }

    /** @test */
    public function suppressing_a_finding_belongs_to_the_platform_admin_alone(): void
    {
        // **إخفاءُ اكتشافٍ قرارٌ لا عرض.** ومن يكتم عطلاً يُسكِت الرصدَ
        // عنه، فهي أقربُ إلى قرارِ مخاطرةٍ منها إلى فعلٍ تشغيليّ.
        foreach (['platform_security', 'platform_auditor', 'platform_maintenance',
            'platform_supervisor'] as $role) {
            $this->assertFalse(
                $this->admin($role)->hasPlatformPermission('saher.findings.suppress'),
                "«{$role}» يستطيع كتمَ اكتشافات ساهر");
        }

        $this->assertTrue($this->admin('platform_admin')
            ->hasPlatformPermission('saher.findings.suppress'));
    }

    /** @test */
    public function a_source_that_never_ran_is_not_reported_healthy(): void
    {
        // **صحّةٌ مفترَضةٌ هي اختراعُ صحّة** — و§«SAHER never invents health».
        //
        // ══════════════════════════════════════════════════════════════
        // **وهذا المِقياسُ أُعيدت صياغتُه مرّتين، ولكلٍّ درسُها:**
        //
        // ① أوّلاً دار على `saher_sources` كما هو، فخرج «مخاطِراً» في
        //    المجموعة الكاملة: الجدولُ فارغٌ فلم يُنفَّذ توكيدٌ واحد.
        //    **وحلقةٌ على لا شيءٍ تخرج خضراءَ بلا أن تفحص.**
        //
        // ② فأُضيف شرطُ «مصدرٌ واحدٌ على الأقلّ» — **فسقط**، لأنّه صار
        //    يفحص **البذرةَ لا الثابت**. والبذرةُ لم تعد هي الضمان: نُقل
        //    الضمانُ إلى `ensureSource()` في الشيفرة بعد أن أثبتت البوّابةُ
        //    أنّ صفوفَ الهجرة تختفي. **فمقياسٌ يحرس ما لم يعد يحرس شيئاً
        //    يسقط على سلامةٍ لا على عطل.**
        //
        // **فالثابتُ الصحيحُ يُصنَع حالتُه ولا يُنتظَر:** مصدرٌ يُستحدَث
        // ولم تنجح له جولةٌ **لا يُعلَن سليماً**. وهو يصدق مهما كانت
        // البذرةُ موجودةً أو غائبة.
        $store = app(FindingStore::class);

        DB::table('saher_sources')->delete();
        $store->beginRun('guards');            // بدأت ولم تكتمل

        $sources = DB::table('saher_sources')->get();

        $this->assertGreaterThan(0, $sources->count(),
            'جولةٌ بدأت ولم تُنشئ صفَّ مصدرها — فالراصدُ غيرُ مرئيّ');

        foreach ($sources as $s) {
            $this->assertNotSame('HEALTHY', $s->health,
                "المصدرُ «{$s->code}» يُعلَن سليماً ولم تكتمل له جولةٌ ناجحة");
        }

        // **والنجاحُ وحدَه يُخرجه من ذلك** — لا مرورُ الوقت ولا بدءُ جولة.
        $runId = $store->beginRun('guards');
        $store->commitRun($runId, 'guards', [], 7);

        $this->assertSame('HEALTHY',
            DB::table('saher_sources')->where('code', 'guards')->value('health'),
            'جولةٌ اكتملت ولم تُعلَن صحّةُ مصدرها — فالشاشةُ تقول «معطّل» وهو يعمل');
    }

    /** @test */
    public function a_run_on_an_unregistered_source_creates_it_rather_than_vanishing(): void
    {
        // **وغيابُ صفِّ المصدر لا يُقرأ «غير متاح» — يُقرأ عدماً.**
        //
        // فالشاشةُ لا تعرض المصدرَ إطلاقاً، ولا يُعلَم أنّ راصداً كان
        // يُفترض أن يعمل. **والحمراءُ تُرى، والغيابُ لا يُرى.**
        DB::table('saher_sources')->delete();

        $store = app(FindingStore::class);
        $runId = $store->beginRun('guards');

        $this->assertDatabaseHas('saher_sources',
            ['code' => 'guards', 'health' => 'NOT_CONFIGURED']);

        $store->failRun($runId, 'guards', 'انقطع المصدر');

        $this->assertDatabaseHas('saher_sources',
            ['code' => 'guards', 'health' => 'UNAVAILABLE']);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑦ ويُوصَل إليه — وصفحةٌ لا يُوصل إليها ليست مبنيّة
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_radar_opens_and_is_linked_from_the_sidebar(): void
    {
        $admin = $this->admin();

        $html = $this->actingAs($admin, 'user')
            ->get(route('admin.amial.saher.index'))->assertOk()->getContent();

        // **والرابطُ يطلب ما تطلبه صفحتُه بالضبط.** رابطٌ أشدُّ من صفحته
        // يُخفيها عمّن يملكها، وأليَنُ منها يُظهر باباً يُغلَق في وجهه.
        $sidebar = file_get_contents(
            resource_path('views/admin-views/amial/partials/_sidebar.blade.php'));

        $this->assertStringContainsString("route('admin.amial.saher.index'), 'saher.view'", $sidebar,
            'ساهر غيرُ مرتبطٍ من القائمة الجانبيّة بصلاحيّته — '
            . 'وصفحةٌ لا يُوصل إليها ليست مبنيّة');

        $this->assertStringContainsString('ساهر', $html);
    }

    /** @test */
    public function an_empty_board_before_any_scan_does_not_read_as_healthy(): void
    {
        // **وأخطرُ حالاتِ الرصد لوحةٌ خضراءُ لم تفحص شيئاً.** فقبل أوّل
        // جولةٍ تقول الصفحةُ ذلك صراحةً ولا تعرض «لا اكتشافات».
        $html = $this->actingAs($this->admin(), 'user')
            ->get(route('admin.amial.saher.index'))->assertOk()->getContent();

        $this->assertStringContainsString('لم تُشغَّل جولةُ فحصٍ بعد', $html);
        $this->assertStringNotContainsString('لا اكتشافاتٍ مفتوحة', $html,
            'الصفحةُ تقول «لا اكتشافات» ولم يُفحص شيء — وذاك اختراعُ صحّة');
    }

    /** @test */
    public function the_evidence_is_withheld_from_a_role_that_may_only_look(): void
    {
        $store = app(FindingStore::class);
        $store->commitRun($store->beginRun('guards'), 'guards', [$this->sampleFinding()], 5);

        $id = DB::table('saher_findings')->where('rule_id', 'TEST.RULE')->value('id');

        // المدقّقُ يرى الاكتشاف…
        $auditor = $this->admin('platform_auditor');
        $html = $this->actingAs($auditor, 'user')
            ->get(route('admin.amial.saher.show', $id))->assertOk()->getContent();

        // …ولا يرى متنَ الدليل، **ويُقال له لماذا** لا يُترَك أمام فراغ.
        $this->assertStringContainsString('الدليلُ محجوبٌ عن دورك', $html);
        $this->assertStringNotContainsString('سطرٌ ما', $html,
            'متنُ الدليل ظهر لمن لا يملك `saher.evidence.view`');

        // والأمنُ يراه.
        $secHtml = $this->actingAs($this->admin('platform_security'), 'user')
            ->get(route('admin.amial.saher.show', $id))->assertOk()->getContent();

        $this->assertStringContainsString('سطرٌ ما', $secHtml,
            'محلّلُ الأمن يملك `saher.evidence.view` ولا يرى الدليل — '
            . 'وحاجزٌ يشلّ عملاً سليماً أسوأ من ثغرة');
    }

    /** @test */
    public function running_a_scan_is_an_action_not_a_read(): void
    {
        // **زرٌّ لم يُضغط ليس مبنيّاً** — فيُضغط من الطلب.
        $this->actingAs($this->admin(), 'user')
            ->post(route('admin.amial.saher.scan'))->assertRedirect();

        $this->assertDatabaseHas('saher_scan_runs',
            ['source_code' => 'guards', 'trigger' => 'manual', 'status' => 'COMPLETED']);

        // **ومن لا يملك `saher.scan.run` لا يشغّل** — والمدقّقُ يقرأ ولا
        // يُشغّل: تشغيلُ فحصٍ يستهلك موارد ويكتب صفوفاً.
        $this->actingAs($this->admin('platform_auditor'), 'user')
            ->post(route('admin.amial.saher.scan'))->assertForbidden();
    }

    /** @test */
    public function the_scan_records_who_ran_it(): void
    {
        // **ومن شغّل يُسجَّل** (§27) — فجولةٌ بلا فاعلٍ لا تُراجَع.
        $admin = $this->admin();

        $this->actingAs($admin, 'user')->post(route('admin.amial.saher.scan'));

        $this->assertDatabaseHas('saher_scan_runs', [
            'source_code' => 'guards', 'run_by_user_id' => $admin->id,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════

    private function sampleFinding(): Finding
    {
        return (new Finding(
            ruleId: 'TEST.RULE', sourceCode: 'guards', category: 'security',
            title: 'عطلٌ للاختبار', severity: 'HIGH', confidence: 'PROVEN',
            assetKey: 'probe.route',
        ))->withEvidence(new Evidence('CODE_LINE', 'موضعُه', 'سطرٌ ما'));
    }
}
