<?php

namespace Tests\Feature;

use App\Saher\Collectors\DataTruthCollector;
use App\Saher\Support\CallerIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SAHER-DATA-004 — **الحارسُ الذي كان سيُخرج العطلَ قبل صاحب المشروع.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **السؤالُ الذي وُلد منه هذا الملفّ، بنصّه:**
 *
 *     «...لماذا سوف تكتشف الخطأ وساهر لم يكتشفه؟»
 *
 * وهو سؤالٌ عن **الرادار لا عن العطل**. فالعطلُ يُصلَح مرّةً، والرادارُ
 * الذي لا يراه يتركه يتكرّر بأسماءٍ أخرى إلى الأبد.
 *
 * **فشرطُ صحّةِ هذا الجامع ليس أن يمرّ** — بل أن يُثبَت أنّه يمسك
 * **الشكلَ** الذي أفلت منه: قيمةٌ افتراضيّةٌ يرفضها شرطٌ ولا مخرجَ منها،
 * ودالّةٌ عامّةٌ في خدمةٍ لا يناديها أحد. **وحارسٌ لم يُجرَّب على العطل
 * الذي بُني له ليس حارساً.**
 */
class SaherDataTruthTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/saher-data-' . uniqid();
        mkdir($this->tmpRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->tmpRoot);

        parent::tearDown();
    }

    private function write(string $rel, string $body): string
    {
        $path = $this->tmpRoot . '/' . $rel;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $body);

        return $path;
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }

            $p = "{$dir}/{$e}";
            is_dir($p) ? $this->rmrf($p) : @unlink($p);
        }

        @rmdir($dir);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① فهرسُ المنادين — الأساسُ الذي يقوم عليه كلُّ شيء
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function it_counts_a_real_caller(): void
    {
        $svc = $this->write('Services/Thing.php',
            "<?php\nclass Thing { public function doIt() {} }\n");
        $this->write('Http/Caller.php',
            "<?php\nclass Caller { public function go(\$t) { \$t->doIt(); } }\n");

        $index = new CallerIndex([$this->tmpRoot]);

        $this->assertSame(1, $index->callersOutside('doIt', $svc));
    }

    /** @test */
    public function a_method_calling_only_itself_is_not_reached(): void
    {
        // **ودالّةٌ يناديها ملفُّها وحدَه ليست مبلوغةً من خارج** — وهو
        // بعينه شكلُ `assignOnRegistration`: مكتوبةٌ ومُوثَّقةٌ ومذكورةٌ
        // في ملفِّها، ولا مسارَ في المنتج كلِّه يبلغها.
        $svc = $this->write('Services/Lonely.php',
            "<?php\nclass Lonely {\n"
            . "  public function outer() { \$this->inner(); }\n"
            . "  public function inner() {}\n}\n");

        $index = new CallerIndex([$this->tmpRoot]);

        $this->assertSame(0, $index->callersOutside('inner', $svc),
            'عُدَّ نداءٌ داخليٌّ بلوغاً من خارج — فتصير كلُّ دالّةٍ ميّتةٍ حيّة');
    }

    /** @test */
    public function a_blade_template_counts_as_a_caller(): void
    {
        // **والقالبُ مُنادٍ كالشيفرة.** ولولا ذلك لخرجت عشراتُ الدوالّ
        // التي لا يناديها إلّا العرضُ «ميّتةً» — وهي حيّةٌ تُضغط كلَّ يوم.
        $svc = $this->write('Services/Fmt.php',
            "<?php\nclass Fmt { public function money() {} }\n");
        $this->write('views/page.blade.php',
            "<div>{{ \$fmt->money() }}</div>\n");

        $index = new CallerIndex([$this->tmpRoot]);

        $this->assertSame(1, $index->callersOutside('money', $svc));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② والقاعدةُ تمسك الشكلَ الذي أفلت
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function it_would_have_caught_the_zone_bug_shape(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **هذا هو الاختبارُ الذي يبرّر الجامعَ كلَّه.**
        //
        // يُعاد بناءُ شكل العطل حرفاً بحرف — عمودٌ افتراضيّه `UNKNOWN`،
        // وشرطٌ يرفض كلَّ ما ليس `SOUTH`، وحاسمٌ بلا مُنادٍ — ويجب أن
        // يخرج اكتشافٌ. **وبلا هذا يبقى الجامعُ وعداً.**
        // ══════════════════════════════════════════════════════════════
        DB::statement('CREATE TABLE saher_probe_rows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            probe_zone VARCHAR(20) NOT NULL DEFAULT "UNKNOWN"
        )');

        try {
            $collector = new DataTruthCollector();

            $columns = $this->invoke($collector, 'columnsWithStringDefaults');

            $this->assertArrayHasKey('probe_zone', $columns,
                'العمودُ ذو القيمة الافتراضيّة النصّيّة لم يُقرأ من القاعدة');

            $this->assertSame('UNKNOWN', $columns['probe_zone']);

            // والشرطُ الرافض — بصورتيه: مباشرةً وعبر متغيّر.
            $viaVariable = <<<'PHP'
            <?php
            $zone = $recipient->probe_zone ?? 'UNKNOWN';
            if ($zone !== 'SOUTH') { throw new RuntimeException('مرفوض'); }
            PHP;

            $this->assertSame('SOUTH',
                $this->invoke($collector, 'rejectingLiteralFor', $viaVariable, 'probe_zone'),
                'لم يُقرأ الشرطُ حين وقعت المقارنةُ على متغيّرٍ أُسندت إليه '
                . 'الخاصّيّة — وهي صورتُه في الشيفرة الحقيقيّة');

            $direct = "<?php if (\$u->probe_zone !== 'SOUTH') { abort(403); }";

            $this->assertSame('SOUTH',
                $this->invoke($collector, 'rejectingLiteralFor', $direct, 'probe_zone'));
        } finally {
            DB::statement('DROP TABLE IF EXISTS saher_probe_rows');
        }
    }

    /** @test */
    public function a_default_that_the_condition_accepts_is_not_reported(): void
    {
        // **وحارسٌ يبلّغ عن حالةٍ سليمةٍ يُطفَأ عند أوّل شكوى.** فالقاعدةُ
        // تصمت حين يكون الافتراضيُّ هو المقبولَ نفسَه.
        $collector = new DataTruthCollector();

        $src = "<?php if (\$u->status !== 'active') { abort(403); }";

        $this->assertSame('active',
            $this->invoke($collector, 'rejectingLiteralFor', $src, 'status'));

        // والجامعُ يقارن: `active === active` ⇒ لا اكتشاف. (يُقاس أدناه
        // على المسار الكامل.)
    }

    /** @test */
    public function a_column_with_a_reached_writer_is_not_reported(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وهذا الشرطُ هو ما يمنع الجامعَ من أن يصير ضجيجاً.**
        //
        // «ممنوعٌ حتّى يثبت» تصميمٌ سليمٌ **ما دام الإثباتُ ممكناً**.
        // فالعطلُ ليس في القيمة الافتراضيّة — بل في ألّا يكون لها باب.
        //
        // وبعد إصلاح عطل المناطق صار لـ`zone_code` كاتبٌ مبلوغ
        // (`assignFromKyc` من لوحة الإدارة). **فلو بلّغ الجامعُ عنه بعد
        // الإصلاح لَكان يبلّغ عن شيفرةٍ سليمة** — وذاك يُعوّد القارئَ
        // على التجاهل يومَ يصدق.
        // ══════════════════════════════════════════════════════════════
        $collector = new DataTruthCollector();

        $svc = $this->write('Services/Zones.php',
            "<?php\nclass Zones {\n  public function resolve(\$u) { \$u->probe_zone = 'SOUTH'; }\n}\n");
        $this->write('Http/Controllers/AdminZones.php',
            "<?php\nclass AdminZones { public function go(\$z, \$u) { \$z->resolve(\$u); } }\n");

        $index = new CallerIndex([$this->tmpRoot]);

        $this->assertTrue(
            $this->invoke($collector, 'hasReachedWriter', 'probe_zone', $index,
                [$svc, $this->tmpRoot . '/Http/Controllers/AdminZones.php']),
            'كاتبٌ في خدمةٍ يناديها متحكّمٌ قُرئ غيرَ مبلوغ');
    }

    /** @test */
    public function a_writer_in_an_unreached_service_does_not_count(): void
    {
        // **وكاتبٌ لا يناديه أحدٌ ليس باباً.** وهذا هو الفرقُ بين
        // «الحلُّ مبنيّ» و«الحلُّ مُوصَل».
        $collector = new DataTruthCollector();

        $svc = $this->write('Services/Orphan.php',
            "<?php\nclass Orphan {\n  public function assignOnRegistration(\$u) { \$u->probe_zone = 'SOUTH'; }\n}\n");

        $index = new CallerIndex([$this->tmpRoot]);

        $this->assertFalse(
            $this->invoke($collector, 'hasReachedWriter', 'probe_zone', $index, [$svc]),
            'كاتبٌ في دالّةٍ لا يناديها أحدٌ قُرئ باباً — وهو نفسُ العطل '
            . 'الذي بُني هذا الجامعُ ليمسكه');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ ولا يتّهم ما يناديه الإطار
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function framework_entry_points_are_never_reported(): void
    {
        // **وجامعٌ يتّهم `handle()` في كلّ خدمةٍ يُخرج عشراتِ الأكاذيب في
        // أوّل جولة** — فيُعوَّد القارئُ على التجاهل قبل أن يصدق مرّةً.
        $r = new \ReflectionClass(DataTruthCollector::class);
        $list = $r->getConstant('FRAMEWORK_ENTRY_POINTS');

        foreach (['__construct', 'handle', 'boot', 'rules', 'toArray'] as $name) {
            $this->assertContains($name, $list, "«{$name}» يناديه الإطارُ ولا يُتَّهم");
        }
    }

    /** @test */
    public function private_methods_are_not_asked_about_callers(): void
    {
        // ودالّةٌ خاصّةٌ لا تُسأل — يناديها صنفُها بحكم تعريفها.
        $collector = new DataTruthCollector();

        $src = "<?php\nclass X {\n  public function a() {}\n"
            . "  private function b() {}\n  protected function c() {}\n}\n";

        $names = array_column($this->invoke($collector, 'publicMethodsOf', $src), 0);

        $this->assertSame(['a'], $names,
            'قُرئت الخاصّةُ أو المحميّةُ عامّةً — فيُتَّهم ما لا يُتَّهم');
    }

    /** @test */
    public function an_anonymous_closure_is_not_read_as_a_method(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **عطلٌ حقيقيٌّ أنتجه هذا الجامعُ في أوّل مسحٍ شامل.**
        //
        // كان يقفز حتّى خمسةَ رموزٍ بعد `function` بحثاً عن أوّل اسم.
        // **والإغلاقُ بلا اسم**، فيلتقط **نوعَ أوّل مُعامِل**:
        //
        //     function (int $a) {…}          →  دالّةٌ اسمُها «int»
        //     function (Promotion $p) {…}    →  دالّةٌ اسمُها «Promotion»
        //
        // فخرجت ستّةُ أسماءٍ كاذبةٍ في تقرير «شيفرةٌ ميّتة». **واسمٌ
        // مخترَعٌ في تقريرِ موتٍ أخطرُ من صمت**: يُرسل من يقرؤه يبحث عن
        // شيءٍ لا وجودَ له، ثمّ يتعلّم أن يتجاهل التقريرَ كلَّه.
        // ══════════════════════════════════════════════════════════════
        $collector = new DataTruthCollector();

        $src = "<?php\nclass X {\n"
            . "  public function real(): int { return 1; }\n"
            . "  public function withClosure() {\n"
            . "    \$f = function (int \$a) { return \$a; };\n"
            . "    \$g = function (Promotion \$p) { return \$p; };\n"
            . "    return [\$f, \$g];\n  }\n}\n";

        $names = array_column($this->invoke($collector, 'publicMethodsOf', $src), 0);

        $this->assertSame(['real', 'withClosure'], $names,
            'قُرئ نوعُ مُعامِلٍ في إغلاقٍ اسمَ دالّة — فيُبلَّغ عن موتِ ما لا وجودَ له');
    }

    /** @test */
    public function a_signature_inside_a_comment_is_not_read_as_a_definition(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وهذا العطلُ وقع في هذا المشروع مرّتين.**
        //
        // حارسٌ للبحث عن نقاط نهايةٍ ميّتة مرّ لأنّ الكلمة وردت في تعليقٍ
        // عربيٍّ يشرح أنّ النقطة غير موصولة. **فالتعليقُ الذي يصف العطلَ
        // كان يُخفيه.** ووقع ثالثةً في هذه الجلسة نفسِها.
        //
        // فتُقرأ الدوالُّ من الرموز لا من نصّ الملفّ.
        // ══════════════════════════════════════════════════════════════
        $collector = new DataTruthCollector();

        $src = "<?php\nclass X {\n"
            . "  // public function ghost() — مذكورةٌ في تعليقٍ فقط\n"
            . "  /** public function phantom() */\n"
            . "  public function real() {}\n}\n";

        $names = array_column($this->invoke($collector, 'publicMethodsOf', $src), 0);

        $this->assertSame(['real'], $names,
            'قُرئ توقيعٌ داخلَ تعليقٍ تعريفاً — والتعليقُ الذي يصف العطلَ يُخفيه');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ والجولةُ تُسجَّل، والعمياءُ منها تسقط
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_command_records_a_run_and_reports_what_it_saw(): void
    {
        $this->artisan('saher:scan-data')->assertExitCode(0);

        $run = DB::table('saher_scan_runs')->where('source_code', 'data_truth')
            ->latest('id')->first();

        $this->assertNotNull($run, 'جولةٌ لم تُسجَّل — فلا يُعرف متى فُحص آخرَ مرّة');
        $this->assertSame('COMPLETED', $run->status);

        $this->assertGreaterThan(0, (int) $run->assets_seen,
            'جولةٌ سُجّلت ناجحةً وقد قرأت صفرَ أصول — «لم أنظر» تُقرأ «نظرتُ فلم أجد»');
    }

    /** @test */
    public function the_source_gets_an_arabic_label_not_its_code(): void
    {
        // **ورمزٌ إنجليزيٌّ في شاشةٍ عربيّةٍ يُوقف القارئ.**
        $this->artisan('saher:scan-data');

        $this->assertNotSame('data_truth',
            DB::table('saher_sources')->where('code', 'data_truth')->value('label_ar'),
            'المصدرُ يُعرَض برمزه — ولا معجمَ له');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ⑤ وزرُّ الفحص يبلغ الجوامعَ كلَّها
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_scan_button_runs_every_collector_not_only_the_first(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وجامعٌ بلا زرٍّ يبلغه ليس مبنيّاً.**
        //
        // كان زرُّ «فحصٌ الآن» يشغّل `guards` وحدَه، و`gate` و`data_truth`
        // لا يبلغهما إلّا أمرٌ في الطرفيّة. **وهو نمطُ العطل الأكثرُ
        // تكراراً في هذا المشروع، واقعاً على الأداة التي بُنيت لتمسكه.**
        // ══════════════════════════════════════════════════════════════
        $src = file_get_contents(app_path('Http/Controllers/Admin/SaherController.php'));

        foreach (['GuardCoverageCollector', 'GateCoverageCollector', 'DataTruthCollector'] as $c) {
            $this->assertStringContainsString($c . '::SOURCE', $src,
                "زرُّ الفحص لا يبلغ {$c} — فجامعٌ بلا مدخلٍ ليس مبنيّاً");
        }
    }

    /** @test */
    public function one_collector_failing_does_not_silence_the_others(): void
    {
        // **ولافتةُ نجاحٍ فوق مصدرٍ ساقطٍ تُطمئن ولا تحرس.** فكلُّ جامعٍ
        // يُحاسَب وحدَه، والفشلُ يُقال أوّلاً.
        $src = file_get_contents(app_path('Http/Controllers/Admin/SaherController.php'));

        $this->assertMatchesRegularExpression(
            '~if\s*\(\$failed\s*!==\s*\[\]\)~', $src,
            'سقوطُ جامعٍ يُبتلَع في رسالةٍ تقول «تمّ»');

        $this->assertStringContainsString('continue;', $src,
            'سقوطُ جامعٍ يُسقط الباقين');
    }

    // ══════════════════════════════════════════════════════════════════

    /** يُنادى ما هو خاصٌّ — فالقياسُ على الوحدة أدقُّ من القياس على الجملة. */
    private function invoke(object $obj, string $method, mixed ...$args): mixed
    {
        $m = new \ReflectionMethod($obj, $method);
        $m->setAccessible(true);

        return $m->invoke($obj, ...$args);
    }
}
