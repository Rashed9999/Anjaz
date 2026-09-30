<?php

namespace App\Services\Merchant;

use App\Services\Access\EntitlementService;
use App\Support\Access\AccessConstants as A;

/**
 * يركّب قائمة بوابة مالك المنشأة من القطاع والاستحقاقات الفعلية.
 *
 * لا يقرر هذا الصنف صلاحيةً ولا يفتح باباً خادمياً؛ ذلك يبقى حصراً في
 * EntitlementService والوسطاء. دوره أن يمنع واجهة المالك من وعده بوظائف
 * تخص قطاعاً آخر أو باقة لا يملكها.
 */
final class MerchantPortalNavigationService
{
    /** @var array<string,array{workspace:string,sales:string,products:string,reports:string,workspace_capability:string,sales_capability:string,product_capability:?string}> */
    private const VERTICALS = [
        A::BIZ_QUICK_SALE => [
            'workspace' => 'مركز البيع السريع',
            'sales' => 'مبيعات ونقاط البيع',
            'products' => 'الأصناف والأسعار',
            'reports' => 'مبيعات اليوم',
            'workspace_capability' => A::F_QUICK_SALE,
            'sales_capability' => A::F_QUICK_SALE,
            'product_capability' => A::F_PRODUCTS,
        ],
        A::BIZ_RETAIL => [
            'workspace' => 'مركز التجزئة والمخزون',
            'sales' => 'مبيعات التجزئة',
            'products' => 'الأصناف والمخزون',
            'reports' => 'تقارير التجزئة',
            'workspace_capability' => A::F_INVENTORY,
            'sales_capability' => A::F_QUICK_SALE,
            'product_capability' => A::F_PRODUCTS,
        ],
        A::BIZ_FUEL => [
            'workspace' => 'مركز المحطة والمضخات',
            'sales' => 'مبيعات الوقود',
            'products' => 'أنواع الوقود والأسعار',
            'reports' => 'تقارير المحطة',
            'workspace_capability' => A::F_FUEL_PUMPS,
            'sales_capability' => A::F_FUEL_POS,
            'product_capability' => A::F_FUEL_PRODUCTS,
        ],
        A::BIZ_PHARMACY => [
            'workspace' => 'مركز الصيدلية والتنبيهات',
            'sales' => 'مبيعات الصيدلية',
            'products' => 'الأدوية والدفعات',
            'reports' => 'تقارير الصيدلية',
            'workspace_capability' => A::F_PHARMACY_ALERTS,
            'sales_capability' => A::F_PHARMACY_POS,
            'product_capability' => A::F_PHARMACY_PRODUCTS,
        ],
        A::BIZ_WHOLESALE => [
            'workspace' => 'مركز الجملة والفواتير',
            'sales' => 'فواتير ومبيعات الجملة',
            'products' => 'الأصناف وأسعار الجملة',
            'reports' => 'تقارير الجملة والذمم',
            'workspace_capability' => A::F_WHOLESALE_INVOICES,
            'sales_capability' => A::F_WHOLESALE_INVOICES,
            'product_capability' => A::F_PRODUCTS,
        ],
        A::BIZ_RESTAURANT => [
            'workspace' => 'مركز المطعم والطاولات',
            'sales' => 'طلبات ومبيعات المطعم',
            // لا نسمي كتالوج الكاشير «منيو» قبل بناء وصفات ومعدِّلات حقيقية.
            'products' => 'أصناف البيع',
            'reports' => 'تقارير المطعم',
            'workspace_capability' => A::F_RESTAURANT_TABLES,
            'sales_capability' => A::F_RESTAURANT_ORDERS,
            'product_capability' => A::F_PRODUCTS,
        ],
    ];

    /**
     * @param array{capabilities?:array<int,array{state?:string,capability?:array{code?:string}}> } $manifest
     * @return array<int,array{tab:string,label:string,icon:string,state:string}>
     */
    public function forOwner(?string $businessType, array $manifest): array
    {
        $states = [];
        foreach (($manifest['capabilities'] ?? []) as $row) {
            $code = $row['capability']['code'] ?? null;
            if (is_string($code) && $code !== '') {
                $states[$code] = (string) ($row['state'] ?? EntitlementService::LOCKED_BY_PLAN);
            }
        }

        $vertical = self::VERTICALS[$businessType ?? ''] ?? null;
        if ($vertical === null) {
            return [
                $this->item('overview', 'اختر نشاط المنشأة', 'storefront', EntitlementService::AVAILABLE),
                $this->item('wallet', 'المحفظة وكشف الحساب', 'account_balance_wallet', EntitlementService::AVAILABLE),
                $this->item('plans', 'باقتي ومميزاتي', 'auto_awesome', EntitlementService::AVAILABLE),
            ];
        }

        $items = [
            $this->item('sector', $vertical['workspace'], 'dashboard_customize', $this->state($states, $vertical['workspace_capability'])),
            // سجل البيع ليس تقريراً عاماً: كل قطاع يقرأ سجله من محرّكه
            // الخاص. وحارسه ليس بالضرورة حارس مساحة الإدارة: طلبات المطعم
            // ليست طاولاته، ومبيعات الوقود ليست مضخاته.
            $this->item('sales', $vertical['sales'], 'receipt_long', $this->state($states, $vertical['sales_capability'])),
            $this->item('overview', 'ملخص المنشأة', 'insights', EntitlementService::AVAILABLE),
            $this->item('wallet', 'المحفظة وكشف الحساب', 'account_balance_wallet', EntitlementService::AVAILABLE),
        ];

        if ($vertical['product_capability'] !== null && $this->available($states, $vertical['product_capability'])) {
            $items[] = $this->item('products', $vertical['products'], 'inventory_2', EntitlementService::AVAILABLE);
        }
        if ($businessType !== A::BIZ_WHOLESALE && $this->available($states, A::F_DEBTS)) {
            $items[] = $this->item('debts', $businessType === A::BIZ_WHOLESALE ? 'الذمم والتحصيلات' : 'الديون والدفع بالآجل', 'payments', EntitlementService::AVAILABLE);
        }
        $hasBranches = $this->available($states, A::F_BRANCHES);
        $hasEmployees = $this->available($states, A::F_EMPLOYEES);
        $hasMultiPos = $this->available($states, A::F_MULTI_POS);

        if ($hasBranches) {
            $items[] = $this->item('branches', 'الفروع', 'account_tree', EntitlementService::AVAILABLE);
        }
        // المعالج يجمع ثلاثة أصول حقيقية (فرع + موظف + جهاز)، لذلك لا
        // نظهره إن كانت إحدى الوحدات مقفلة بالباقة. تبقى شاشتا الموظفين
        // والأجهزة مستقلتين لمن يملك إحداهما دون الأخرى.
        if ($hasBranches && $hasEmployees && $hasMultiPos) {
            $items[] = $this->item('posSetup', 'إعداد نقطة بيع', 'point_of_sale', EntitlementService::AVAILABLE);
        }
        if ($hasEmployees) {
            $items[] = $this->item('staff', 'الموظفون والصلاحيات', 'groups', EntitlementService::AVAILABLE);
        }
        if ($hasMultiPos) {
            $items[] = $this->item('devices', 'أجهزة نقاط البيع', 'point_of_sale', EntitlementService::AVAILABLE);
        }
        // التقرير العام يقرأ `merchant_sales`؛ لا نعيد تسميته تقرير وقود أو
        // صيدلية أو جملة وهو لا يقرأ جداولها القطاعية. كل قطاع منها يقرأ
        // ملخصه الحقيقي من مساحة التشغيل إلى أن يكتمل تقريره الخاص.
        if (in_array($businessType, [A::BIZ_QUICK_SALE, A::BIZ_RETAIL], true)
            && $this->available($states, A::F_DAILY_REPORTS)) {
            $items[] = $this->item('reports', $vertical['reports'], 'analytics', EntitlementService::AVAILABLE);
        }

        $items[] = $this->item('settings', 'هوية المنشأة والفواتير', 'receipt_long', EntitlementService::AVAILABLE);
        $items[] = $this->item('plans', 'باقتي ومميزاتي', 'auto_awesome', EntitlementService::AVAILABLE);

        return $items;
    }

    /** @return array{tab:string,label:string,icon:string,state:string} */
    private function item(string $tab, string $label, string $icon, string $state): array
    {
        return compact('tab', 'label', 'icon', 'state');
    }

    /** @param array<string,string> $states */
    private function available(array $states, string $capability): bool
    {
        return $this->state($states, $capability) === EntitlementService::AVAILABLE;
    }

    /** @param array<string,string> $states */
    private function state(array $states, string $capability): string
    {
        return $states[$capability] ?? EntitlementService::LOCKED_BY_PLAN;
    }
}
