<?php

namespace Tests\Feature;

use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\CapabilityRegistry;
use Tests\TestCase;

/**
 * AMIAL-ORPHAN-CAPS-001 — **مبنيّةٌ ومُسعَّرةٌ ومقفلةٌ معاً.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمنُ الذي دُفع:** خمسُ قدراتٍ كانت تُعلَن
 * `minPlan(PLAN_BUSINESS)`، ولها شاشاتٌ في `CapabilityScreens`، **ولها
 * نقاطُ نهايةٍ تعمل وعليها وسيطُ `capability:` باسمها** — ولا يمنحها
 * مانحٌ واحد.
 *
 * فيدفع تاجرُ التجزئة خمسةً وثلاثين ريالاً، وتَعِده صفحةُ التسعير،
 * ويفتح الشاشة، **فيردُّه الوسيطُ نفسُه**. والسلسلةُ كلُّها سليمةٌ إلّا
 * حلقةَ المنح.
 *
 * **وهذا مقلوبُ «تُباع ولا وجودَ لها»** (‏`pharmacy_customers`): تلك
 * مُعلَنةٌ بلا نقطةِ نهاية، فأُخرجت من الباقات بـ`comingSoon()`. وهذه
 * **موجودةٌ ومبيعةٌ ومقفلة** — فعلاجُها المنحُ لا الإخراج.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولا يُقاس المنحُ من القائمة بل من الخدمة.**
 *
 * `AccessPresets::planFeatures()` قائمةٌ مسطّحة، و`resolveFeatures()`
 * تُصفّيها بـ`Capability::appliesTo()`. **فقائمةٌ فيها الاسمُ لا تعني
 * أنّ التاجرَ يناله** — وقد قِيس هذا الفرقُ بعينه في هذه الجلسة:
 * `planFeatures(business)` أرجعت الخمسَ كاملةً بينما بدا أنّ التاجرَ
 * لا ينالها.
 *
 * فالحارسُ يسأل **الخدمةَ** لا القائمة.
 */
class RetailDepthReachesTheMerchantGuardTest extends TestCase
{
    /**
     * الخمسُ التي أُغلقت — وكلٌّ منها عليها وسيطُ `capability:` في مسار.
     *
     * @var array<int,string>
     */
    private const FIVE = [
        'retail.catalog',
        'retail.variants',
        'retail.price_versions',
        'retail.waste',
        'retail.returns.by_line',
    ];

    private function featuresFor(string $businessType, string $plan): array
    {
        return app(FeatureAccessService::class)->resolveFeatures(
            A::ROLE_MERCHANT, 'verified', $businessType, $plan, []);
    }

    /**
     * **① وتاجرُ التجزئة على «الأعمال» ينالها كلَّها.**
     *
     * وهو الثمنُ الذي تُعلنه كلُّ واحدةٍ عن نفسها.
     */
    /** @test */
    public function a_business_plan_goods_merchant_actually_receives_all_five(): void
    {
        foreach ([A::BIZ_RETAIL, A::BIZ_WHOLESALE, A::BIZ_PHARMACY] as $biz) {
            $features = $this->featuresFor($biz, A::PLAN_BUSINESS);

            $missing = array_values(array_diff(self::FIVE, $features));

            $this->assertSame([], $missing, sprintf(
                "**قدراتٌ يُعلن كلٌّ منها `minPlan(business)` ولا تصل «%s» "
                . "على باقة الأعمال:**\n  %s\n\n"
                . 'ولكلٍّ منها وسيطُ `capability:` في مسارٍ يعمل — فيدفع '
                . 'التاجرُ ويُردُّ عند الباب.',
                $biz, implode('، ', $missing)));
        }
    }

    /**
     * **② والمجّانيّةُ لا تنالها** — فالثمنُ المُعلَن يُحترَم في الجهتين.
     *
     * (حارسٌ يُثبت المنحَ ولا يُثبت المنعَ يقبل «افتحها للجميع» علاجاً.)
     */
    /** @test */
    public function the_free_plan_receives_none_of_them(): void
    {
        $features = $this->featuresFor(A::BIZ_RETAIL, A::PLAN_FREE);

        $leaked = array_values(array_intersect(self::FIVE, $features));

        $this->assertSame([], $leaked, sprintf(
            "**قدراتٌ مدفوعةٌ تسرّبت إلى الباقة المجّانيّة:**\n  %s",
            implode('، ', $leaked)));
    }

    /**
     * **③ ومحطّةُ الوقود لا تنالها بأيّ باقة.**
     *
     * ══════════════════════════════════════════════════════════════════
     * وهذا شرطُ صحّة العلاج لا زينةٌ فوقه: منحُها في `planFeatures` بلا
     * نطاقٍ يُعطي «الهالك» و«متغيّرات الصنف» لمحطّةِ وقود — **وهو العطلُ
     * الذي كشفه صاحبُ المشروع بعينه** («لماذا تاجر وقود لديه أصناف
     * ومخزون؟»).
     *
     * فنُطّقت الخمسُ بـ`GOODS` قبل منحها، و`resolveFeatures` تُصفّي
     * بـ`appliesTo()`.
     * ══════════════════════════════════════════════════════════════════
     */
    /** @test */
    public function a_fuel_station_never_receives_them_on_any_plan(): void
    {
        foreach (A::ALL_PLANS as $plan) {
            $features = $this->featuresFor(A::BIZ_FUEL, $plan);

            $leaked = array_values(array_intersect(self::FIVE, $features));

            $this->assertSame([], $leaked, sprintf(
                "**قدراتُ تجزئةٍ وصلت محطّةَ وقودٍ على باقة «%s»:**\n  %s\n\n"
                . 'وهي لا تنطبق على قطاعها — والترقيةُ لا تُغيّر ذلك.',
                $plan, implode('، ', $leaked)));
        }
    }

    /**
     * **④ والنطاقُ مُعلَنٌ في السجلّ لا مفروضٌ في الخدمة وحدَها.**
     *
     * فلو نُزع `businessTypes()` عن إحداها بقيت الخدمةُ تُصفّي بـ«لا
     * قدرةَ لها فتمرّ» — **وهو المرورُ الافتراضيّ**، فتصل الوقودَ صامتةً.
     */
    /** @test */
    public function each_of_the_five_declares_its_business_scope(): void
    {
        $unscoped = [];

        foreach (self::FIVE as $code) {
            $cap = CapabilityRegistry::find($code);

            $this->assertNotNull($cap, "اختفت «{$code}» من السجلّ.");

            if ($cap->appliesToAllBusinessTypes()) {
                $unscoped[] = $code;
            }
        }

        $this->assertSame([], $unscoped, sprintf(
            "**قدراتٌ ممنوحةٌ في `planFeatures` بلا نطاقِ قطاع:**\n  %s\n\n"
            . 'و`planFeatureAppliesTo` تُمرّر ما لا قدرةَ له **عمداً** '
            . '(لئلّا يُقفل أساسٌ بالشكّ) — فقدرةٌ بلا نطاقٍ تمرّ إلى كلّ '
            . 'قطاع، ومنها محطّةُ الوقود.',
            implode('، ', $unscoped)));
    }
}
