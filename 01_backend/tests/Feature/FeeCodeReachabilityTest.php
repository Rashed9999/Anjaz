<?php

namespace Tests\Feature;

use App\Models\FeeScheme;
use Tests\TestCase;

/**
 * AMIAL-TRUTH-002 — رموزُ الرسوم: ما يُضبط يُطبَّق، وما يُطلب يُضبط.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما كشفه تدقيق `amial-financial-truth`:**
 *
 * `FeeSchemeController` يتحقّق `in:FeeScheme::codes()`. فتلك القائمةُ هي
 * كلُّ ما يستطيع الأدمنُ تسعيرَه، **وما ليس فيها لا يُسعَّر أبداً**.
 *
 * وقِيس فوُجد انفصامٌ في الاتّجاهين:
 *
 * **① رمزٌ يُطلب وليس في القائمة** — `AgentCounterService` كان يطلب
 * `'agent_deposit'` و`'agent_withdraw'`. والمطابقةُ في
 * `FeeService::activeScheme()` نصّيّةٌ حرفيّة، فلا يجد مخطّطاً أبداً
 * ويردّ صفراً. **وهذا سببُ ما في `CLAUDE.md`: «كلّ عمليات الوكيل مجّانية
 * الآن»** — لم يكن نقصَ ضبط، كان اسمين لا يلتقيان.
 *
 * **② رمزٌ في القائمة بلا مستهلك** — يضبطه الأدمنُ ويحفظه ويراه فعّالاً،
 * **ولا يُخصم منه ريالٌ واحد**. زرٌّ يعمل ولا يفعل شيئاً، ولا خطأ في أيّ
 * سجلّ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ حارسٌ لا إصلاحٌ لكلّ رمز:** توصيلُ رمزٍ يعني تحصيلَ رسمٍ لم
 * يكن يُحصَّل — **قرارُ تسعيرٍ لا إصلاحُ عطل**. فيُقاس ويُقال، ويُحسم
 * بقرارٍ صريح. والاستثناءاتُ مكتوبةٌ بأسبابها لا مسكوتٌ عنها.
 */
class FeeCodeReachabilityTest extends TestCase
{
    /**
     * رموزٌ في القائمة لا يستهلكها أحدٌ بعد — **بعلمنا، لا صمتاً**.
     *
     * ══════════════════════════════════════════════════════════════════
     * **AMIAL-FEE-TRUTH-009 — وكانت هذه القائمةُ مكتوبةً هنا حرفيّاً.**
     *
     * فصارت الأسبابُ في موضعين: هنا، وفي الشاشة التي يجب أن تقول للأدمن
     * لماذا لا يُسعَّر هذا الرمز. **وموضعان يفترقان**: يُوصَل رمزٌ فيُرفع
     * استثناؤه من الاختبار وتبقى الشاشةُ تقول «غيرُ موصول» — أو العكس،
     * وهو أخطر.
     *
     * فصار السببُ يُكتب مرّةً واحدةً في `FeeOperationRegistry`، **ومنه
     * يقرأ الحارسُ والشاشةُ معاً**.
     *
     * @return array<string,string> الرمز ⇒ السبب وما يجري اليوم
     */
    private function notWiredYet(): array
    {
        return \App\Support\Fees\FeeOperationRegistry::notWired();
    }

    /**
     * الملفّاتُ التي تُطلب منها الرسوم — تُمسح بحثاً عن الرموز.
     *
     * @return array<int,string>
     */
    private function sourceFiles(): array
    {
        $out = [];

        foreach ([app_path('Services'), app_path('Http/Controllers'),
                  app_path('Traits')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $out[] = $f->getPathname();
                }
            }
        }

        return $out;
    }

    /**
     * شيفرةُ **مستهلكي المحرّك وحدَهم** — بلا تعليقات.
     *
     * ══════════════════════════════════════════════════════════════════
     * كان المسحُ يشمل كلَّ ملفّات `Services` و`Controllers` و`Traits`.
     * فذكرُ رمزٍ في **جدول ترجمةٍ للعرض** يُقرأ استهلاكاً: أسقط
     * `FeeProfitReportService` — وفيه `'cash_in' => 'CASH_IN'` لترجمة
     * اسم الحركة — استثناءَ `CASH_IN` الصحيحَ وهو لم يُوصَل بعد.
     *
     * **ومستهلكُ رسمٍ تعريفُه واحد: ملفٌّ ينادي المحرّك.**
     */
    private function consumerBlob(): string
    {
        $blob = '';

        foreach ($this->sourceFiles() as $file) {
            $src = (string) preg_replace(
                ['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents($file));

            if (! preg_match('~->(?:calculate|quote)\s*\(~', $src)) {
                continue;
            }

            $blob .= $src;
        }

        return $blob;
    }

    /**
     * @test
     *
     * **كلُّ رمزٍ يُطلب من `FeeService` موجودٌ في القائمة القابلة للضبط.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا هو الاتّجاهُ الذي أخفى العطلَ شهوراً: الخدمةُ تطلب، والمحرّكُ
     * لا يجد، فيردّ صفراً **بلا استثناءٍ ولا تحذير**. والصفرُ يُقرأ
     * «مجّانيّ بقرار» لا «رمزٌ لا وجود له».
     */
    public function every_requested_fee_code_is_configurable_by_the_admin(): void
    {
        $requested = [];

        foreach ($this->sourceFiles() as $file) {
            $src = (string) preg_replace(
                ['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents($file));

            // `->calculate('CODE'` و`->quote('CODE'` — النداءان الوحيدان
            // اللذان يبلغان `activeScheme()`.
            preg_match_all(
                "/->(?:calculate|quote)\(\s*'([A-Za-z_]+)'/", $src, $m);

            foreach ($m[1] ?? [] as $code) {
                $requested[$code] = str_replace(app_path() . '/', '', $file);
            }
        }

        $this->assertNotEmpty($requested,
            'لم يُقرأ أيُّ نداءِ رسم — التعبيرُ لم يعد يطابق، والحارسُ يمرّ فارغاً');

        $unknown = [];

        foreach ($requested as $code => $where) {
            if (! in_array($code, FeeScheme::codes(), true)) {
                $unknown[] = "{$code}  ← {$where}";
            }
        }

        $this->assertSame([], $unknown, sprintf(
            "رموزُ رسومٍ تُطلب ولا يستطيع الأدمنُ ضبطَها — فتردّ صفراً أبداً:\n  %s\n"
            . 'والصفرُ يُقرأ «مجّانيٌّ بقرار» لا «رمزٌ لا وجود له».',
            implode("\n  ", $unknown),
        ));
    }

    /**
     * @test
     *
     * **وكلُّ رمزٍ في القائمة يستهلكه أحد — أو يُصرَّح بأنّه لا يُطبَّق.**
     *
     * ══════════════════════════════════════════════════════════════════
     * فرمزٌ معروضٌ في اللوحة بلا مستهلكٍ يضبطه الأدمنُ ويحفظه ويراه
     * فعّالاً — **ولا يُخصم منه ريالٌ واحد**.
     *
     * ولا يُمنع وجودُه: يُمنع **السكوتُ عنه**. فمن أضاف رمزاً ولم يوصله
     * يكتب سببَه هنا، فيُراجَع. (الادّعاءُ يُراجَع والصمتُ لا يُراجَع.)
     */
    public function every_configurable_fee_code_is_consumed_or_declared(): void
    {
        $blob = $this->consumerBlob();

        $orphans = [];

        foreach (FeeScheme::codes() as $code) {
            if (str_contains($blob, "'{$code}'")) {
                continue;   // مستهلَك
            }

            if (array_key_exists($code, $this->notWiredYet())) {
                continue;   // مصرَّحٌ به بسببه
            }

            $orphans[] = $code;
        }

        $this->assertSame([], $orphans, sprintf(
            "رموزٌ يعرضها الأدمنُ ولا يستهلكها أحد، ولا سببَ مكتوب:\n  %s\n"
            . "يضبطها الأدمنُ ويراها فعّالة ولا يُخصم منها ريال.\n"
            . 'إمّا تُوصَل، وإمّا يُكتب سببُها في `FeeOperationRegistry`.',
            implode("\n  ", $orphans),
        ));
    }

    /**
     * @test
     *
     * **والاستثناءُ لا يُترك بعد أن يُوصَل.**
     *
     * فاستثناءٌ قديمٌ لرمزٍ صار مستعمَلاً يُطمئن على غير موضعه، ويُخفي
     * لو انفصل ثانيةً. (حارسٌ يكذب أسوأ من غيابه.)
     */
    public function no_stale_exception_remains_after_a_code_is_wired(): void
    {
        $blob = $this->consumerBlob();

        $stale = [];

        foreach (array_keys($this->notWiredYet()) as $code) {
            if (str_contains($blob, "'{$code}'")) {
                $stale[] = $code;
            }
        }

        $this->assertSame([], $stale,
            'رموزٌ مكتوبٌ أنّها غيرُ موصولة وهي مستعمَلة: ' . implode('، ', $stale)
            . ' — يُرفع استثناؤها.');
    }

    /**
     * @test
     *
     * **ورمزا شبّاك الوكيل موجودان — فتسعيرُه ممكنٌ من اللوحة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * هذا الحارسُ يخصّ العطلَ الذي وُلد منه الملفّ. و`CLAUDE.md` يذكر
     * «ضبط الرسوم» بين ما يمنع الإطلاق — **والسببُ كان هنا**.
     */
    public function the_agent_counter_can_finally_be_priced(): void
    {
        foreach (['AGENT_DEPOSIT', 'AGENT_WITHDRAW'] as $code) {
            $this->assertContains($code, FeeScheme::codes(),
                "رمزُ {$code} غيرُ قابلٍ للضبط — يبقى شبّاكُ الوكيل مجّانيّاً بلا سبيلٍ لتسعيره");
        }

        $svc = file_get_contents(app_path('Services/AgentCounterService.php'));

        $this->assertStringContainsString("quote('AGENT_DEPOSIT'", $svc,
            'خدمةُ الشبّاك ما زالت تطلب رمزاً بحروفٍ صغيرة لا يطابق أيّ مخطّط');

        $this->assertStringContainsString("quote('AGENT_WITHDRAW'", $svc,
            'خدمةُ الشبّاك ما زالت تطلب رمزاً بحروفٍ صغيرة لا يطابق أيّ مخطّط');
    }
}
