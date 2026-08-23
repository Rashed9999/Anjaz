<?php

namespace Tests\Feature;

use App\Saher\Support\CallerIndex;
use Tests\TestCase;

/**
 * AMIAL-SAHER-HONESTY-001 — **نصفُ التقرير كان اسمُه يكذب على ما وجده.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما قِيس على جولةٍ كاملة:**
 *
 *     109 نتيجة كلُّها باسم `SERVICE_METHOD_UNREACHED` وشدّةٍ متوسّطة
 *       ├─ 55  **تُنادى داخل صنفها**  ← تعمل. ليست عطلاً
 *       └─ 54  صفرُ نداءٍ في المشروع  ← الحقيقيّة
 *
 * و`CallerIndex::callersOutside` تستثني ملفَّ التعريف **عمداً وبتعليقٍ
 * يشرح ذلك**. فالمنطقُ سليم، **والاسمُ الذي عُلّق عليه كاذب**: «لا
 * يناديها أحد» عن دالّةٍ يناديها صنفُها في السطر التالي.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والثمنُ ليس تجميليّاً.** ستُّ أعطالٍ حقيقيّةٍ كانت مدفونةً في هذه
 * القائمة — حدُّ استلام التاجر، وقفلُ رمز العمليّات، ومسارُ استعادة
 * الحساب، والتوقيعُ الثاني — **ولم تظهر إلّا بفرزٍ يدويٍّ خارج ساهر**.
 * فالأداةُ وجدتها ثمّ دفنتها.
 *
 * **ومُبلِّغٌ نصفُه ضجيجٌ يُعوّد القارئَ على التجاهل يومَ يصدق.** وهي
 * القاعدةُ التي دفع المشروعُ ثمنَها مرّتين: «كُسرت السلسلة في ٧ مواضع»
 * على صفوفٍ لم يمسَّها أحد، و«٢١٧٣ اختباراً فشل» ولا واحدٌ منها مكسور.
 */
class SaherReportHonestyGuardTest extends TestCase
{
    private function collectorSource(): string
    {
        $s = (string) file_get_contents(base_path('app/Saher/Collectors/DataTruthCollector.php'));
        $s = preg_replace('~/\*.*?\*/~s', '', $s) ?? '';

        return preg_replace('~^[ \t]*//[^\n]*$~m', '', $s) ?? '';
    }

    // ══════════════════════════════════════════════════════════════════
    // الفهرسُ يفرّق
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_index_can_tell_an_internal_call_from_none_at_all(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **بلا هذا لا يمكن للتقرير أن يصدق.** `callersOutside` وحدَها
        // تُعيد صفراً في الحالتين: «تُنادى من صنفها» و«لا يناديها شيء».
        // ══════════════════════════════════════════════════════════════
        $dir = sys_get_temp_dir() . '/saher-honesty-' . uniqid();
        mkdir($dir, 0777, true);

        file_put_contents($dir . '/Inner.php', <<<'PHP'
        <?php
        class Inner {
            public function outer() { return $this->helper(); }
            public function helper() { return 1; }
            public function orphan() { return 2; }
        }
        PHP);

        $index = new CallerIndex([$dir]);
        $decl = realpath($dir . '/Inner.php');

        $this->assertTrue($index->calledWithin('helper', $decl),
            '`helper` تُنادى في الملفّ نفسِه ولم يُرَ ذلك');

        $this->assertFalse($index->calledWithin('orphan', $decl),
            '`orphan` بلا نداءٍ إطلاقاً وحُسبت مُنادَاة');

        $this->assertSame(0, $index->callersOutside('helper', $decl),
            'نداءٌ داخليٌّ حُسب خارجيّاً');

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    // ══════════════════════════════════════════════════════════════════
    // والتقريرُ يفرّق
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_collector_emits_two_rules_not_one(): void
    {
        $src = $this->collectorSource();

        $this->assertStringContainsString('SERVICE_METHOD_OVER_EXPOSED', $src,
            'قاعدةٌ واحدةٌ لصنفين — فما يعمل يُبلَّغ «لا يعمل»');

        $this->assertStringContainsString('SERVICE_METHOD_UNREACHED', $src,
            'اختفت قاعدةُ «لا يناديها أحد» — والعطلُ الحقيقيُّ بلا اسم');

        $this->assertMatchesRegularExpression(
            '~calledWithin\(\$name, \$path\)\s*\?\s*\$this->overExposedMethod~s', $src,
            'الاختيارُ بين القاعدتين لا يقوم على وجود نداءٍ داخليّ');
    }

    /** @test */
    public function the_two_rules_do_not_carry_the_same_weight(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **وشدّةٌ واحدةٌ لصنفين تُعيد الدفن.** من يفرز بالشدّة يجد
        // ٥٧ منخفضةً و٥٢ متوسّطة، لا ١٠٩ متساوية.
        // ══════════════════════════════════════════════════════════════
        $src = $this->collectorSource();

        $over = substr($src, (int) strpos($src, 'function overExposedMethod'), 1800);
        $un = substr($src, (int) strpos($src, 'function unreachedMethod'), 1800);

        $this->assertStringContainsString("severity: 'LOW'", $over,
            'ما يعمل يُبلَّغ بشدّةٍ تساوي ما لا يعمل');

        $this->assertStringContainsString("severity: 'MEDIUM'", $un,
            'العطلُ الحقيقيُّ هبطت شدّتُه');
    }

    /** @test */
    public function the_low_rule_never_tells_the_reader_to_delete(): void
    {
        // **وتوصيةٌ بالحذف على دالّةٍ تعمل تُنتج انحداراً.** هي مُنادَاةٌ
        // فعلاً؛ علاجُها `private` لا المِبضع.
        $src = $this->collectorSource();
        $over = substr($src, (int) strpos($src, 'function overExposedMethod'), 1800);

        $this->assertStringContainsString('private', $over,
            'القاعدةُ الدنيا لا تقترح خفضَ الرؤية');

        $this->assertStringContainsString('لا تُحذف', $over,
            'القاعدةُ الدنيا لا تنهى عن الحذف صراحةً — '
            . 'وقارئٌ مستعجلٌ يحذف دالّةً تعمل');
    }

    /** @test */
    public function a_method_called_by_its_literal_name_is_not_reported_dead(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والنداءُ باسمٍ نصّيٍّ نداء.** قِيست ثلاثةُ مواضعَ في شيفرة
        // الإنتاج تنادي دالّةً باسمٍ في متغيّر:
        //
        //     SafePaymentService:953   $this->receipts->{$method}(…)
        //                              $method = 'issueDebit'|'issueCredit'
        //
        // فبلا قراءة النصّ كان `issueDebit` يُبلَّغ «لا يناديها أحد»
        // **وهي تُنادى في كلّ إيصالِ دفعٍ آمن**. ومن صدّق التقريرَ حذفها.
        //
        // وأغلق هذا التوسيعُ **١٣ اكتشافاً كاذباً** في جولةٍ واحدة.
        // ══════════════════════════════════════════════════════════════
        $dir = sys_get_temp_dir() . '/saher-literal-' . uniqid();
        mkdir($dir, 0777, true);

        file_put_contents($dir . '/Caller.php', <<<'PHP'
        <?php
        class Caller {
            public function run(string $direction) {
                $method = $direction === 'debit' ? 'issueDebit' : 'issueCredit';
                return $this->receipts->{$method}([]);
            }
        }
        PHP);

        file_put_contents($dir . '/Receipts.php', <<<'PHP'
        <?php
        class Receipts {
            public function issueDebit(array $d) { return 1; }
            public function neverCalledAnywhere(array $d) { return 2; }
        }
        PHP);

        $index = new CallerIndex([$dir]);
        $decl = realpath($dir . '/Receipts.php');

        $this->assertGreaterThan(0, $index->callersOutside('issueDebit', $decl),
            'دالّةٌ تُنادى باسمٍ نصّيٍّ تُحسب ميّتة — ومن صدّق التقريرَ حذف '
            . 'إيصالَ كلّ دفعةٍ آمنة');

        // **والتوسيعُ لا يُعمي التقريرَ كلَّه**: ما لا يُذكر البتّةَ يبقى
        // مبلَّغاً. وإلّا استُبدل ضجيجٌ بصمت.
        $this->assertSame(0, $index->callersOutside('neverCalledAnywhere', $decl),
            'صار كلُّ شيءٍ يُحسب مُنادىً — فالتقريرُ لا يجد عطلاً أبداً');

        array_map('unlink', glob($dir . '/*'));
        rmdir($dir);
    }

    /** @test */
    public function the_live_report_is_actually_split(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ولا يُكتفى بقراءة الشيفرة.** الجامعُ يُشغَّل على المشروع
        // كما هو، ويُتحقَّق أنّ القاعدتين تخرجان فعلاً — فقاعدةٌ مكتوبةٌ
        // ولا تُنتج نتيجةً واحدةً هي نفسُها «مبنيٌّ ولا يُوصَل إليه».
        // ══════════════════════════════════════════════════════════════
        $rows = \Illuminate\Support\Facades\DB::table('saher_findings')
            ->where('source_code', 'DATA_TRUTH')
            ->where('status', '!=', 'RESOLVED')
            ->selectRaw('rule_id, count(*) c')
            ->groupBy('rule_id')
            ->pluck('c', 'rule_id');

        if ($rows->isEmpty()) {
            $this->markTestSkipped('لا جردَ محفوظٌ في هذه القاعدة — يُشغَّل `saher:scan-data`');
        }

        $this->assertArrayHasKey('SAHER.DATA.SERVICE_METHOD_OVER_EXPOSED', $rows->toArray(),
            'القاعدةُ الدنيا مكتوبةٌ ولا تُنتج شيئاً — فالفرزُ لم يقع');
    }
}
