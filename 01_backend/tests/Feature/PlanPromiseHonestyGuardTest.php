<?php

namespace Tests\Feature;

use App\Services\Access\PlanComparisonService;
use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use Tests\TestCase;

/**
 * AMIAL-PLAN-HONESTY-001 — **الجدولُ يَعِد بما يُعطى، لا بما في القائمة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **سأل صاحبُ المشروع:** «هل تستحقُّ الباقاتُ الدفعَ مقابل الفروقات؟ وهل
 * تعارضٌ بينهم؟ وهل مميّزاتٌ وصفحاتٌ مبنيّةٌ ولا يُوصَل إليها؟».
 *
 * وقِيس، **فالمحرّكُ سليمٌ والشاشةُ وحدَها كانت تكذب:**
 *
 *     ما تَعِد به «الأعمال» في الشاشة  →  ٥ قدراتِ تجزئة
 *     ما تأخذه صيدليّةٌ منها فعلاً      →  **صفر**
 *
 * `AccessPresets::planFeatures()` قائمةُ الباقة **خاماً**، وليست ما يصل
 * التاجر: `FeatureAccessService::resolveFeatures` تمرّ بعدها على
 * `CapabilityRegistry` فتنزع كلَّ قدرةٍ لا تنطبق على نوع النشاط.
 * **والشاشةُ كانت تقرأ الأولى.**
 *
 * وأسوأُ ما فيه أنّ الملاحظةَ أسفلَ الجدول كانت تُسمّي «صيدليّة · محطّة
 * وقود · جملة» **ولا تذكر التجزئة** — فيقرؤها صاحبُ الصيدليّة عكسَ
 * مرادها: «قدراتُ الصيدليّة لا تفتحها الترقيةُ، إذن قدراتُ التجزئة
 * المذكورةُ تحت الباقة تُفتَح». وهي لا تُفتَح. **وعدٌ لا يُوفى**
 * (القاعدة التاسعة).
 *
 * **وتصحيحٌ لِما قِيل في التقرير:** قلتُ إنّ `branch_reports` «مبنيّةٌ
 * ولا شاشةَ تناديها» — وكان خطأً: بحثتُ عن رمز القدرة في شيفرة التطبيق
 * ولم أبحث عن مسار النداء. والبابُ قائمٌ («تقرير الفرع» ← `repo.report`)،
 * والحالةُ الأخيرةُ هنا تُثبّته لئلّا يُهدَم ويُظنّ يتيماً ثانيةً.
 */
class PlanPromiseHonestyGuardTest extends TestCase
{
    private function svc(): PlanComparisonService
    {
        return app(PlanComparisonService::class);
    }

    /** @return array<int,string> */
    private function promised(?string $type): array
    {
        $out = [];

        foreach ($this->svc()->catalogue($type)['plans'] as $plan) {
            foreach ($plan['adds'] as $cap) {
                $out[] = $cap['code'];
            }
        }

        return $out;
    }

    /** @test */
    public function nothing_promised_to_a_pharmacy_is_stripped_at_runtime(): void
    {
        // **العطلُ بعينه.** وكلُّ نشاطٍ يُجرَّب لا الصيدليّةُ وحدَها:
        // إصلاحُ واحدٍ يترك الباقين. (القاعدة الرابعة.)
        $types = [A::BIZ_RETAIL, A::BIZ_PHARMACY, A::BIZ_WHOLESALE,
            A::BIZ_RESTAURANT, A::BIZ_FUEL, A::BIZ_QUICK_SALE];

        $access = app(FeatureAccessService::class);
        $broken = [];

        foreach ($types as $t) {
            $granted = $access->resolveFeatures(
                A::ROLE_MERCHANT, 'verified', $t, A::PLAN_ENTERPRISE);

            foreach ($this->promised($t) as $code) {
                if (! in_array($code, $granted, true)) {
                    $broken[] = "{$t} → {$code}";
                }
            }
        }

        $this->assertSame([], $broken,
            "قدراتٌ تَعِد بها شاشةُ الباقات ولا يصل إليها صاحبُها:\n  "
            .implode("\n  ", $broken)."\n\n"
            .'والمحرّكُ ينزعها في `CapabilityRegistry`. فمن رقّى ليحصل '
            .'عليها لا يجدها — ووعدٌ لا يُوفى أسوأ من غيابه.');
    }

    /** @test */
    public function a_pharmacy_is_never_shown_a_retail_only_capability(): void
    {
        $promised = $this->promised(A::BIZ_PHARMACY);
        $retail = array_values(array_filter($promised,
            fn ($c) => str_starts_with($c, 'retail.')));

        $this->assertSame([], $retail,
            'ما زالت شاشةُ الباقات تَعِد صاحبَ الصيدليّة بقدرات تجزئة: '
            .implode('، ', $retail));

        // **وصاحبُ التجزئة يراها** — فالمرشِّحُ يفرّق ولا يحذف للجميع.
        $this->assertNotEmpty(array_filter($this->promised(A::BIZ_RETAIL),
            fn ($c) => str_starts_with($c, 'retail.')),
            'حُذفت قدراتُ التجزئة عن صاحب التجزئة أيضاً — '
            .'فالمرشِّحُ يقصّ بدل أن يميّز');
    }

    /** @test */
    public function the_note_names_what_is_withheld_instead_of_naming_three_sectors(): void
    {
        // **الملاحظةُ المكتوبةُ تشيخ وتُقرأ عكسَ مرادها.** فتُبنى من
        // الفرق المحسوب وتقول كم قدرةً حُجبت وأمثلةً بأسمائها.
        $note = $this->svc()->catalogue(A::BIZ_PHARMACY)['vertical_note'];

        $this->assertStringNotContainsString('(صيدليّة · محطّة وقود · جملة)', $note,
            'عادت الملاحظةُ المكتوبةُ يدويّاً — وهي تُسمّي ثلاثةَ أنشطةٍ '
            .'وتُهمل الرابع، فيقرؤها صاحبُه عكسَ مرادها');

        $this->assertGreaterThan(0,
            $this->svc()->catalogue(A::BIZ_PHARMACY)['withheld_count'],
            'لا يُقال لصاحب الصيدليّة أنّ في الباقات قدراتٍ لن تصله');

        // **وصاحبُ التجزئة لا يُقال له إنّ شيئاً محجوب** — فالرسالةُ
        // تُحسب ولا تُلصَق على الجميع.
        $this->assertSame(0,
            $this->svc()->catalogue(A::BIZ_RETAIL)['withheld_count']);
    }

    /** @test */
    public function the_ladder_still_buys_something_real_in_every_sector(): void
    {
        // **«هل تستحقُّ الدفع؟»** — وباقةٌ لا تضيف شيئاً لنشاطٍ تُباع له
        // بلا مقابل. وهذا يقيسه لكلّ نشاطٍ لا للمجموع.
        foreach ([A::BIZ_RETAIL, A::BIZ_PHARMACY, A::BIZ_WHOLESALE,
            A::BIZ_RESTAURANT, A::BIZ_FUEL, A::BIZ_QUICK_SALE] as $t) {

            $plans = $this->svc()->catalogue($t)['plans'];

            foreach (array_slice($plans, 1) as $step) {
                $this->assertGreaterThan(0, $step['adds_count'],
                    "باقةُ «{$step['label']}» لا تضيف شيئاً لنشاط «{$t}» — "
                    .'فهي تُباع له بلا مقابل');
            }
        }
    }

    /** @test */
    public function the_comparison_is_cached_per_business_type_not_once_for_all(): void
    {
        // **مفتاحٌ واحدٌ للجميع أخطرُ من غياب الذاكرة**: أوّلُ قارئٍ
        // يملؤها بنشاطه فيراها البقيّةُ بعينه.
        $code = implode("\n", array_filter(
            explode("\n", (string) file_get_contents(
                app_path('Http/Controllers/Api/V1/Amial/AccessController.php'))),
            fn ($l) => ! str_starts_with(ltrim($l), '//')));

        $this->assertStringContainsString(
            "'amial_plans_comparison:'.(\$businessType ?: 'any')", $code,
            'ذاكرةُ المقارنة بمفتاحٍ واحدٍ لكلّ الأنشطة — '
            .'فيرى صاحبُ الصيدليّة جدولَ صاحب البقالة');
    }

    /** @test */
    public function every_sold_capability_still_has_a_door_somewhere(): void
    {
        // **«صفحاتٌ مبنيّةٌ ولا يُوصَل إليها».** والأبوابُ ثلاثة: كتالوجُ
        // «خدمات التاجر»، وخريطةُ `CapabilityScreens`، وأبوابٌ داخل شاشةٍ
        // قائمة (دورُ موظّفٍ · قسمٌ في القائمة · زرٌّ في شاشة الفروع).
        $lib = base_path('../02_flutter_app/lib');
        $hub = (string) file_get_contents($lib.'/features/merchant/screens/merchant_services_hub_screen.dart');
        $map = (string) file_get_contents($lib.'/features/entitlements/capability_screens.dart');

        // أبوابٌ داخل شاشاتٍ قائمة — تُذكَر بأسمائها ولا تُترك تخميناً.
        $inline = [
            'operations_manager' => 'دورُ موظّفٍ في شاشة الموظّفين',
            'financial_manager' => 'دورُ موظّفٍ في شاشة الموظّفين',
            'corporate_credit_limits' => 'قسمٌ في القائمة الجانبيّة',
            'branch_reports' => 'زرُّ «تقرير الفرع» في شاشة الفروع',
        ];

        $orphans = [];

        foreach (AccessPresets::planFeatures(A::PLAN_ENTERPRISE) as $code) {
            if (isset($inline[$code])) {
                continue;
            }
            if (str_contains($hub, "'{$code}'") || str_contains($map, "'{$code}'")) {
                continue;
            }
            $orphans[] = $code;
        }

        $this->assertSame([], $orphans,
            "قدراتٌ تُباع في الباقات ولا بابَ لها في أيّ شاشة:\n  "
            .implode("\n  ", $orphans)."\n\n"
            .'مبنيٌّ ولا يُوصَل إليه — وهو نمطُ العطل الأكثرُ تكراراً هنا.');
    }

    /** @test */
    public function the_branch_report_door_still_calls_its_endpoint(): void
    {
        // **تصحيحٌ مُثبَّت.** قِيل في التقرير إنّها يتيمة، والبحثُ كان عن
        // رمز القدرة لا عن مسار النداء. فتُقاس السلسلةُ كاملةً هنا.
        $lib = base_path('../02_flutter_app/lib');

        $this->assertStringContainsString('تقرير الفرع',
            (string) file_get_contents($lib.'/features/branches/screens/branches_management_screen.dart'),
            'ذهب زرُّ «تقرير الفرع» — فصارت القدرةُ تُباع بلا باب');

        $this->assertStringContainsString('/report',
            (string) file_get_contents($lib.'/features/branches/domain/repositories/branches_repo.dart'),
            'الزرُّ قائمٌ ولا ينادي نقطةَ النهاية');

        $this->assertTrue(
            collect(app('router')->getRoutes())->contains(
                fn ($r) => str_contains($r->uri(), 'branches/{id}/report')),
            'ذهبت نقطةُ النهاية والزرُّ يناديها');
    }
}
