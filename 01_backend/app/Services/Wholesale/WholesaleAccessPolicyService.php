<?php

namespace App\Services\Wholesale;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Access\EntitlementService;
use App\Services\Merchant\MerchantPermissionService;
use App\Support\Access\AccessConstants as A;
use App\Support\Merchant\MerchantPermissions as P;

/**
 * AMIAL-WHOLESALE-ACCESS-001 — قرار واحد للباقات + صلاحيات موظفي الجملة.
 *
 * القاعدة:
 *  - قراءة البيانات الموجودة لا تختفي بعد خفض الباقة؛ صاحب المنشأة يبقى
 *    قادراً على الوصول إلى سجلاته بحسب صلاحية الفاعل.
 *  - الإنشاء/التعديل والعمق التشغيلي يمران عبر Entitlement الحقيقي.
 *  - صلاحية الدور مستقلة عن الباقة: الباقة تفتح الميزة للمنشأة، والدور
 *    يحدد من يستطيع تنفيذ الفعل داخل المنشأة.
 *
 * لا يعتمد Flutter على أسماء الباقات. يقرأ snapshot هذا ويعرض/يخفي الزر
 * بنفس القرار الذي يفرضه middleware على الـ API.
 */
final class WholesaleAccessPolicyService
{
    public const AVAILABLE = 'available';
    public const LOCKED_BY_PLAN = 'locked_by_plan';
    public const LOCKED_BY_ROLE = 'locked_by_role';
    public const LIMIT_REACHED = 'limit_reached';
    public const NOT_APPLICABLE = 'not_applicable';
    public const UNKNOWN = 'unknown';

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly MerchantPermissionService $permissions,
    ) {}

    /**
     * كل فعل واجهة/نقطة نهاية، وصلاحيته الدقيقة، والقدرات التي يجب أن تكون
     * مشتراة. enforce_limit لا يُستخدم إلا عندما الفعل نفسه يستهلك الحد.
     *
     * @return array<string,array{
     *   label:string,permission:?string,
     *   capabilities:array<int,array{code:string,enforce_limit:bool}>
     * }>
     */
    public static function definitions(): array
    {
        $cap = static fn (string $code, bool $limit = false): array => [
            'code' => $code,
            'enforce_limit' => $limit,
        ];

        return [
            // shell / dashboard
            'access.view' => [
                'label' => 'الدخول إلى مساحة الجملة',
                'permission' => null,
                'capabilities' => [],
            ],
            'dashboard.view' => [
                'label' => 'لوحة الجملة',
                'permission' => null,
                'capabilities' => [],
            ],
            'dashboard.metrics' => [
                'label' => 'مؤشرات المبيعات',
                'permission' => P::REPORT_SALES,
                'capabilities' => [],
            ],
            'business.view' => [
                'label' => 'ملف المنشأة',
                'permission' => null,
                'capabilities' => [],
            ],
            'business.manage' => [
                'label' => 'إعدادات منشأة الجملة',
                'permission' => P::SETTINGS_MANAGE,
                'capabilities' => [],
            ],

            // products — القراءة تبقى بعد downgrade، الكتابة من Starter.
            'product.view' => [
                'label' => 'عرض المنتجات',
                'permission' => P::WHOLESALE_PRODUCT_VIEW,
                'capabilities' => [],
            ],
            'product.create' => [
                'label' => 'إضافة منتج',
                'permission' => P::WHOLESALE_PRODUCT_MANAGE,
                'capabilities' => [$cap(A::F_PRODUCTS, true)],
            ],
            'product.update' => [
                'label' => 'تعديل منتج',
                'permission' => P::WHOLESALE_PRODUCT_MANAGE,
                'capabilities' => [$cap(A::F_PRODUCTS)],
            ],
            'stock.adjust' => [
                'label' => 'تعديل المخزون',
                'permission' => P::WHOLESALE_STOCK_ADJUST,
                'capabilities' => [$cap(A::F_INVENTORY)],
            ],
            'stock_alert.view' => [
                'label' => 'تنبيهات قرب النفاد',
                'permission' => P::WHOLESALE_PRODUCT_VIEW,
                'capabilities' => [$cap(A::F_LOW_STOCK_ALERTS)],
            ],
            'expiry.view' => [
                'label' => 'تنبيهات الصلاحية',
                'permission' => P::WHOLESALE_PRODUCT_VIEW,
                'capabilities' => [$cap(A::F_INVENTORY)],
            ],

            // customers — القراءة لا تُحجب؛ الإدارة من Business.
            'customer.view' => [
                'label' => 'عرض العملاء',
                'permission' => P::WHOLESALE_CUSTOMER_VIEW,
                'capabilities' => [],
            ],
            'customer.manage' => [
                'label' => 'إدارة العملاء والائتمان',
                'permission' => P::WHOLESALE_CUSTOMER_MANAGE,
                'capabilities' => [$cap(A::F_CUSTOMERS)],
            ],

            // invoices — القراءة/التصحيح/تحصيل دين قائم لا تُغلق بعد downgrade.
            'invoice.view' => [
                'label' => 'عرض الفواتير',
                'permission' => P::WHOLESALE_INVOICE_VIEW,
                'capabilities' => [$cap(A::F_WHOLESALE_INVOICES)],
            ],
            'invoice.create' => [
                'label' => 'إنشاء فاتورة جملة',
                'permission' => P::WHOLESALE_INVOICE_CREATE,
                // العقد الحالي يحتاج كتالوج منتجات + عميل؛ لا نعرض زرّاً
                // لا يستطيع الوصول إلى مكوّناته.
                'capabilities' => [
                    $cap(A::F_WHOLESALE_INVOICES),
                    $cap(A::F_PRODUCTS),
                    $cap(A::F_CUSTOMERS),
                ],
            ],
            'invoice.void' => [
                'label' => 'إبطال فاتورة',
                'permission' => P::WHOLESALE_INVOICE_VOID,
                'capabilities' => [$cap(A::F_WHOLESALE_INVOICES)],
            ],

            // collection — قبض الدين القائم لا يُقفل تجارياً.
            'collection.view' => [
                'label' => 'عرض التحصيلات',
                'permission' => P::WHOLESALE_COLLECTION_VIEW,
                'capabilities' => [$cap(A::F_WHOLESALE_INVOICES)],
            ],
            'collection.record' => [
                'label' => 'تسجيل تحصيل',
                'permission' => P::WHOLESALE_COLLECTION_RECORD,
                'capabilities' => [$cap(A::F_WHOLESALE_INVOICES)],
            ],

            // sales reps use Business employee depth, but their RBAC remains wholesale.*.
            'rep.view' => [
                'label' => 'عرض المندوبين',
                'permission' => P::WHOLESALE_REP_VIEW,
                'capabilities' => [$cap(A::F_EMPLOYEES)],
            ],
            'rep.manage' => [
                'label' => 'إدارة المندوبين',
                'permission' => P::WHOLESALE_REP_MANAGE,
                'capabilities' => [$cap(A::F_EMPLOYEES)],
            ],

            // reports / export
            'report.view' => [
                'label' => 'تقارير الجملة المتقدمة',
                'permission' => P::WHOLESALE_REPORT_VIEW,
                'capabilities' => [$cap(A::F_ADVANCED_REPORTS)],
            ],
            'export' => [
                'label' => 'تصدير تقارير الجملة',
                'permission' => P::WHOLESALE_REPORT_VIEW,
                'capabilities' => [$cap(A::F_EXCEL_EXPORT)],
            ],

            // multi pricing — Pro.
            'price.view' => [
                'label' => 'عرض أسعار الشرائح',
                'permission' => P::WHOLESALE_PRICE_VIEW,
                'capabilities' => [$cap(A::F_WHOLESALE_MULTI_PRICING)],
            ],
            'price.set' => [
                'label' => 'تعديل أسعار الشرائح',
                'permission' => P::WHOLESALE_PRICE_SET,
                'capabilities' => [$cap(A::F_WHOLESALE_MULTI_PRICING)],
            ],
            'tier.manage' => [
                'label' => 'إدارة شرائح أسعار الجملة',
                'permission' => P::WHOLESALE_TIER_MANAGE,
                'capabilities' => [$cap(A::F_WHOLESALE_MULTI_PRICING)],
            ],
        ];
    }

    public function actionFor(string $method, string $path): ?string
    {
        $method = strtoupper($method);
        $path = trim($path, '/');
        $prefix = 'api/v1/amial/merchant/wholesale';

        if ($path !== $prefix && ! str_starts_with($path, $prefix . '/')) {
            return null;
        }

        $relative = trim(substr($path, strlen($prefix)), '/');

        if ($relative === 'access') return 'access.view';
        if ($relative === 'dashboard' && $method === 'GET') return 'dashboard.metrics';
        if ($relative === '') return $method === 'GET' ? 'business.view' : ($method === 'POST' ? 'business.manage' : null);
        if ($relative === 'price-tiers' && $method === 'POST') return 'tier.manage';

        if ($relative === 'products') {
            return match ($method) {
                'GET' => 'product.view',
                'POST' => 'product.create',
                default => null,
            };
        }
        if (preg_match('~^products/\d+/adjust-stock$~', $relative)) {
            return $method === 'POST' ? 'stock.adjust' : null;
        }
        if (preg_match('~^products/\d+/prices$~', $relative)) {
            return match ($method) {
                'GET' => 'price.view',
                'POST' => 'price.set',
                default => null,
            };
        }
        if (preg_match('~^products/\d+$~', $relative)) {
            return in_array($method, ['PUT', 'PATCH'], true) ? 'product.update' : null;
        }

        if ($relative === 'customers') {
            return match ($method) {
                'GET' => 'customer.view',
                'POST' => 'customer.manage',
                default => null,
            };
        }
        if (preg_match('~^customers/\d+$~', $relative)) {
            return in_array($method, ['PUT', 'PATCH'], true) ? 'customer.manage' : null;
        }

        if ($relative === 'invoices') {
            return match ($method) {
                'GET' => 'invoice.view',
                'POST' => 'invoice.create',
                default => null,
            };
        }
        if (preg_match('~^invoices/\d+/collect$~', $relative)) {
            return $method === 'POST' ? 'collection.record' : null;
        }
        if (preg_match('~^invoices/\d+/void$~', $relative)) {
            return $method === 'POST' ? 'invoice.void' : null;
        }
        if (preg_match('~^invoices/\d+(/pdf)?$~', $relative)) {
            return $method === 'GET' ? 'invoice.view' : null;
        }

        if ($relative === 'collections') return $method === 'GET' ? 'collection.view' : null;
        if ($relative === 'sales-reps') {
            return match ($method) {
                'GET' => 'rep.view',
                'POST' => 'rep.manage',
                default => null,
            };
        }
        if (str_starts_with($relative, 'reports/')) return $method === 'GET' ? 'report.view' : null;

        return null;
    }

    /** @return array<string,mixed> */
    public function state(User $user, string $action): array
    {
        $def = self::definitions()[$action] ?? null;
        if ($def === null) {
            return $this->row($action, self::UNKNOWN, 'فعل جملة غير معروف');
        }

        $merchantId = $this->permissions->merchantIdFor($user);
        $profile = MerchantProfile::where('user_id', $merchantId)->first();

        if (! $profile || $profile->business_type !== A::BIZ_WHOLESALE) {
            return $this->row($action, self::NOT_APPLICABLE, 'هذه النقطة تخص تجارة الجملة فقط', $def);
        }

        foreach ($def['capabilities'] as $required) {
            $decision = $this->entitlements->state($user, $required['code']);
            $state = (string) ($decision['state'] ?? self::UNKNOWN);

            if ($state === EntitlementService::LOCKED_BY_PLAN) {
                return $this->row($action, self::LOCKED_BY_PLAN,
                    'الميزة غير مشمولة في الباقة الحالية', $def, $decision);
            }
            if ($state === EntitlementService::NOT_APPLICABLE) {
                return $this->row($action, self::NOT_APPLICABLE,
                    'الميزة لا تنطبق على هذا النشاط', $def, $decision);
            }
            if ($state === EntitlementService::LIMIT_REACHED && $required['enforce_limit']) {
                return $this->row($action, self::LIMIT_REACHED,
                    'تم بلوغ حد الباقة لهذا الفعل', $def, $decision);
            }
            // locked_by_role لا يُحسم هنا: permission أدناه هو الصلاحية
            // الدقيقة لقطاع الجملة، بينما بعض capabilities عامة وتحمل
            // patterns لقطاع آخر.
        }

        $permission = $def['permission'];
        if ($permission !== null && ! $this->permissions->can($user, $permission)) {
            return $this->row($action, self::LOCKED_BY_ROLE,
                'هذه العملية تحتاج صلاحية من مالك المنشأة', $def);
        }

        return $this->row($action, self::AVAILABLE, null, $def);
    }

    /** @return array<string,mixed> */
    public function snapshot(User $user): array
    {
        $merchantId = $this->permissions->merchantIdFor($user);
        $profile = MerchantProfile::where('user_id', $merchantId)->first();
        $actions = [];

        foreach (array_keys(self::definitions()) as $action) {
            $actions[$action] = $this->state($user, $action);
        }

        return [
            'business_type' => $profile?->business_type,
            'subscription_plan' => $profile?->subscription_plan ?? A::PLAN_FREE,
            'is_owner' => $this->permissions->isOwner($user),
            'permissions' => $this->permissions->effective($user),
            'actions' => $actions,
        ];
    }

    /** @return array<string,mixed> */
    private function row(
        string $action,
        string $state,
        ?string $reason,
        ?array $def = null,
        ?array $entitlement = null,
    ): array {
        return [
            'action' => $action,
            'label' => $def['label'] ?? $action,
            'state' => $state,
            'reason' => $reason,
            'permission' => $def['permission'] ?? null,
            'required_capabilities' => array_values(array_map(
                static fn (array $r): string => $r['code'],
                $def['capabilities'] ?? [],
            )),
            'unlock' => $entitlement['unlock'] ?? null,
            'usage' => $entitlement['usage'] ?? null,
        ];
    }
}
