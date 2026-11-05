<?php

namespace Tests\Feature;

use App\Saher\Findings\Evidence;
use App\Saher\Findings\Finding;
use App\Saher\Findings\FindingStore;
use App\Saher\Support\CallerIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-SAHER-ADVICE-001 — **نصيحةٌ لم تُجرَّب قبل أن تُكتَب.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس:** خمسٌ وأربعون اكتشافاً باسم «مكشوفةٌ أكثرَ ممّا تحتاج»،
 * ونصيحتُها في كلِّها واحدة: «تُخفَّض إلى `private`». وطُبّقت فقِيس:
 *
 *     ٢٤ من ٤٩  →  تُخفَّض بلا كسر
 *     ٢٥ من ٤٩  →  **يناديها اختبارٌ مباشرةً** ⇒ الخفضُ يكسر البوّابة
 *
 * أي أنّ التقريرَ كان **يأمر بكسر نصف ما ينصح به**. ومن طبّقه ثقةً به
 * وجد الخطأَ في وجهه، ومن طبّقه بلا تشغيلٍ شحن كسراً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والسببُ أنّ الفهرسَ لا يمسّ `tests/` إطلاقاً** — وهو صحيحٌ لقاعدة
 * «لا يُوصَل إليه»: دالّةٌ لا يناديها إلّا اختبارُها **غيرُ مبلوغةٍ
 * فعلاً**، وذاك بعينه «مبنيٌّ ومُختبَرٌ ولا يُوصَل إليه» — خمسةُ أعطالٍ
 * من هذا الصنف في هذا التدقيق وحدَه.
 *
 * **فلا يُدمَج الفهرسان.** الأوّلُ يجيب «أيُنادى في الإنتاج؟»، والثاني
 * «أيُكسَر شيءٌ إن خُفِّضت؟». ودمجُهما يُسكت السؤالَ الأهمّ ليجيب الأهون.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وثانيةٌ خرجت من إصلاح الأولى:** صُحّحت النصيحةُ في الجامع، وأُعيد
 * المسح، **فتغيّر العنوانُ في اللوحة وبقيت النصيحةُ الكاسرةُ كما هي.**
 * `FindingStore` كان يُحدّث `title` و`actual_behavior` وحدَهما على
 * اكتشافٍ قائم، ويكتب `suggested_action` مرّةً عند أوّل ظهور.
 *
 * **فتصحيحُ نصيحةٍ خاطئةٍ لا يصل قارئَها أبداً** — وهو نمطُ «مبنيٌّ ولا
 * يُوصَل إليه» واقعاً على التصحيح نفسِه.
 */
class SaherAdviceTruthGuardTest extends TestCase
{
    use RefreshDatabase;

    /** الشيفرةُ بلا تعليقاتها — مرحلتان لا تعبيرٌ واحدٌ جشع. */
    private function codeOnly(string $rel): string
    {
        $s = (string) file_get_contents(base_path($rel));
        $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? '';

        return preg_replace('~^[ \t]*//[^\n]*$~m', '', $s) ?? '';
    }

    // ══════════════════════════════════════════════════════════════════
    // ① الفهرسان اثنان، ولا يُدمَجان
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_production_index_still_ignores_tests(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وهذا أهمُّ ما يُحرَس هنا.** أسهلُ «إصلاحٍ» لهذا العطل أن
        // يُضاف `tests/` إلى الفهرس الأوّل — **وهو يُعمي الأداةَ عن
        // أخطر ما تكشفه**: دالّةٌ خضراءُ الاختبار بلا مُنادٍ في الإنتاج
        // تصير «مُنادَاة» فتختفي من التقرير.
        // ══════════════════════════════════════════════════════════════
        $src = $this->codeOnly('app/Saher/Collectors/DataTruthCollector.php');

        $head = substr($src, (int) strpos($src, 'function collect'), 900);

        $this->assertMatchesRegularExpression(
            "~new CallerIndex\(\[\s*app_path\(\), base_path\('routes'\), resource_path\('views'\),~", $head,
            'فهرسُ الإنتاج تغيّرت جذورُه');

        $prod = substr($head, 0, (int) strpos($head, '$binders'));

        $this->assertStringNotContainsString("base_path('tests')", $prod,
            'الاختباراتُ دخلت فهرسَ الإنتاج — فدالّةٌ لا يناديها إلّا اختبارُها '
            . 'صارت «مُنادَاة»، وأخطرُ ما يكشفه ساهر اختفى');
    }

    /** @test */
    public function a_second_index_asks_whether_lowering_would_break_anything(): void
    {
        $src = $this->codeOnly('app/Saher/Collectors/DataTruthCollector.php');

        $this->assertStringContainsString("base_path('tests')", $src,
            'لا فهرسَ لِما خارج الإنتاج — فالنصيحةُ تُكتب بلا أن تُجرَّب');

        $this->assertStringContainsString('$binders', $src,
            'الفهرسُ الثاني لا يُمرَّر إلى بناء الاكتشاف');
    }

    /** @test */
    public function the_second_index_actually_sees_a_test_caller(): void
    {
        // **ولا يُصدَّق الفهرسُ على كلمته** — يُبنى مثالٌ ويُسأل.
        $dir = sys_get_temp_dir() . '/saher-advice-' . uniqid();
        mkdir($dir . '/svc', 0777, true);
        mkdir($dir . '/t', 0777, true);

        file_put_contents($dir . '/svc/Thing.php', <<<'PHP'
        <?php
        class Thing {
            public function outer() { return $this->bound() + $this->free(); }
            public function bound() { return 1; }
            public function free() { return 2; }
        }
        PHP);

        file_put_contents($dir . '/t/ThingTest.php', <<<'PHP'
        <?php
        class ThingTest {
            public function testIt() { (new Thing)->bound(); }
        }
        PHP);

        $decl = (string) realpath($dir . '/svc/Thing.php');
        $binders = new CallerIndex([$dir . '/t']);

        $this->assertNotEmpty($binders->callerFiles('bound', $decl),
            'الفهرسُ الثاني لا يرى اختباراً ينادي الدالّةَ — فالنصيحةُ تبقى عمياء');

        $this->assertEmpty($binders->callerFiles('free', $decl),
            'دالّةٌ لا يناديها اختبارٌ حُسبت مُثبَّتةً — فتُمنَع من خفضٍ آمن');

        array_map('unlink', glob($dir . '/*/*'));
        array_map('rmdir', glob($dir . '/*'));
        rmdir($dir);
    }

    // ══════════════════════════════════════════════════════════════════
    // ② والنصيحتان تختلفان
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_advice_differs_between_the_safe_and_the_bound(): void
    {
        $src = $this->codeOnly('app/Saher/Collectors/DataTruthCollector.php');

        $over = $this->body($src, 'overExposedMethod');

        $this->assertStringContainsString('$bound === []', $over,
            'نصيحةٌ واحدةٌ للحالتين — فنصفُها أمرٌ بكسر البوّابة');

        $this->assertStringContainsString('لا تُخفَّض قبل قرارٍ في الاختبار', $over,
            'المُثبَّتةُ باختبارٍ ما زالت تُؤمَر بالخفض');
    }

    /** @test */
    public function the_collector_really_emits_two_different_advices(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ولا يُقاس النصُّ بل ما يخرج.** الحارسُ الذي قبله يقرأ شيفرةَ
        // الجامع، وشرطٌ مكتوبٌ قد لا يُنفَّذ. فيُنادى الباني مرّتين —
        // مرّةً بلا مُثبِّتٍ ومرّةً به — ويُقارَن الخارجان.
        //
        // (وكان هذا الموضعُ اختباراً يقرأ التقريرَ الحيّ، **فتخطّى
        // دائماً** لأنّ `RefreshDatabase` تُفرّغ الجدول. والطبقةُ
        // المُخطّاةُ لا تُعدّ نجاحاً — القاعدةُ الأولى.)
        // ══════════════════════════════════════════════════════════════
        $m = new \ReflectionMethod(\App\Saher\Collectors\DataTruthCollector::class, 'overExposedMethod');
        $m->setAccessible(true);

        $c = new \App\Saher\Collectors\DataTruthCollector;
        $path = app_path('Services/FakeService.php');

        /** @var Finding $safe */
        $safe = $m->invoke($c, $path, 'helper', 42, []);
        /** @var Finding $bound */
        $bound = $m->invoke($c, $path, 'helper', 42, [base_path('tests/Feature/SomeGuardTest.php')]);

        $this->assertStringContainsString('تُخفَّض إلى `private`', $safe->suggestedAction,
            'الآمنةُ للخفض لا يُنصَح بخفضها — فالتنظيفُ لا يقع');

        $this->assertStringContainsString('لا تُخفَّض', $bound->suggestedAction,
            'المُثبَّتةُ باختبارٍ تُؤمَر بالخفض — وهو أمرٌ بكسر البوّابة');

        $this->assertStringContainsString('SomeGuardTest', $bound->suggestedAction,
            'النصيحةُ لا تسمّي ما سيُكسَر — فتُرسل قارئَها يبحث');

        $this->assertNotSame($safe->title, $bound->title,
            'العنوانان متطابقان — فمن يفرز بالعنوان يخلط الصنفين');
    }

    // ══════════════════════════════════════════════════════════════════
    // ③ وتصحيحُ النصيحة يصل قارئَها
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function correcting_a_rules_advice_reaches_an_existing_finding(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وهذا هو العطلُ الثاني بعينه.** البصمةُ ثابتةٌ عمداً
        // (`ruleId|assetKey`) فيُحدَّث الصفُّ ولا يُدرَج جديد — وكان
        // التحديثُ لا يمسّ النصيحة. فتُصلَح القاعدةُ في الشيفرة، ويقرأ
        // الإنسانُ الجملةَ الكاسرةَ القديمة **إلى الأبد**.
        // ══════════════════════════════════════════════════════════════
        $this->scan($this->finding('نصيحةٌ أولى', 'أثرٌ أوّل'));
        $this->scan($this->finding('نصيحةٌ مصحَّحة', 'أثرٌ مصحَّح'));

        $row = DB::table('saher_findings')->where('asset_key', 'Fake::method')->first();

        $this->assertNotNull($row, 'لم يُحفظ الاكتشاف');

        $this->assertSame(1, DB::table('saher_findings')->where('asset_key', 'Fake::method')->count(),
            'أُدرج صفٌّ ثانٍ — فالبصمةُ ليست ثابتة، والتاريخُ يتشظّى');

        $this->assertSame('نصيحةٌ مصحَّحة', $row->suggested_action,
            'النصيحةُ المصحَّحةُ لا تصل قارئَها — يُصلَح الجامعُ ويبقى التقريرُ يأمر بالخطأ');

        $this->assertSame('أثرٌ مصحَّح', $row->impact,
            'الأثرُ لا يُحدَّث — فسببٌ قديمٌ يُقرأ على شيفرةٍ جديدة');
    }

    /** @test */
    public function a_method_that_moved_is_not_reported_at_its_old_line(): void
    {
        // **ومؤشِّرٌ يشير إلى الخطأ يُرسل قارئَه يبحث.** الدالّةُ تنتقل
        // أسطرُها مع كلّ تحرير، والتقريرُ كان يجمّد أوّلَ سطرٍ رآها فيه.
        $this->scan($this->finding('نصيحة', 'أثر', line: 10));
        $this->scan($this->finding('نصيحة', 'أثر', line: 250));

        $row = DB::table('saher_findings')->where('asset_key', 'Fake::method')->first();

        $this->assertSame(250, (int) $row->line_start,
            'الموضعُ مجمَّدٌ على أوّل ظهور — فالتقريرُ يشير إلى سطرٍ ليس فيه شيء');
    }

    // ══════════════════════════════════════════════════════════════════
    // ④ والدليلُ لا يدّعي بحثاً لم يجرِ
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_absence_evidence_names_the_roots_it_actually_searched(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ودعوى نفيٍ أوسعُ من البحث الذي جرى كذبة.** كان الدليلُ يقول
        // «أيُّ ملفٍّ **خارج** هذا الملفّ» — و`tests/` لم تُمسّ إطلاقاً.
        // فمن قرأها فهم «بُحث في المشروع كلِّه».
        //
        // (القاعدةُ السابعة: الصفرُ يُقرأ «فحصنا فلم نجد» — فلا يُقال
        // إلّا عمّا فُحص.)
        // ══════════════════════════════════════════════════════════════
        $src = $this->codeOnly('app/Saher/Collectors/DataTruthCollector.php');

        $over = $this->body($src, 'overExposedMethod');

        $this->assertMatchesRegularExpression('~أيُّ ملفٍّ \*\*خارج\*\* \{\$rel\} في~u', $over,
            'الدليلُ يدّعي نفياً عن ملفَّاتٍ لم تُمسح — فيُقرأ أوسعَ ممّا هو');

        $this->assertStringContainsString('resources/views/', $over,
            'الدليلُ لا يسمّي الجذورَ التي مُسحت فعلاً');
    }

    // ── أدوات ─────────────────────────────────────────────────────────

    /**
     * متنُ دالّةٍ بعينها — **من تعريفها إلى تعريف تاليتها**.
     *
     * ولا يُؤخَذ بعددٍ مكتوب: نافذةُ ٣٠٠٠ محرفٍ قطعت هذه الدالّةَ عند
     * ٣١٧٨ **فسقط الحارسُ على شيفرةٍ سليمة**. ورقمٌ سحريٌّ يشيخ مع أوّل
     * سطرٍ يُضاف، ويقطع بصمتٍ لا برسالة.
     */
    private function body(string $src, string $name): string
    {
        $start = strpos($src, 'function ' . $name);

        $this->assertNotFalse($start, "لا دالّةَ بهذا الاسم: {$name}");

        $next = preg_match('~\n\s+(?:private|public|protected)\s+function ~',
            $src, $m, PREG_OFFSET_CAPTURE, (int) $start + 10)
            ? (int) $m[0][1]
            : strlen($src);

        return substr($src, (int) $start, $next - (int) $start);
    }

    /** جولةُ مسحٍ كاملةٌ باكتشافٍ واحد — كما يفعل الجامعُ الحقيقيّ. */
    private function scan(Finding $finding): void
    {
        $store = app(FindingStore::class);
        $run = $store->beginRun('data_truth', 'test');

        $store->commitRun($run, 'data_truth', [$finding], 1);
    }

    private function finding(string $advice, string $impact, int $line = 10): Finding
    {
        return (new Finding(
            ruleId: 'SAHER.DATA.SERVICE_METHOD_OVER_EXPOSED',
            sourceCode: 'data_truth',
            category: 'maintainability',
            title: 'عنوان',
            severity: 'LOW',
            confidence: 'SUSPECTED',
            assetKey: 'Fake::method',
            assetType: 'method',
            expected: 'المتوقَّع',
            actual: 'الواقع',
            impact: $impact,
            suggestedAction: $advice,
            filePath: 'app/Services/FakeService.php',
            lineStart: $line,
            symbol: 'Fake::method',
        ))->withEvidence(new Evidence('CODE_LINE', 'موضع', 'سطر', 'app/Services/FakeService.php'));
    }
}
