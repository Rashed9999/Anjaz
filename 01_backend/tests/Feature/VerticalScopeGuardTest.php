<?php

namespace Tests\Feature;

use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use App\Support\Access\CapabilityRegistry;
use Tests\TestCase;

/**
 * AMIAL-VERTICAL-SCOPE-001 — **الباقةُ تفتح العمق، والنشاطُ يقرّر الانطباق.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الثمن، بسؤال صاحب المشروع:**
 *
 *     «لماذا تاجر وقود لديه أصناف ومخزون… ألا تفحص مسار التاجر؟»
 *
 * وقِيس فكان محقّاً:
 *
 *     resolveFeatures(merchant, fuel, business) = ٣٩ ميزة
 *     ومنها: products · barcode · inventory · inventory_audit ·
 *            low_stock_alerts · purchases · suppliers · quick_sale · debts
 *
 * **وإعلانُ كلٍّ منها تجزئةٌ بنصّه**: `retail.product.*` · `/retail`.
 *
 * والسببُ سطرٌ واحد: `planFeatures($plan)` قائمةٌ مسطّحةٌ تُصبّ على كلّ
 * تاجرٍ يدفع **بلا نظرٍ إلى قطاعه** — وفوقها مكتوبٌ منذ كُتبت: «نوع
 * النشاط يحدد الانطباق… ولا تمنح قدرة قطاعية لقطاع آخر». **نيّةٌ مكتوبةٌ
 * ولا سطرَ ينفّذها.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وأخطرُ ما فيه أنّه طريقٌ مسدود، لا زيادةُ كرم:**
 *
 * الشاشةُ الوحيدةُ التي تبيع من ذلك الكتالوج **ترفض أن تُفتح له** —
 * `CashierPosScreen` تردّ حسابَ الوقود إلى `FuelSaleScreen` بحاجزٍ قطاعيّ.
 * فيُباع كتالوجٌ ومخزونٌ وباركود ثمّ يُمنَع البيعُ منها.
 *
 * **والحكمُ من صاحب المشروع لا من اجتهادي** — «تدقيق شامل لقطاعات
 * التجار»، جدولُ «لا يجوز أن يرى»: محطة وقود ⇒ «البيع السريع بدون
 * التجزئة، كاشير منتجات عام».
 */
class VerticalScopeGuardTest extends TestCase
{
    private FeatureAccessService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(FeatureAccessService::class);
    }

    /** @return array<int,string> */
    private function featuresFor(string $businessType, string $plan): array
    {
        return $this->svc->resolveFeatures(
            A::ROLE_MERCHANT, A::VERIFICATION_VERIFIED, $businessType, $plan,
        );
    }

    /**
     * @test
     *
     * **محطّةُ الوقود لا تُعطى كتالوجَ رفوفٍ ولا جردَ مخزون.**
     *
     * وتُسمّى واحدةً واحدةً لا بنمطٍ عامّ: قدرةٌ تُضاف غداً بلا قطاعٍ
     * معلَنٍ تسقط في الفحص الأخير من هذا الملفّ، لا هنا.
     */
    public function a_fuel_station_never_gets_the_dry_goods_catalogue(): void
    {
        $forbidden = [
            A::F_PRODUCTS => 'كتالوجُ أصناف — وكتالوجُ المحطّة `fuel_products`',
            A::F_BARCODE => 'باركود — ولا باركودَ على لتر بنزين',
            A::F_INVENTORY => 'مخزونُ رفوف — ومخزونُ المحطّة خزّاناتٌ تُقاس',
            A::F_INVENTORY_AUDIT => 'جردُ رفوف — والمحطّةُ تُصالَح بـ`fuel.recon`',
            A::F_LOW_STOCK_ALERTS => 'تنبيهُ نفادِ صنف',
            A::F_SUPPLIERS => 'موردون — وتوريدُ المحطّة `fuel.delivery`',
            A::F_PURCHASES => 'أوامرُ شراء',
            A::F_QUICK_SALE => 'البيع السريع — ومنعُه بنصّ التدقيق',
            A::F_DEBTS => 'دفترُ دَين — وائتمانُ المحطّة ببطاقاتٍ لا بدفتر',
            A::F_CASHIER => 'كاشيرُ منتجاتٍ عامّ',
        ];

        $leaks = [];

        // **وتُفحَص الباقاتُ كلُّها** — فالتسريبُ من العمق المدفوع،
        // ومن فحص المجّانيّة وحدَها لم يفحص شيئاً.
        foreach (A::ALL_PLANS as $plan) {
            $has = $this->featuresFor(A::BIZ_FUEL, $plan);

            foreach ($forbidden as $code => $why) {
                if (in_array($code, $has, true)) {
                    $leaks[] = sprintf('  %-18s على باقة %-10s — %s', $code, $plan, $why);
                }
            }
        }

        $this->assertSame([], $leaks,
            "**قدراتُ تجزئةٍ تصل حسابَ محطّة وقود:**\n" . implode("\n", $leaks)
            . "\n\nوهي طريقٌ مسدود لا كرم: `CashierPosScreen` تردّ حسابَ "
            . "الوقود إلى `FuelSaleScreen`، **فالكتالوجُ يُملأ ولا يُفرَّغ**.\n"
            . 'والتدقيق: «محطة وقود — لا يجوز أن يرى: البيع السريع بدون '
            . 'التجزئة، كاشير منتجات عام».');
    }

    /**
     * @test
     *
     * **وما تحتاجه المحطّةُ يبقى كاملاً** — فحارسٌ يقصّ الحقَّ عطلٌ لا حماية.
     */
    public function the_fuel_station_still_gets_its_whole_vertical(): void
    {
        $has = $this->featuresFor(A::BIZ_FUEL, A::PLAN_BUSINESS);

        $required = [
            A::F_FUEL_POS, A::F_FUEL_PUMPS, A::F_FUEL_SHIFTS,
            A::F_FUEL_PRODUCTS, A::F_FUEL_COMPANIES,
            A::F_FUEL_CARDS, A::F_FUEL_VARIANCE,
            // وما هو مشترَكٌ بحقّ: المال والفريقُ والتقارير.
            A::F_WALLET, A::F_RECEIPTS, A::F_EMPLOYEES, A::F_MULTI_POS,
            A::F_SHIFT_CLOSE, A::F_DAILY_REPORTS, A::F_PROFIT_REPORTS,
            A::F_EXPENSES, A::F_EXCEL_EXPORT, A::F_REFUNDS,
        ];

        $missing = array_values(array_diff($required, $has));

        $this->assertSame([], $missing,
            "**قُصّت عن المحطّة قدراتٌ هي لها:**\n  " . implode(' · ', $missing)
            . "\n\nوالمقصودُ حدُّ القطاع لا تجويعُه. ومساحتُها في التدقيق: "
            . '«خزانات، توريد، قياس، ورديات، شركات، فروقات، إيصالات، '
            . 'بيع وقود، مضخات/مسدسات» — ومعها مالُها وفريقُها وتقاريرُها.');
    }

    /**
     * @test
     *
     * **والتجزئةُ لم تُمَسّ.** فإصلاحٌ يقصّ قطاعاً سليماً أسوأُ من العطل.
     */
    public function retail_keeps_every_capability_it_had(): void
    {
        $has = $this->featuresFor(A::BIZ_RETAIL, A::PLAN_BUSINESS);

        foreach ([
            A::F_PRODUCTS, A::F_BARCODE, A::F_INVENTORY, A::F_INVENTORY_AUDIT,
            A::F_LOW_STOCK_ALERTS, A::F_SUPPLIERS, A::F_PURCHASES,
            A::F_QUICK_SALE, A::F_DEBTS, A::F_CASHIER,
        ] as $code) {
            $this->assertContains($code, $has,
                "سقطت «{$code}» عن تاجر التجزئة — **والإصلاحُ قصَّ قطاعاً سليماً**، "
                . 'وهو أسوأ من العطل الذي عالجه.');
        }
    }

    /**
     * @test
     *
     * **الجملة لها كتالوجها العام؛ الصيدلية لها كتالوجها الطبي.**
     *
     * الصيدلية لا ترث شاشة التجزئة لمجرد أنها تبيع صنفاً: حقول الدفعات
     * والصلاحية والوصفة جزء من منتجها الحقيقي، لا طبقة تجزئة فوقه.
     */
    public function wholesale_keeps_general_catalogue_while_pharmacy_keeps_its_own_surface(): void
    {
        $wholesale = $this->featuresFor(A::BIZ_WHOLESALE, A::PLAN_BUSINESS);
        foreach ([A::F_PRODUCTS, A::F_INVENTORY, A::F_SUPPLIERS] as $code) {
            $this->assertContains($code, $wholesale,
                "سقطت «{$code}» عن الجملة — وهي تحتاج كتالوجها العام.");
        }

        $pharmacy = $this->featuresFor(A::BIZ_PHARMACY, A::PLAN_BUSINESS);
        foreach ([A::F_PRODUCTS, A::F_INVENTORY, A::F_SUPPLIERS] as $code) {
            $this->assertNotContains($code, $pharmacy,
                "وصلت «{$code}» إلى الصيدلية — وهي شاشة تجزئة لا كتالوج الدواء.");
        }
        foreach ([A::F_PHARMACY_PRODUCTS, A::F_PHARMACY_BATCHES, A::F_PHARMACY_ALERTS] as $code) {
            $this->assertContains($code, $pharmacy,
                "سقطت «{$code}» عن الصيدلية — وهو جزء من عملها الأساسي.");
        }
    }

    /**
     * @test
     *
     * **والمصفاةُ تسأل السجلَّ ولا تكتب قائمةً ثانية.**
     *
     * كتالوجان يفترقان عطلٌ وقع في هذا المشروع قبلُ: سجلُّ الخادم فيه ٤٧
     * قدرة وقائمةٌ مكتوبةٌ بيدٍ فيها ٢٧، فقرأ التاجرُ «١٢ من ٢٧» —
     * **ومقامٌ لا يُحسب من مصدره**. فيُثبَت هنا أنّ لا قائمةَ ثانية.
     */
    public function the_filter_reads_the_registry_not_a_second_list(): void
    {
        $src = file_get_contents(app_path('Services/FeatureAccessService.php'));

        $this->assertStringContainsString('CapabilityRegistry::find', $src,
            '**المصفاةُ لا تسأل سجلَّ القدرات** — فإن كانت تقرأ قائمةً '
            . 'مكتوبةً هنا فقد وُلد كتالوجٌ ثانٍ يشيخ وحدَه.');

        $this->assertStringContainsString('appliesTo', $src,
            'المصفاةُ لا تستعمل `Capability::appliesTo` — وهي الآلةُ '
            . 'التي تسألها شاشةُ «قدراتي» أصلاً.');
    }

    /**
     * @test
     *
     * **وما لا قدرةَ له في السجلّ يمرّ — ولا يُحذف بالشكّ.**
     *
     * فبعضُ الميزات أساسٌ بلا مدخلٍ في الكتالوج (وقد كُشف ذلك في
     * `payment_requests`: ممنوحةٌ ومستعمَلةٌ وغائبةٌ عن السجلّ). وحذفُها
     * بحجّة القطاع **يُقفل ما لم يقصده أحد**، ولا رسالةَ تقول لماذا.
     */
    public function a_feature_absent_from_the_registry_is_not_silently_dropped(): void
    {
        $planned = AccessPresets::planFeatures(A::PLAN_BUSINESS);

        $unregistered = array_values(array_filter(
            $planned,
            static fn (string $f): bool => CapabilityRegistry::find($f) === null,
        ));

        if ($unregistered === []) {
            $this->markTestSkipped(
                'كلُّ ميزات الباقة معلَنةٌ في السجلّ — ولا حالةَ تُفحص. '
                . 'ويبقى الحارسُ لأنّ غداً قد يُضاف ما ليس فيه.');
        }

        $has = $this->featuresFor(A::BIZ_FUEL, A::PLAN_BUSINESS);

        foreach ($unregistered as $f) {
            $this->assertContains($f, $has,
                "**حُذفت «{$f}» عن المحطّة وهي غيرُ معلَنةٍ في السجلّ** — "
                . 'والغيابُ عن الكتالوج ليس منعاً قطاعيّاً، بل ثغرةٌ في '
                . 'الكتالوج. فتُعلَن هناك، ولا تُقفَل هنا بالصمت.');
        }
    }
}
