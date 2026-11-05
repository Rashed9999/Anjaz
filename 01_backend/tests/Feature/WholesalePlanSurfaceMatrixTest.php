<?php

namespace Tests\Feature;

use App\Services\FeatureAccessService;
use App\Support\Access\AccessConstants as A;
use Tests\TestCase;

/**
 * AMIAL-WHOLESALE-SCOPE-001 — ما يظهر في تصاميم الجملة يجب أن يتبع
 * الاستحقاقات الحقيقية لا أن يتحول إلى قائمة ثابتة لكل التجار أو لكل الباقات.
 */
class WholesalePlanSurfaceMatrixTest extends TestCase
{
    /** @test */
    public function free_wholesale_keeps_the_vertical_core_without_paid_catalog_depth(): void
    {
        $f = $this->featuresFor(A::PLAN_FREE);

        $this->assertContains(A::F_WHOLESALE_INVOICES, $f);
        $this->assertContains(A::F_WHOLESALE_COLLECTIONS, $f);
        $this->assertContains(A::F_DEBTS, $f);
        $this->assertContains(A::F_REFUNDS, $f);

        $this->assertNotContains(A::F_PRODUCTS, $f);
        $this->assertNotContains(A::F_INVENTORY, $f);
        $this->assertNotContains(A::F_CUSTOMERS, $f);
        $this->assertNotContains(A::F_ADVANCED_REPORTS, $f);
        $this->assertNotContains(A::F_WHOLESALE_MULTI_PRICING, $f);
    }

    /**
     * ══════════════════════════════════════════════════════════════════
     * **الطبقةُ الوسطى — وكانت طبقتين.**
     *
     * كان هنا اختباران: `starter` يفتح عمقَ الكتالوج ويمنع الزبائنَ
     * والتقارير، و`business` يفتحهما. **ووحّد صاحبُ المشروع الباقاتِ في
     * ثلاثٍ** (‏`PLAN_STARTER` صار اسماً ثانياً لـ`PLAN_BUSINESS`)، فصار
     * الاختباران يقيسان **الطبقةَ نفسَها ويطلبان منها ضدَّين**: يُثبت
     * أحدُهما `F_CUSTOMERS` ويَنفيه الآخر.
     *
     * فسقط أحدُهما — **على قرارِ تسعيرٍ سليمٍ لا على عطل**. واسمُ اختبارٍ
     * يقول `starter` وهو يقيس `business` **يكذب على قارئه** ويُرسله خلف
     * طبقةٍ لا تُباع.
     *
     * فدُمجا في واحدٍ يصف الطبقةَ القائمة: عمقُ الكتالوج **والزبائنُ
     * والتقارير والتسعيرُ المتعدّد معاً** — وحدُّها الأعلى ما تبيعه
     * «مؤسسة» وحدَها: منفذُ البرمجة وحساباتُ الشركات.
     */
    /** @test */
    public function business_wholesale_opens_catalog_depth_customers_and_advanced_reporting(): void
    {
        $f = $this->featuresFor(A::PLAN_BUSINESS);

        foreach ([
            // عمقُ الكتالوج — كان في `starter`.
            A::F_PRODUCTS,
            A::F_INVENTORY,
            A::F_BARCODE,
            A::F_INVENTORY_AUDIT,
            A::F_LOW_STOCK_ALERTS,
            // والطبقةُ التجاريّة فوقه.
            A::F_CUSTOMERS,
            A::F_SUPPLIERS,
            A::F_PURCHASES,
            A::F_ADVANCED_REPORTS,
            A::F_EXCEL_EXPORT,
            // **وعمقُ القطاع نفسُه** — انتقل إلى هنا بالتوحيد: كان
            // «تاجر محترف» يبيعه، وقد أُلغيت. ومصدرُه `verticalPlanFeatures`
            // لا `planFeatures`، فلا يُرى إلّا بسؤال المُحلِّل كاملاً.
            A::F_WHOLESALE_MULTI_PRICING,
        ] as $feature) {
            $this->assertContains($feature, $f);
        }

        // **والحدُّ الأعلى يبقى حدّاً** — وإلّا فما فوقه لا يُباع.
        $this->assertNotContains(A::F_API_ACCESS, $f);
        $this->assertNotContains(A::F_CORPORATE_ACCOUNTS, $f);
    }

    /**
     * **والطبقةُ العليا — وكانت طبقتين كذلك** (`merchant_pro` صار اسماً
     * ثانياً لـ`enterprise`). ولم يتناقضا فبقيا يمرّان، **وهذا أخطرُ من
     * السقوط**: اختباران يقيسان شيئاً واحداً يُقرآن تغطيتين.
     */
    /** @test */
    public function enterprise_wholesale_opens_multi_pricing_and_platform_capabilities(): void
    {
        $f = $this->featuresFor(A::PLAN_ENTERPRISE);

        foreach ([
            // ولا يتغيّر القطاعُ بارتفاع الباقة — يُضاف إليه لا يُستبدل.
            A::F_WHOLESALE_INVOICES,
            A::F_PRODUCTS,
            A::F_CUSTOMERS,
            // وما تفتحه هذه الطبقةُ وحدَها.
            A::F_WHOLESALE_MULTI_PRICING,
            A::F_BRANCHES,
            A::F_MULTI_CURRENCY,
            A::F_AUDIT_LOG,
            A::F_API_ACCESS,
            A::F_CORPORATE_ACCOUNTS,
        ] as $feature) {
            $this->assertContains($feature, $f);
        }
    }

    /**
     * **واسمٌ مهجورٌ يشير إلى خارج الفهرس يفتح سطحاً لا يُباع.**
     *
     * ══════════════════════════════════════════════════════════════════
     * `PLAN_STARTER` و`PLAN_MERCHANT_PRO` باقيان أسماءً للتوافق، وهما
     * **مقروءان في الشيفرة والاختبارات**. فلو أشار أحدُهما يوماً إلى رمزٍ
     * ليس في `ALL_PLANS` لَـفتح `planFeatures` سطحاً لا تعرفه اللوحةُ ولا
     * يقبله المُتحقِّق — ولا اختبارَ يسأل عنه.
     *
     * **والفهرسُ يُقرأ من مصدره** فلا يشيخ الرقمُ ثلاثةً مكتوباً.
     */
    /** @test */
    public function every_retired_plan_name_still_resolves_inside_the_live_catalogue(): void
    {
        foreach ([
            'PLAN_STARTER' => A::PLAN_STARTER,
            'PLAN_MERCHANT_PRO' => A::PLAN_MERCHANT_PRO,
        ] as $name => $code) {
            $this->assertContains($code, A::ALL_PLANS,
                "«{$name}» يشير إلى «{$code}» وليست في الفهرس المُباع — "
                . 'فتُفتح ميزاتٌ بباقةٍ لا تقبلها اللوحة');
        }

        // **والتطبيعُ يُجرَّب من الطرف الآخر**: النصُّ القديمُ كما يصل من
        // صفٍّ قديمٍ في القاعدة يجب أن ينتهي إلى باقةٍ حيّة.
        foreach (['starter', 'merchant_pro'] as $legacy) {
            $this->assertContains(A::canonicalPlan($legacy), A::ALL_PLANS,
                "«{$legacy}» في صفٍّ قديمٍ لا يُطبَّع إلى باقةٍ حيّة");
        }
    }

    /**
     * ══════════════════════════════════════════════════════════════════
     * **يُسأل المُحلِّلُ نفسُه، لا تُركَّب طبقاتُه هنا من جديد.**
     *
     * كان هذا يجمع ثلاثاً بيده — `roleBase` + `businessTypeFeatures` +
     * `planFeatures` — **وهي أربعٌ في `FeatureAccessService`**: الرابعةُ
     * `verticalPlanFeatures` (‏عمقُ القطاع المُباع بالباقة)، وأُضيفت
     * حين وُحّدت الباقاتُ في ثلاث.
     *
     * فصار الاختبارُ يقيس **تركيبةً لا يراها مستعمِلٌ واحد**: يقول إنّ
     * «التسعير المتعدّد» مغلقٌ في كلّ الباقات، وهو مفتوحٌ فعلاً من
     * «الأعمال» فما فوق. **وتعريفان للاستحقاق يفترقان أوّلَ ما يتغيّر
     * أحدُهما** — وقد افترقا.
     *
     * ولا يُصلَح بإضافة سطرٍ رابعٍ هنا: ذلك يؤجّل الافتراقَ إلى الطبقة
     * الخامسة. فيُسأل المصدرُ الواحد.
     */
    private function featuresFor(string $plan): array
    {
        return app(FeatureAccessService::class)->resolveFeatures(
            role: A::ROLE_MERCHANT,
            verificationLevel: A::VERIFICATION_BASIC,
            businessType: A::BIZ_WHOLESALE,
            plan: $plan,
        );
    }
}
