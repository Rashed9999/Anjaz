<?php

namespace Tests\Feature;

use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-CURRENCY-POLICY-001 — **الريالُ اليمنيُّ وحدَه، إلّا في الباقة العليا.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **قرارُ صاحب المشروع بنصّه:**
 *
 *     «العملات اجعلها يمني فقط، في التجار الاشتراك الأعلى يقبل متعدّد
 *      العملات»
 *
 * وقِيس ما هو قائمٌ قبل تغيير سطر، **فإذا القرارُ منفَّذٌ أصلاً**:
 * `F_MULTI_CURRENCY` ممنوحةٌ لـ`merchant_pro` و`enterprise` وحدَهما،
 * و`MerchantCurrencyController::guard()` يردّ ٤٠٢ لمن دونهما.
 *
 * **فلمَ يُكتب حارسٌ لِما يعمل؟** لأنّ ما يعمل بلا حارسٍ ينزلق: قائمةُ
 * الميزات في `AccessPresets` تُعدَّل بيدٍ عند كلّ باقةٍ جديدة، وسطرٌ
 * يُنسخ من `enterprise` إلى `business` يفتح تعدّدَ العملات لمن لم يدفع
 * ثمنَه — **بلا خطأٍ في أيّ سجلّ، ولا شاشةٍ تقول إنّ شيئاً تغيّر.**
 *
 * **وهذا حارسُ عقدٍ لا حارسُ عطل**: يُمسك انزلاقَ الغد لا خطأَ اليوم.
 */
class CurrencyPolicyGuardTest extends TestCase
{
    use RefreshDatabase;

    /** الباقاتُ التي **لا** تملك تعدّدَ العملات — والريالُ اليمنيُّ فيها وحدَه. */
    private const SINGLE_CURRENCY_PLANS = [
        A::PLAN_FREE, A::PLAN_STARTER, A::PLAN_BUSINESS,
    ];

    /** والباقتان العليان. */
    private const MULTI_CURRENCY_PLANS = [
        A::PLAN_MERCHANT_PRO, A::PLAN_ENTERPRISE,
    ];

    private function featuresOf(string $plan): array
    {
        return AccessPresets::planFeatures($plan);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ① الباقاتُ الدنيا بالريال اليمنيّ وحدَه
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * @dataProvider singleCurrencyPlans
     */
    public function a_lower_plan_never_gets_multi_currency(string $plan): void
    {
        $this->assertNotContains(A::F_MULTI_CURRENCY, $this->featuresOf($plan),
            "باقةُ «{$plan}» فُتح لها تعدّدُ العملات — وهي دون الاشتراك "
            . 'الأعلى. وسطرٌ يُنسخ بين الباقات يفعلها بلا خطأٍ في أيّ سجلّ');
    }

    public static function singleCurrencyPlans(): array
    {
        return array_map(fn ($p) => [$p], self::SINGLE_CURRENCY_PLANS);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ② والعليا تملكه — وإلّا صارت ميزةً مدفوعةً لا تُسلَّم
    // ══════════════════════════════════════════════════════════════════

    /**
     * @test
     *
     * @dataProvider multiCurrencyPlans
     */
    public function the_top_plans_do_get_it(string $plan): void
    {
        // **وميزةٌ تُباع ولا تُسلَّم أسوأ من ميزةٍ لا تُباع.**
        $this->assertContains(A::F_MULTI_CURRENCY, $this->featuresOf($plan),
            "باقةُ «{$plan}» تُباع بتعدّد العملات ولا تملكه");
    }

    public static function multiCurrencyPlans(): array
    {
        return array_map(fn ($p) => [$p], self::MULTI_CURRENCY_PLANS);
    }

    // ══════════════════════════════════════════════════════════════════
    //  ③ والبابُ نفسُه محروسٌ لا القائمةُ وحدَها
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function the_endpoint_checks_the_feature_not_only_the_role(): void
    {
        // **وقائمةُ ميزاتٍ لا يقرؤها أحدٌ ليست حاجزاً.** فالمسارُ نفسُه
        // يُسأل: هل يفحص `F_MULTI_CURRENCY`، أم يكتفي بأنّ الطالبَ تاجر؟
        $src = file_get_contents(app_path(
            'Http/Controllers/Api/V1/Amial/MerchantCurrencyController.php'));

        $this->assertStringContainsString('F_MULTI_CURRENCY', $src,
            'مسارُ عملات التاجر يفحص الدورَ ولا يفحص الباقة — '
            . 'فكلُّ تاجرٍ يضيف عملات');

        // **والحارسُ على كلّ فعلٍ لا على القراءة وحدَها** — حاجزُ قراءةٍ
        // يترك بابَ الكتابة مفتوحاً.
        foreach (['index', 'store'] as $action) {
            $this->assertMatchesRegularExpression(
                '~function ' . $action . '\([^)]*\)[^{]*\{\s*\$m = \$this->guard~s', $src,
                "الفعل «{$action}» لا يمرّ بالحارس");
        }
    }

    /** @test */
    public function the_base_currency_is_the_yemeni_rial(): void
    {
        // **والأساسُ يُقال ولا يُفترَض.** رقمٌ بلا عملةٍ رقمٌ صحيحٌ بمعنىً
        // مجهول — وهو الدرسُ الذي كتب «٣٥ ر.ي» على سعرٍ بالريال السعوديّ.
        $src = file_get_contents(app_path(
            'Http/Controllers/Api/V1/Amial/MerchantCurrencyController.php'));

        $this->assertStringContainsString("'base' => 'ر.ي'", $src,
            'العملةُ الأساسُ غيرُ معلنةٍ في الردّ — فالمُنادي يخمّنها');
    }

    // ══════════════════════════════════════════════════════════════════
    //  ④ والعميلُ بالريال اليمنيّ — لا خيارَ له ولا يُسأل
    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function a_customer_is_never_offered_a_currency_choice(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **ونموذجُ بنك عدن يسأل «نوع العملة: يمني — سعودي — دولار».**
        //
        // وقرارُ صاحب المشروع: **اليمنيُّ وحدَه للعملاء**. فلا يُنقَل هذا
        // الحقلُ إلى تسجيل العميل، **وحقلٌ يُعرَض ولا يُخدَم يُنتج وعداً
        // يُكسَر عند أوّل معاملة**.
        // ══════════════════════════════════════════════════════════════
        $wizard = base_path(
            '../02_flutter_app/lib/features/auth/screens/amial_registration_wizard_screen.dart');

        if (! is_file($wizard)) {
            $this->markTestSkipped('معالجُ التسجيل غيرُ موجودٍ في هذه البيئة');
        }

        $src = file_get_contents($wizard);

        // الدخلُ الشهريُّ يُرسَل بعملةٍ **ثابتةٍ معلنة**، لا بمنتقٍ.
        $this->assertStringContainsString("'monthly_income_currency': 'YER'", $src,
            'عملةُ الدخل غيرُ معلنةٍ في التسجيل');

        $this->assertStringNotContainsString('reg-currency-picker', $src,
            'عُرض على العميل منتقي عملات — والقرارُ أنّ اليمنيَّ وحدَه له');
    }

    /** @test */
    public function the_income_currency_defaults_to_the_yemeni_rial_when_unsaid(): void
    {
        // **ولا يُترك فراغاً**: رقمُ دخلٍ بلا عملةٍ لا يُبنى عليه سقف.
        $u = \App\Models\User::factory()->create(['type' => 2]);

        $request = \Illuminate\Http\Request::create('/', 'POST', [
            'monthly_income' => '100000',
        ]);

        \App\Support\Kyc\KycProfileFields::fill($u, $request);
        $u->save();

        $this->assertSame('YER', $u->refresh()->monthly_income_currency,
            'حُفظ دخلٌ بلا عملة — رقمٌ صحيحٌ بمعنىً مجهول');
    }
}
