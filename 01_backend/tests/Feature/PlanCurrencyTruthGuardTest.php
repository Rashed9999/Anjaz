<?php

namespace Tests\Feature;

use App\Support\Access\AccessConstants as A;
use Tests\TestCase;

/**
 * AMIAL-PLAN-CURRENCY-001 — **رقمٌ صحيحٌ بعملةٍ كاذبة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن — قِيس:**
 *
 *     AccessConstants::PLAN_PRICES_SAR = [starter => 15, business => 35, …]
 *     plans_catalog_screen.dart:286     Text('$price ر.ي')
 *     contact_constants.dart:28         '💰 السعر: $priceSar ر.ي / شهرياً'
 *
 * الأسعارُ بالريال **السعوديّ** — كما يقول اسمُ الثابت — وكلُّ رصيدٍ في
 * المنتج بالريال **اليمنيّ**. وأربعةُ مواضعَ في الشاشة ورسالةُ الواتساب
 * تكتب «ر.ي» على الرقم نفسِه.
 *
 * **وهذا أخطرُ من رقمٍ خاطئ:** ٣٥ ر.س ≈ ٢٤٠٠ ر.ي. فمن قرأ «٣٥ ر.ي» ظنّ
 * الباقةَ بثمن كوبِ شاي، ثمّ يُطالَب بسبعين ضعفَه.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والقرارُ: تُقال العملةُ ولا تُحوَّل.**
 *
 * `amial-financial-truth` صريحةٌ في هذا: «Never silently convert
 * currencies». وتحويلُ السعر إلى اليمنيّ يحتاج سعرَ صرفٍ ومصدرَه وطابعَه
 * الزمنيّ — وثلاثتُها غيرُ موجودةٍ في المنتج. **فالتحويلُ الصامتُ يستبدل
 * كذبةً بكذبةٍ أطولَ عمراً.**
 *
 * فصار السعرُ يُرسَل ومعه عملتُه من مصدرٍ واحد
 * (`AccessConstants::PLAN_PRICE_CURRENCY`)، **وتغييرُ التسعير سطرٌ واحد**
 * لا تعديلُ نصٍّ في تسع شاشات.
 */
class PlanCurrencyTruthGuardTest extends TestCase
{
    private function dart(string $rel): string
    {
        $path = base_path('../02_flutter_app/' . $rel);

        $this->assertFileExists($path, "ملفٌّ مفقود: {$rel}");

        // يُنزع التعليقُ أوّلاً — الحارسُ لا يسقط على شرحِ نفسِه (وهو فخٌّ
        // وقع في هذا المشروع أربعَ مرّات).
        return (string) preg_replace('~///[^\n]*|//[^\n]*~', '',
            (string) file_get_contents($path));
    }

    /**
     * @test
     *
     * **لا شاشةَ باقاتٍ تكتب عملةً بيدها.**
     */
    public function no_plan_screen_writes_a_currency_of_its_own(): void
    {
        foreach ([
            'lib/features/plans/screens/plans_catalog_screen.dart',
            'lib/features/plans/screens/my_usage_screen.dart',
            'lib/features/entitlements/screens/my_capabilities_screen.dart',
            'lib/util/contact_constants.dart',
        ] as $rel) {
            $src = $this->dart($rel);

            foreach (['ر.ي', 'ر.س', 'ريال يمني', 'ريال سعودي'] as $literal) {
                $this->assertStringNotContainsString($literal, $src,
                    "«{$rel}» يكتب «{$literal}» بيده على سعرِ باقة — "
                    . 'والعملةُ تأتي من الخادم، وإلّا عاد الرقمُ صحيحاً والعملةُ كاذبة');
            }
        }
    }

    /**
     * @test
     *
     * **وكلُّ حمولةٍ تحمل سعراً تحمل عملتَه.**
     *
     * وهذا هو الحدُّ الحقيقيّ: شاشةٌ نظيفةٌ فوق حمولةٍ بلا عملةٍ تعرض
     * الرقمَ عارياً — وهو أسوأُ من عملةٍ خاطئة، لأنّ القارئ يفترض عملتَه.
     */
    public function every_payload_that_carries_a_price_carries_its_currency(): void
    {
        foreach ([
            'app/Http/Controllers/Api/V1/Amial/AccessController.php',
            'app/Http/Controllers/Api/V1/Amial/EntitlementController.php',
            'app/Services/Access/EntitlementService.php',
            'app/Services/FeatureAccessService.php',
            'app/Exceptions/UsageLimitExceededException.php',
        ] as $rel) {
            $src = (string) file_get_contents(base_path($rel));

            $prices = preg_match_all('~PLAN_PRICES_SAR~', $src);
            $currencies = preg_match_all('~PLAN_PRICE_CURRENCY~', $src);

            $this->assertGreaterThan(0, $currencies,
                "«{$rel}» يرسل سعراً ({$prices} موضعاً) ولا يرسل عملته — "
                . 'فالتطبيقُ يعرض رقماً عارياً ويفترض القارئُ عملتَه');
        }
    }

    /**
     * @test
     *
     * **ومصدرُ العملة واحد.**
     *
     * ثابتٌ واحدٌ يُقرأ في كلّ موضع. فيومَ يُقرَّر التسعيرُ بالريال اليمنيّ
     * — وهو قرارُ صاحب المشروع لا قرارُ شيفرة — يتغيّر **سطرٌ واحد**.
     */
    public function the_currency_has_exactly_one_source(): void
    {
        $this->assertSame('ر.س', A::PLAN_PRICE_CURRENCY,
            'العملةُ المعلَنة تخالف ما تقوله أسماءُ الثوابت (PLAN_PRICES_SAR)');

        $this->assertNotEmpty(A::PLAN_PRICE_CURRENCY,
            'عملةٌ فارغةٌ تُخرج الرقمَ عارياً — وهي حالةُ ما قبل الإصلاح نفسُها');
    }

    /**
     * @test
     *
     * **ورسالةُ الترقية تحمل العملةَ الصحيحة.**
     *
     * هي **أوّلُ ما يقرؤه العميلُ عن التسعير**، وكانت تقول «٣٥ ر.ي» عن
     * ٣٥ ر.س — خطأٌ بمقدار سبعين ضعفاً في رسالةٍ تُرسَل بالاسم.
     */
    public function the_upgrade_message_takes_the_currency_as_a_parameter(): void
    {
        $src = $this->dart('lib/util/contact_constants.dart');

        $this->assertStringContainsString('required String currency', $src,
            'رسالةُ الترقية لا تستقبل العملة — فهي تكتبها بيدها أو تُسقطها');

        $this->assertStringContainsString('$priceSar $currency', $src,
            'رسالةُ الترقية لا تطبع العملةَ مع السعر');
    }
}
