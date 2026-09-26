<?php

namespace Tests\Feature;

use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use App\Support\Access\CapabilityRegistry;
use Tests\TestCase;

/**
 * AMIAL-RESTAURANT-GATE-001 — **طاولاتٌ بلا طلبات.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * قطاعُ المطاعم كان يمنح `restaurant_tables` **ولا يمنح**
 * `restaurant_orders` ولا `restaurant_kitchen`.
 *
 * فيفتح صاحبُ المطعم حسابَه، ويرى طاولاتِه، **ولا يستطيع فتحَ طلبٍ
 * واحد**: يردّ الخادمُ 402 «قطاع المطاعم متاح لحسابات المطاعم» — وهو
 * **حسابُ مطعمٍ فعلاً**. ورسالةٌ تنفي عن الحساب صفتَه وهو يحملها ترسل
 * صاحبَها إلى الدعم بلا معلومة.
 *
 * **والقطاعُ كلُّه معطَّلٌ عمليّاً**: طاولةٌ لا يُفتح عليها طلبٌ لا تبيع.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والعطلُ صنفٌ لا حالة:** قطاعٌ يُمنَح نصفَ نواته يبدو مفتوحاً ويعمل
 * إلى أوّل فعلٍ حقيقيّ. ولا يظهر في اختبارٍ يسأل «أيرى التاجرُ شاشتَه؟»
 * — يظهر عند الضغط وحدَه.
 */
class VerticalCoreIsWholeGuardTest extends TestCase
{
    /**
     * نواةُ كلّ قطاع — ما لا يعمل القطاعُ بدونه.
     *
     * **وليست كلَّ ميزاته**: العمقُ المُباع بالباقة يبقى في
     * `verticalPlanFeatures`، ولا يُطلَب هنا.
     *
     * @return array<string,array<int,string>>
     */
    private function cores(): array
    {
        return [
            A::BIZ_RESTAURANT => [
                A::F_RESTAURANT_TABLES,
                A::F_RESTAURANT_ORDERS,   // بلا طلبٍ لا مطعم
                A::F_RESTAURANT_KITCHEN,
            ],
            A::BIZ_FUEL => [
                A::F_FUEL_POS,
            ],
            A::BIZ_PHARMACY => [
                A::F_PHARMACY_POS,
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════

    /** @test */
    public function every_vertical_grants_its_whole_core_not_half_of_it(): void
    {
        foreach ($this->cores() as $biz => $core) {
            $granted = AccessPresets::businessTypeFeatures($biz);

            foreach ($core as $feature) {
                $this->assertContains($feature, $granted,
                    "**قطاع «{$biz}» لا يمنح «{$feature}»** وهي من نواته. "
                    . 'فيفتح صاحبُه حسابَه ويرى شاشتَه، ثمّ يُردّ بـ402 '
                    . 'عند أوّل فعلٍ حقيقيّ — ورسالةٌ تنفي عنه صفتَه وهو '
                    . 'يحملها ترسله إلى الدعم بلا معلومة.');
            }
        }
    }

    /**
     * **وما يُمنَح لا يكون «قريباً».**
     *
     * مِنحةٌ من `businessTypeFeatures` تتجاوز إعلانَ «قريباً» في سجلّ
     * القدرات — فتُرسَم الشاشةُ ويُضغَط الزرُّ ولا شيءَ خلفه. **ومصدران
     * لحقيقةٍ واحدةٍ يفترقان أوّلَ ما يتغيّر أحدُهما**، وقد افترقا فعلاً
     * في `pharmacy_customers`.
     *
     * @test
     */
    public function nothing_declared_coming_soon_is_granted_by_a_business_type(): void
    {
        // ══════════════════════════════════════════════════════════════
        // **والمِنَحُ مصدران لا مصدر.** `businessTypeFeatures` تمنح نواةَ
        // القطاع، و`verticalPlanFeatures` تمنح عمقَه المُباع بالباقة.
        //
        // وجُرّب هذا بالعكس فمرّ: أُعيدت `pharmacy_customers` إلى المصدر
        // **الثاني** والحارسُ يفحص الأوّل — **فمرّ على العطل الذي بُني
        // له**. ومصدرٌ لا يُفحَص كأنّه غير موجود.
        // ══════════════════════════════════════════════════════════════
        foreach (A::ALL_BUSINESS_TYPES as $biz) {
            $granted = AccessPresets::businessTypeFeatures($biz);

            foreach (A::ALL_PLANS as $plan) {
                $granted = array_merge($granted,
                    AccessPresets::verticalPlanFeatures($biz, $plan));
            }

            foreach (array_unique($granted) as $feature) {
                $cap = CapabilityRegistry::find($feature);

                if ($cap === null) {
                    continue;   // ليست قدرةً معلَنةً — خارج نطاق هذا الحارس
                }

                $this->assertNotSame('coming_soon', $cap->toArray()['status'] ?? 'available',
                    "**«{$feature}» مُعلَنةٌ «قريباً» ويمنحها قطاع «{$biz}»** — "
                    . 'فتظهر جاهزةً في «قدراتي»، ويُضغَط زرُّها ولا شيءَ خلفه.');
            }
        }
    }
}
