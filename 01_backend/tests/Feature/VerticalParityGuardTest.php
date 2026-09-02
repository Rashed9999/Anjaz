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

    /**
     * **المرجعُ المجمَّد — ما كان يمنحه المحرّكُ يومَ النقل.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولمَ مجمَّدٌ لا محسوب:** كان هذا الحارسُ يقارن
     * `AccessPresets` بالسجلّ. فلمّا وُصلت `AccessPresets` بالسجلّ صار
     * **يقارن الشيءَ بنفسِه**: يخرج أخضرَ مهما تغيّرت القطاعاتُ كلُّها.
     *
     * وهو أخطرُ صنفٍ في هذا المشروع — حارسٌ يمرّ والعطلُ قائم — وقد وقع
     * هنا **لحظةَ نجاح** النقل الذي بُني ليحرسه.
     *
     * فصار الطرفُ الأوّلُ **نصّاً مجمَّداً** مأخوذاً من المحرّك القديم
     * قبل الوصل (‏`git show` على الالتزام السابق). فأيُّ انزلاقٍ في
     * القطاعات يظهر رقماً بعينه، ولا شيءَ يقيسه بنفسه.
     *
     * **وتغييرُ هذه القائمة قرارُ منتَجٍ لا إصلاحُ اختبار:** من غيّرها
     * يقول ماذا غيّر ولماذا — فالمنحُ يفتح ميزةً على من لم يشترها،
     * والنزعُ يُقفلها على من اشتراها.
     * ══════════════════════════════════════════════════════════════════
     *
     * @return array<string,array<string,array<int,string>>>
     */
    private function frozenEngineOutput(): array
    {
        return [
            'quick_sale' => [
                'free' => ['daily_reports', 'debts', 'merchant_verification', 'notifications', 'profile', 'qr_pay', 'quick_sale', 'receipts', 'receive', 'refunds', 'transfer', 'wallet'],
                'business' => ['daily_reports', 'debts', 'merchant_verification', 'notifications', 'profile', 'qr_pay', 'quick_sale', 'receipts', 'receive', 'refunds', 'transfer', 'wallet'],
                'enterprise' => ['daily_reports', 'debts', 'merchant_verification', 'notifications', 'profile', 'qr_pay', 'quick_sale', 'receipts', 'receive', 'refunds', 'transfer', 'wallet'],
            ],
            'retail' => [
                'free' => ['cashier', 'daily_reports', 'debts', 'merchant_verification', 'notifications', 'payment_requests', 'profile', 'quick_sale', 'qr_pay', 'receipts', 'receive', 'refunds', 'split_bill', 'transfer', 'wallet'],
                'business' => ['cashier', 'daily_reports', 'debts', 'merchant_verification', 'notifications', 'payment_requests', 'profile', 'quick_sale', 'qr_pay', 'receipts', 'receive', 'refunds', 'split_bill', 'transfer', 'wallet'],
                'enterprise' => ['cashier', 'daily_reports', 'debts', 'merchant_verification', 'notifications', 'payment_requests', 'profile', 'quick_sale', 'qr_pay', 'receipts', 'receive', 'refunds', 'split_bill', 'transfer', 'wallet'],
            ],
            'fuel' => [
                'free' => ['daily_reports', 'fuel_pos', 'fuel_pumps', 'fuel_shifts', 'merchant_verification', 'notifications', 'profile', 'qr_pay', 'receipts', 'receive', 'transfer', 'wallet'],
                'business' => ['daily_reports', 'fuel_cards', 'fuel_companies', 'fuel_pos', 'fuel_products', 'fuel_pumps', 'fuel_shifts', 'fuel_variance', 'merchant_verification', 'notifications', 'profile', 'qr_pay', 'receipts', 'receive', 'transfer', 'wallet'],
                'enterprise' => ['daily_reports', 'fuel_cards', 'fuel_companies', 'fuel_pos', 'fuel_products', 'fuel_pumps', 'fuel_shifts', 'fuel_variance', 'merchant_verification', 'notifications', 'profile', 'qr_pay', 'receipts', 'receive', 'transfer', 'wallet'],
            ],
            'pharmacy' => [
                'free' => ['daily_reports', 'debts', 'merchant_verification', 'notifications', 'pharmacy_alerts', 'pharmacy_batches', 'pharmacy_pos', 'pharmacy_products', 'profile', 'quick_sale', 'qr_pay', 'receipts', 'receive', 'transfer', 'wallet'],
                'business' => ['daily_reports', 'debts', 'merchant_verification', 'notifications', 'pharmacy_alerts', 'pharmacy_batch_disposition', 'pharmacy_batches', 'pharmacy_customers', 'pharmacy_pos', 'pharmacy_prescriptions', 'pharmacy_products', 'pharmacy_substitutions', 'profile', 'quick_sale', 'qr_pay', 'receipts', 'receive', 'transfer', 'wallet'],
                'enterprise' => ['daily_reports', 'debts', 'merchant_verification', 'notifications', 'pharmacy_alerts', 'pharmacy_batch_disposition', 'pharmacy_batches', 'pharmacy_customers', 'pharmacy_pos', 'pharmacy_prescriptions', 'pharmacy_products', 'pharmacy_substitutions', 'profile', 'quick_sale', 'qr_pay', 'receipts', 'receive', 'transfer', 'wallet'],
            ],
            'wholesale' => [
                'free' => ['cashier', 'daily_reports', 'debts', 'merchant_verification', 'notifications', 'profile', 'quick_sale', 'qr_pay', 'receipts', 'receive', 'transfer', 'wallet', 'wholesale_collections', 'wholesale_invoices'],
                'business' => ['cashier', 'daily_reports', 'debts', 'merchant_verification', 'notifications', 'profile', 'quick_sale', 'qr_pay', 'receipts', 'receive', 'transfer', 'wallet', 'wholesale_collections', 'wholesale_invoices', 'wholesale_multi_pricing'],
                'enterprise' => ['cashier', 'daily_reports', 'debts', 'merchant_verification', 'notifications', 'profile', 'quick_sale', 'qr_pay', 'receipts', 'receive', 'transfer', 'wallet', 'wholesale_collections', 'wholesale_invoices', 'wholesale_multi_pricing'],
            ],
            'restaurant' => [
                'free' => ['daily_reports', 'debts', 'merchant_verification', 'notifications', 'profile', 'quick_sale', 'qr_pay', 'receipts', 'receive', 'restaurant_kitchen', 'restaurant_orders', 'restaurant_tables', 'transfer', 'wallet'],
                'business' => ['daily_reports', 'debts', 'merchant_verification', 'notifications', 'profile', 'quick_sale', 'qr_pay', 'receipts', 'receive', 'restaurant_kitchen', 'restaurant_orders', 'restaurant_tables', 'transfer', 'wallet'],
                'enterprise' => ['daily_reports', 'debts', 'merchant_verification', 'notifications', 'profile', 'quick_sale', 'qr_pay', 'receipts', 'receive', 'restaurant_kitchen', 'restaurant_orders', 'restaurant_tables', 'transfer', 'wallet'],
            ],
        ];
    }

    /** @test */
    public function every_vertical_and_plan_pair_matches_the_current_engine(): void
    {
        $checked = 0;
        $frozen = $this->frozenEngineOutput();

        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            $vertical = VerticalRegistry::find($biz);

            $this->assertNotNull($vertical,
                "**قطاع «{$biz}» ليس في السجلّ الجديد** — فمن يسأله عنه "
                . 'يجده فارغاً، ويُقفل نشاطُ صاحبه كلُّه.');

            $this->assertArrayHasKey($biz, $frozen,
                "**قطاعٌ جديدٌ بلا مرجعٍ مجمَّد: «{$biz}»** — فلا شيءَ "
                . 'يقول ماذا يجب أن يمنح، والحارسُ يفحص فراغاً.');

            foreach (A::ALL_PLANS as $plan) {
                $checked++;

                $old = $frozen[$biz][$plan];

                // والجديد: المشترك + النواة + عمقُ الباقة.
                $new = $vertical->featuresFor($plan);

                sort($old);
                sort($new);

                $this->assertSame($old, $new, sprintf(
                    "**اختلافٌ في «%s» × «%s».**\n"
                    . "  ينقص الجديدَ : %s\n"
                    . "  ويزيد فيه   : %s\n\n"
                    . 'والنقصُ يُقفل ميزةً على صاحبها، والزيادةُ تفتح ما '
                    . 'لم يُشترَ.',
                    $biz, $plan,
                    implode('، ', array_diff($old, $new)) ?: '—',
                    implode('، ', array_diff($new, $old)) ?: '—'));
            }
        }

        $this->assertSame(
            count(A::ALL_BUSINESS_TYPES) * count(A::ALL_PLANS), $checked,
            'لم تُفحص كلُّ التركيبات — وحارسٌ يفحص بعضَها يقول «تطابق» ولم ينظر');
    }

    /**
     * @test
     *
     * **والمدخلُ العامُّ يُخرج ما يُخرجه المربّع.**
     *
     * فالمرجعُ المجمَّد يحرس السجلَّ، وهذا يحرس **الوصلَ نفسَه**: أن
     * تبقى `AccessPresets` — وهي ما تسأله الخدمةُ فعلاً — تقول ما يقوله
     * المربّع. فلو فُكّ الوصلُ غداً وعادت القوائمُ مكتوبةً بالاسم، سقط
     * هذا وحدَه.
     */
    public function the_public_entrance_still_answers_from_the_boxes(): void
    {
        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            $vertical = VerticalRegistry::find($biz);

            $this->assertSame($vertical->own(), AccessPresets::businessTypeFeatures($biz),
                "«{$biz}»: المدخلُ العامُّ لا يُخرج نواةَ المربّع — الوصلُ انفكّ");

            foreach (A::ALL_PLANS as $plan) {
                $this->assertSame(
                    $vertical->depthFor($plan),
                    AccessPresets::verticalPlanFeatures($biz, $plan),
                    "«{$biz}» × «{$plan}»: العمقُ المُباع لا يأتي من المربّع");
            }
        }
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
