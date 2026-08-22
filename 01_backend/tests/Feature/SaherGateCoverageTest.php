<?php

namespace Tests\Feature;

use App\Saher\Collectors\GateCoverageCollector;
use App\Saher\Findings\Finding;
use App\Saher\Support\GitTree;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SAHER-GATE-006 — **«دُفع بلا فحص» اكتشافٌ مرئيّ، لا مفاجأةٌ بعد ساعة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس في جلسةٍ واحدة:** ستّةُ التزاماتٍ بلغت فرعَ النشر بلا بوّابة.
 * وكلُّ دمجٍ لها كشف عطلاً — أخطرُها **استنتاجٌ معكوس في مفسِّر بصمة سجلّ
 * التدقيق**: حقلٌ مُلئ بعد البصم كان يُسمّى «أُفرغ»، فيُقرأ التزويرُ فقدَ
 * بياناتٍ بريئاً.
 *
 * **ولم يُكتشف واحدٌ منها إلّا بعد ساعةٍ من دفعه.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والشهادةُ للشجرة لا للالتزام** — وهو ما يجعل هذا ممكناً أصلاً:
 * البوّابةُ تُشغَّل **قبل** الالتزام، فمفتاحٌ باسم الالتزام يشهد لأبيه.
 */
class SaherGateCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function receipt(string $tree, array $over = []): void
    {
        DB::table('saher_gate_receipts')->insert($over + [
            'tree_sha' => $tree,
            'head_sha' => str_repeat('a', 40),
            'branch' => 'test',
            'verdict' => 'PASS',
            'checks_passed' => 33,
            'checks_failed' => 0,
            'tests_passed' => 2863,
            'is_full' => true,
            'ran_at' => now()->subMinutes(5),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الشهادةُ للمحتوى لا للاسم
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_working_tree_hash_is_computable_and_leaves_the_index_alone(): void
    {
        // **وأداةُ رصدٍ تُفسد `git add` الذي يجري بجانبها تُعطّل العملَ
        // الذي جاءت ترصده** (§2.1). فيُقاس أنّ الفهرسَ لم يُمسّ.
        $before = shell_exec('cd ' . escapeshellarg(base_path('..'))
            . ' && git status --porcelain 2>/dev/null');

        $tree = GitTree::ofWorkingTree();

        $after = shell_exec('cd ' . escapeshellarg(base_path('..'))
            . ' && git status --porcelain 2>/dev/null');

        if ($tree === null) {
            $this->markTestSkipped('لا مستودعَ git في هذه البيئة');
        }

        $this->assertMatchesRegularExpression('~^[0-9a-f]{40}$~', $tree);
        $this->assertSame($before, $after,
            'حسابُ الشجرة غيّر حالةَ العمل — والراصدُ لا يمسّ ما يرصده');
    }

    /** @test */
    public function a_commit_tree_matches_what_the_gate_would_have_certified(): void
    {
        // **الشهادةُ تنجو من تغيّر اسم الالتزام**: تعديلُ رسالةٍ أو إعادةُ
        // أساسٍ يُنتج مُعرّفاً جديداً وشجرةً واحدة.
        $head = GitTree::ofCommit('HEAD');

        if ($head === null) {
            $this->markTestSkipped('لا مستودعَ git في هذه البيئة');
        }

        $commits = GitTree::recentCommits(1);

        $this->assertNotEmpty($commits, 'لم يُقرأ التزامٌ واحد');
        $this->assertSame($head, $commits[0]['tree'],
            'شجرةُ الالتزام المقروءةُ لا تطابق ما يحسبه `rev-parse`');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② والجامعُ يمسك
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_commit_with_no_receipt_after_the_cutoff_is_reported(): void
    {
        $commits = GitTree::recentCommits(5);

        if ($commits === []) {
            $this->markTestSkipped('لا مستودعَ git في هذه البيئة');
        }

        // إيصالٌ قديمٌ لشجرةٍ لا وجودَ لها — يفتح النافذةَ الزمنيّة فقط.
        $this->receipt(str_repeat('f', 40),
            ['ran_at' => now()->subYears(5), 'tree_sha' => str_repeat('f', 40)]);

        $keys = array_map(fn (Finding $f) => $f->assetKey,
            app(GateCoverageCollector::class)->collect()['findings']);

        $this->assertContains($commits[0]['sha'], $keys,
            'التزامٌ بلا إيصالٍ لم يُبلَّغ عنه — والجامعُ لا يرى');
    }

    /** @test */
    public function a_commit_whose_content_was_gated_green_is_silent(): void
    {
        // **وحاجزٌ يبلّغ عن كلّ شيءٍ لا يبلّغ عن شيء.**
        $commits = GitTree::recentCommits(3);

        if ($commits === []) {
            $this->markTestSkipped('لا مستودعَ git في هذه البيئة');
        }

        $this->receipt(str_repeat('f', 40), ['ran_at' => now()->subYears(5)]);
        $this->receipt($commits[0]['tree']);

        $keys = array_map(fn (Finding $f) => $f->assetKey,
            app(GateCoverageCollector::class)->collect()['findings']);

        $this->assertNotContains($commits[0]['sha'], $keys,
            'التزامٌ محتواه مفحوصٌ بخضرةٍ بُلّغ عنه — إيجابيٌّ كاذبٌ '
            . 'يُعوّد القارئَ التجاهل');
    }

    /** @test */
    public function a_receipt_that_failed_is_worse_than_no_receipt(): void
    {
        // **دليلٌ إيجابيٌّ على السقوط أشدُّ من غياب دليل** — فترتفع
        // الدرجةُ من «متوسّط · مشتبَه» إلى «عالٍ · مثبَت».
        $commits = GitTree::recentCommits(3);

        if ($commits === []) {
            $this->markTestSkipped('لا مستودعَ git في هذه البيئة');
        }

        $this->receipt(str_repeat('f', 40), ['ran_at' => now()->subYears(5)]);
        $this->receipt($commits[0]['tree'], ['verdict' => 'FAIL', 'checks_failed' => 2]);

        $f = collect(app(GateCoverageCollector::class)->collect()['findings'])
            ->first(fn (Finding $x) => $x->assetKey === $commits[0]['sha']);

        $this->assertNotNull($f);
        $this->assertSame('SAHER.GATE.COMMIT_ON_FAILING_GATE', $f->ruleId);
        $this->assertSame('HIGH', $f->severity);
        $this->assertSame('PROVEN', $f->confidence);
    }

    /** @test */
    public function a_fast_run_is_not_a_full_certificate(): void
    {
        // `--fast` تتخطّى الاختبارات والضغط الماليَّ المتوازي — **ولا
        // تُستعمل قبل التزامٍ يمسّ المصادقة أو المال.**
        $commits = GitTree::recentCommits(3);

        if ($commits === []) {
            $this->markTestSkipped('لا مستودعَ git في هذه البيئة');
        }

        $this->receipt(str_repeat('f', 40), ['ran_at' => now()->subYears(5)]);
        $this->receipt($commits[0]['tree'], ['is_full' => false, 'tests_passed' => null]);

        $f = collect(app(GateCoverageCollector::class)->collect()['findings'])
            ->first(fn (Finding $x) => $x->assetKey === $commits[0]['sha']);

        $this->assertNotNull($f, 'شهادةٌ سريعةٌ قُرئت شهادةً كاملة');
        $this->assertSame('SAHER.GATE.FAST_ONLY', $f->ruleId);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ ولا يُحاكَم التاريخ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function nothing_is_reported_before_the_very_first_receipt(): void
    {
        // **الجدولُ يبدأ فارغاً**، فالتاريخُ كلُّه بلا إيصالات. والإبلاغُ
        // عنه يُخرج مئاتِ الاكتشافات في أوّل جولةٍ فيُعوّد القارئَ
        // التجاهلَ يومَ تصدق — وهو الدرسُ من لافتة «عُبث بالسجلّ — ٤٢».
        $this->assertSame(0, DB::table('saher_gate_receipts')->count());

        $r = app(GateCoverageCollector::class)->collect();

        $this->assertSame([], $r['findings'],
            'بُلّغ عن التاريخ كلِّه قبل أوّل إيصال');
    }

    /** @test */
    public function reading_commits_but_judging_none_is_not_a_blind_run(): void
    {
        // **والفرقُ بين «لم أقرأ شيئاً» و«قرأتُ ولم أحكم» هو القاعدةُ
        // السابعة** — واقعةً على الجامع لا على المُقاس. وقد أخرج أوّلُ
        // تشغيلٍ صادقٍ «جولةً عمياء» كاذبةً لهذا السبب بعينه.
        $r = app(GateCoverageCollector::class)->collect();

        if ($r['assets_seen'] === 0) {
            $this->markTestSkipped('لا مستودعَ git في هذه البيئة');
        }

        $this->assertGreaterThan(0, $r['assets_seen'],
            'قُرئت التزاماتٌ وعُدّت صفراً — فتُقرأ الجولةُ عمياء وهي ليست كذلك');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ والراصدُ لا يُسقط ما يرصده
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function recording_a_receipt_never_fails_the_gate(): void
    {
        // **§2.1**: أمرُ تسجيلٍ يسقط لأنّ القاعدةَ نائمةٌ يجب ألّا يقلب
        // بوّابةً خضراءَ إلى حمراء.
        $this->artisan('saher:record-gate', ['--verdict' => 'PASS', '--passed' => 33])
            ->assertExitCode(0);

        // وحتّى بحكمٍ مجهول — يُقال ولا يُسقِط.
        $this->artisan('saher:record-gate', ['--verdict' => 'مجهول'])
            ->assertExitCode(0);
    }

    /** @test */
    public function verify_sh_records_the_receipt_and_ignores_its_failure(): void
    {
        // **ويُقاس من النصّ لا من النيّة.** فسطرٌ بلا `|| true` يجعل
        // سقوطَ الراصد سقوطاً للبوّابة.
        $src = file_get_contents(base_path('scripts/verify.sh'));

        $this->assertStringContainsString('saher:record-gate', $src,
            'البوّابةُ لا تسجّل إيصالاً — فالجامعُ يحكم على فراغ');

        $this->assertMatchesRegularExpression(
            '~saher:record-gate[^\n]*(\n[^\n]*)*?\|\| true~', $src,
            'نداءُ التسجيل بلا `|| true` — فسقوطُ الراصد يُسقط البوّابة');

        // **ويُسجَّل الفشلُ كالنجاح**: أن نعرف أنّ شجرةً فُحصت فسقطت خيرٌ
        // من ألّا نعرف أنّها فُحصت.
        $this->assertStringContainsString('SAHER_VERDICT=FAIL', $src,
            'الفشلُ لا يُسجَّل — فتُقرأ الشجرةُ الساقطةُ «غيرَ مفحوصة»');
    }

    /** @test */
    public function the_receipt_is_keyed_by_content_not_by_commit_name(): void
    {
        // **وهذا ما يجعل «فحصٌ قبل الالتزام» ممكناً أصلاً.**
        $cols = DB::getSchemaBuilder()->getColumnListing('saher_gate_receipts');

        $this->assertContains('tree_sha', $cols);
        $this->assertNotContains('commit_sha', $cols,
            'الإيصالُ مفتاحُه اسمُ التزام — وهو يشهد لأبي ما فُحص لا له');
    }
}
