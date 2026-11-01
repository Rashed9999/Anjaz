<?php

namespace App\Support\Access;

use App\Support\Access\AccessConstants as A;

/**
 * AMIAL-BUSINESS-PLANS-001 — ميزاتُ «أميال أعمال» وحدودُها، تُباع في باقة أميال باي نفسها.
 *
 * **اشتراكٌ واحد.** التاجر لا يدفع لأميال أعمال باقةً ثانية؛ باقتُه هنا (`merchant_profiles.subscription_plan`)
 * تحدّد ما يُفتح هناك. والأسعارُ والأسماءُ من `AccessConstants` لا تُنسخ.
 *
 * **ميزةٌ جديدة = سطرٌ واحدٌ هنا.** أميال أعمال لا تعرف الباقات؛ تسأل «هل `ab.x` متاحة؟» فقط.
 * فالرمزُ عقدٌ بين النظامين: لا يُعاد تسميته، ويُضاف ولا يُحذف (يُوقف بـ `retired`).
 *
 * وما يمنحه الأدمن لتاجرٍ بعينه خارج باقته يُكتب في `extra_features` بالرمز نفسه.
 */
final class BusinessFeatureRegistry
{
    /** @var array<string, array{ar:string, en:string, min_plan:string, group:string, retired?:bool}> */
    public const FEATURES = [
        'ab.pos' => ['ar' => 'نقطة البيع', 'en' => 'Point of sale', 'min_plan' => A::PLAN_FREE, 'group' => 'sales'],
        'ab.quick_sale' => ['ar' => 'البيع السريع والميزان', 'en' => 'Quick sale & scale', 'min_plan' => A::PLAN_FREE, 'group' => 'sales'],
        'ab.customers_credit' => ['ar' => 'العملاء والآجل', 'en' => 'Customers & credit', 'min_plan' => A::PLAN_FREE, 'group' => 'sales'],
        'ab.wallet_refunds' => ['ar' => 'الاسترداد إلى المحفظة', 'en' => 'Wallet refunds', 'min_plan' => A::PLAN_FREE, 'group' => 'sales'],
        'ab.inventory' => ['ar' => 'المخزون والجرد', 'en' => 'Inventory & counts', 'min_plan' => A::PLAN_FREE, 'group' => 'stock'],
        'ab.purchases' => ['ar' => 'المشتريات والموردون', 'en' => 'Purchases & suppliers', 'min_plan' => A::PLAN_BUSINESS, 'group' => 'stock'],
        'ab.accounting' => ['ar' => 'المحاسبة والقيود', 'en' => 'Accounting & journals', 'min_plan' => A::PLAN_BUSINESS, 'group' => 'finance'],
        // سجلّ التدقيق المفصّل للمؤسسة — كما `F_AUDIT_LOG` في أميال باي.
        'ab.audit_log' => ['ar' => 'سجل التدقيق', 'en' => 'Audit log', 'min_plan' => A::PLAN_ENTERPRISE, 'group' => 'finance'],
        // تشغيلُ القطاع نفسه مجّانيّ كما في أميال باي (نقطة بيع الوقود والصيدلية `minPlan(FREE)`)؛
        // والمتقدّم منه (مشتريات · محاسبة) بالباقة.
        'ab.wholesale' => ['ar' => 'الجملة والشرائح', 'en' => 'Wholesale & price tiers', 'min_plan' => A::PLAN_FREE, 'group' => 'verticals'],
        'ab.pharmacy' => ['ar' => 'الصيدلية والتشغيلات', 'en' => 'Pharmacy & batches', 'min_plan' => A::PLAN_FREE, 'group' => 'verticals'],
        'ab.fuel' => ['ar' => 'محطة الوقود', 'en' => 'Fuel station', 'min_plan' => A::PLAN_FREE, 'group' => 'verticals'],
        'ab.restaurant' => ['ar' => 'المطعم والمطبخ', 'en' => 'Restaurant & kitchen', 'min_plan' => A::PLAN_FREE, 'group' => 'verticals'],
        // ADR 0013 (أميال أعمال) — الفرع الرئيسي للجميع؛ الثاني فما بعده ومراكز التكلفة بالباقة.
        'ab.multi_branch' => ['ar' => 'فروع ومستودعات متعدّدة', 'en' => 'Multiple branches & warehouses', 'min_plan' => A::PLAN_BUSINESS, 'group' => 'organization'],
        'ab.cost_centers' => ['ar' => 'مراكز التكلفة', 'en' => 'Cost centers', 'min_plan' => A::PLAN_BUSINESS, 'group' => 'organization'],
    ];

    /**
     * حدودُ أميال أعمال لكل باقة. null = بلا حد. (حدود أميال باي نفسها في `AccessConstants::PLAN_LIMITS`.)
     *
     * @var array<string, array<string, int|null>>
     */
    public const LIMITS = [
        A::PLAN_FREE => ['users' => 2, 'products' => 200],
        A::PLAN_BUSINESS => ['users' => 5, 'products' => 5000],
        A::PLAN_ENTERPRISE => ['users' => null, 'products' => null],
    ];

    public static function exists(string $code): bool
    {
        return isset(self::FEATURES[$code]);
    }

    /** @return list<string> */
    public static function availableOn(string $plan): array
    {
        $rank = CapabilityRegistry::planRank($plan);

        return array_keys(array_filter(self::FEATURES,
            fn (array $f) => empty($f['retired']) && CapabilityRegistry::planRank($f['min_plan']) <= $rank));
    }
}
