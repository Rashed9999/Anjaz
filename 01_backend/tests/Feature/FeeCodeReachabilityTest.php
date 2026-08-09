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
 * `FeeSchemeController` يتحقّق `in:FeeScheme::CODES`. فتلك القائمةُ هي
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
     * @var array<string,string> الرمز ⇒ السبب وما يجري اليوم
     */
    private const NOT_WIRED_YET = [
        'CASH_IN' => 'إيداعُ العميل عبر الوكيل يمرّ بـAGENT_DEPOSIT — وهذا الرمز '
            . 'باقٍ لقناةٍ مستقبليّة (إيداع بنكيّ مباشر) ولا يُحصَّل منه شيء.',

        'BILL_PAY' => 'رسمُ الفواتير يُحسب اليوم من عمودَي '
            . '`bill_service_products.fee_amount/fee_percent` في `BillPayService`. '
            . 'فمن يضبط مخطّطاً هنا لا يتغيّر شيء. **وقرارٌ ينتظر**: أهو رسمُ '
            . 'المنصّة فوق رسم المزوّد أم بدله؟ لا يُخترع جوابُه في شيفرة.',

        'SPLIT_BILL' => 'تقسيمُ الفاتورة يُنفَّذ بلا رسمٍ اليوم.',

        'REFUND' => 'الاسترجاعُ بلا رسمٍ اليوم — وهو الأصلح للعميل.',

        'FAMILY_FUND_CONTRIB' => 'صندوقُ العائلة يكتب `fee => 0` صراحةً في '
            . '`FamilyFundService`، فالمساهمةُ مجّانيّةٌ بقرار.',
    ];

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
            if (! in_array($code, FeeScheme::CODES, true)) {
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
        $blob = '';

        foreach ($this->sourceFiles() as $file) {
            $blob .= (string) preg_replace(
                ['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents($file));
        }

        $orphans = [];

        foreach (FeeScheme::CODES as $code) {
            if (str_contains($blob, "'{$code}'")) {
                continue;   // مستهلَك
            }

            if (array_key_exists($code, self::NOT_WIRED_YET)) {
                continue;   // مصرَّحٌ به بسببه
            }

            $orphans[] = $code;
        }

        $this->assertSame([], $orphans, sprintf(
            "رموزٌ يعرضها الأدمنُ ولا يستهلكها أحد، ولا سببَ مكتوب:\n  %s\n"
            . "يضبطها الأدمنُ ويراها فعّالة ولا يُخصم منها ريال.\n"
            . 'إمّا تُوصَل، وإمّا يُكتب سببُها في NOT_WIRED_YET.',
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
        $blob = '';

        foreach ($this->sourceFiles() as $file) {
            $blob .= (string) preg_replace(
                ['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', file_get_contents($file));
        }

        $stale = [];

        foreach (array_keys(self::NOT_WIRED_YET) as $code) {
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
            $this->assertContains($code, FeeScheme::CODES,
                "رمزُ {$code} غيرُ قابلٍ للضبط — يبقى شبّاكُ الوكيل مجّانيّاً بلا سبيلٍ لتسعيره");
        }

        $svc = file_get_contents(app_path('Services/AgentCounterService.php'));

        $this->assertStringContainsString("quote('AGENT_DEPOSIT'", $svc,
            'خدمةُ الشبّاك ما زالت تطلب رمزاً بحروفٍ صغيرة لا يطابق أيّ مخطّط');

        $this->assertStringContainsString("quote('AGENT_WITHDRAW'", $svc,
            'خدمةُ الشبّاك ما زالت تطلب رمزاً بحروفٍ صغيرة لا يطابق أيّ مخطّط');
    }
}
