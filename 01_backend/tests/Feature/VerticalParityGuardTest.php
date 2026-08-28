<?php

namespace Tests\Feature;

use App\Domain\Plans\MerchantPlan;
use App\Domain\Verticals\MerchantVertical;
use App\Domain\Verticals\VerticalRegistry;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use Tests\TestCase;

/**
 * AMIAL-VERTICAL-OOP-001 — **التطابق: الجديدُ يقول ما يقوله القديم بالضبط.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **هذا الحارسُ هو ما يجعل التحويلَ جولةً واحدةً لا عشراً.**
 *
 * المربّعاتُ الثلاثة تُبنى بنسخِ ما تُخرجه `AccessPresets` اليوم **حرفاً
 * بحرف** — لا تحسينَ ولا اجتهاد. وهذا الحارسُ يقارن الاثنين على **ثماني
 * عشرةَ تركيبة**: ستّةُ قطاعاتٍ × ثلاثُ باقات.
 *
 * فإن تطابقت الثمانيَ عشرة، صار تحويلُ المدخل إلى السجلّ الجديد آمناً
 * **رياضيّاً لا ظنّاً**. وإن اختلفت واحدةٌ، ظهر الاختلافُ بالاسم قبل أن
 * يصل مستعمِلاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ولمَ لا يُكتفى بمجموعة الاختبارات:** ٣٢٩٢ اختباراً تمرّ اليوم على
 * الشيفرة القديمة. وتحويلُ المصدر بلا هذا الحارس يعني أن يُكتشف
 * الانحرافُ **حيث يظهر**، لا حيث وقع — وقد كلّف ذلك هذا المشروعَ ثلاثةَ
 * أشهر.
 */
class VerticalParityGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        VerticalRegistry::flush();
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الثمانيَ عشرةَ تركيبة
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function every_vertical_and_plan_pair_matches_the_current_engine(): void
    {
        $checked = 0;

        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            $vertical = VerticalRegistry::find($biz);

            $this->assertNotNull($vertical,
                "**قطاع «{$biz}» ليس في السجلّ الجديد** — فمن يسأله عنه "
                . 'يجده فارغاً، ويُقفل نشاطُ صاحبه كلُّه.');

            foreach (A::ALL_PLANS as $plan) {
                $checked++;

                // القديم: ما يمنحه المحرّكُ القائم اليوم.
                $old = array_unique(array_merge(
                    AccessPresets::roleBase(A::ROLE_MERCHANT),
                    AccessPresets::businessTypeFeatures($biz),
                    AccessPresets::verticalPlanFeatures($biz, $plan),
                ));

                // الجديد: المشترك + النواة + عمقُ الباقة.
                $new = $vertical->featuresFor($plan);

                sort($old);
                sort($new);

                $this->assertSame($old, $new, sprintf(
                    "**اختلافٌ في «%s» × «%s».**\n"
                    . "  ينقص الجديدَ : %s\n"
                    . "  ويزيد فيه   : %s\n\n"
                    . 'والنقصُ يُقفل ميزةً على صاحبها، والزيادةُ تفتح ما '
                    . 'لم يُشترَ. فلا يُحوَّل المدخلُ قبل أن تُطابق '
                    . 'الثمانيَ عشرةَ تركيبةُ كلُّها.',
                    $biz, $plan,
                    implode('، ', array_diff($old, $new)) ?: '—',
                    implode('، ', array_diff($new, $old)) ?: '—'));
            }
        }

        $this->assertSame(
            count(A::ALL_BUSINESS_TYPES) * count(A::ALL_PLANS), $checked,
            'لم تُفحص كلُّ التركيبات — وحارسٌ يفحص بعضَها يقول «تطابق» ولم ينظر');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② المربّع الثاني: الباقةُ تطابق مصدرَها
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_plan_box_mirrors_the_catalogue_and_invents_nothing(): void
    {
        foreach (A::ALL_PLANS as $code) {
            $plan = MerchantPlan::of($code);

            $this->assertSame(A::PLAN_LIMITS[$code], $plan->limits(),
                "حدودُ «{$code}» في المربّع تفترق عن الفهرس — ونسختان "
                . 'لحقيقةٍ واحدةٍ تفترقان أوّلَ ما يتغيّر أحدُهما');

            $this->assertSame((int) A::PLAN_PRICES_SAR[$code], $plan->monthlyPrice(),
                "سعرُ «{$code}» يفترق عن الفهرس");

            $this->assertSame(A::PLAN_PRICE_CURRENCY, $plan->currency(),
                '**العملةُ اختُرعت** — والأسعارُ بالريال السعوديّ والأرصدةُ '
                . 'باليمنيّ، فرقمٌ بعملةٍ خاطئةٍ يُقرأ بثمن كوبِ شاي');
        }
    }

    /**
     * **وثلاثُ حالاتٍ للسقف لا اثنتان** — وخلطُها عطلٌ ماليّ.
     *
     * @test
     */
    public function a_zero_ceiling_is_not_an_unlimited_one(): void
    {
        $free = MerchantPlan::of(A::PLAN_FREE);
        $top  = MerchantPlan::of(A::ALL_PLANS[count(A::ALL_PLANS) - 1]);

        $this->assertTrue($top->isUnlimited('products'),
            'أعلى الباقات بسقفِ منتجاتٍ منتهٍ — فما الذي تبيعه؟');

        $this->assertFalse($free->isUnlimited('products'),
            '**المجّانيّةُ بلا حدّ** — فلا معنى لباقةٍ تُشترى');

        // `allows` تفرّق الثلاثةَ صراحةً.
        $this->assertTrue($top->allows('products', 10_000_000));
        $this->assertFalse($free->allows('products', $free->maxProducts() + 1));
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ المربّع الثالث: الوارثُ يضيف ولا يستبدل
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function every_vertical_inherits_the_shared_box_whole(): void
    {
        foreach (VerticalRegistry::all() as $code => $vertical) {
            foreach (A::ALL_PLANS as $plan) {
                foreach (MerchantVertical::shared() as $feature) {
                    $this->assertContains($feature, $vertical->featuresFor($plan),
                        "**قطاع «{$code}» على «{$plan}» فقد «{$feature}» من "
                        . 'المشترك** — والوارثُ يضيف ولا يستبدل.');
                }
            }
        }
    }

    /**
     * **ولا يُعيد قطاعٌ ما في الصندوق.**
     *
     * من أعادها ظنّ أنّه يفتحها وهي مفتوحة. والأخطرُ عكسُه: من نزعها من
     * الصندوق ظنّ أنّه أغلقها، وهي مفتوحةٌ من قائمة القطاع.
     *
     * @test
     */
    public function no_vertical_repeats_what_the_shared_box_already_grants(): void
    {
        $box = MerchantVertical::shared();

        foreach (VerticalRegistry::all() as $code => $vertical) {
            $repeated = array_intersect($vertical->own(), $box);

            $this->assertSame([], array_values($repeated),
                "**قطاع «{$code}» يُعيد ما في الصندوق**: "
                . implode('، ', $repeated) . ' — فنزعُها من الصندوق بلا أثر.');
        }
    }

    /**
     * **والمجّانيّةُ لا عمقَ لها** — العمقُ هو ما يُباع.
     *
     * @test
     */
    public function the_free_plan_gets_no_paid_depth(): void
    {
        foreach (VerticalRegistry::all() as $code => $vertical) {
            if ($vertical->paidDepth() === []) {
                continue;
            }

            $free = $vertical->featuresFor(A::PLAN_FREE);

            foreach ($vertical->paidDepth() as $features) {
                foreach ($features as $f) {
                    $this->assertNotContains($f, $free,
                        "**«{$f}» تُمنح مجّاناً في «{$code}»** وهي مُباعة — "
                        . 'فتُفرَغ الباقاتُ من معناها.');
                }
            }
        }
    }
}
