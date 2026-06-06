<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * P1-RBAC — تعبئة System Permissions + Roles.
 *
 * يجب تشغيله مرّة واحدة بعد migration:
 *   php artisan db:seed --class=RbacSeeder
 *
 * idempotent: يستخدم updateOrCreate.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = $this->seedPermissions();
        $this->seedSystemRoles($permissions);
    }

    /** يُرجع map: code => Permission */
    private function seedPermissions(): array
    {
        $defs = [
            // Sales
            ['sales.view', 'عرض المبيعات', Permission::CAT_SALES],
            ['sales.create', 'إنشاء عملية بيع', Permission::CAT_SALES],
            ['sales.refund', 'استرجاع', Permission::CAT_SALES],
            ['sales.void', 'إلغاء بيعة', Permission::CAT_SALES],
            ['sales.discount', 'تطبيق خصم', Permission::CAT_SALES],

            // Products
            ['products.view', 'عرض المنتجات', Permission::CAT_PRODUCTS],
            ['products.create', 'إضافة منتج', Permission::CAT_PRODUCTS],
            ['products.edit', 'تعديل منتج', Permission::CAT_PRODUCTS],
            ['products.delete', 'حذف منتج', Permission::CAT_PRODUCTS],
            ['products.import', 'استيراد منتجات', Permission::CAT_PRODUCTS],
            ['products.stock_adjust', 'تعديل المخزون', Permission::CAT_PRODUCTS],

            // Reports
            ['reports.daily', 'تقرير يومي', Permission::CAT_REPORTS],
            ['reports.monthly', 'تقرير شهري', Permission::CAT_REPORTS],
            ['reports.profit', 'تقرير أرباح', Permission::CAT_REPORTS],
            ['reports.export', 'تصدير تقارير', Permission::CAT_REPORTS],

            // Branches
            ['branches.view', 'عرض الفروع', Permission::CAT_BRANCHES],
            ['branches.manage', 'إدارة الفروع', Permission::CAT_BRANCHES],

            // Employees
            ['employees.view', 'عرض الموظّفين', Permission::CAT_EMPLOYEES],
            ['employees.manage', 'إدارة الموظّفين', Permission::CAT_EMPLOYEES],
            ['employees.assign_roles', 'تعيين أدوار', Permission::CAT_EMPLOYEES],

            // Customers
            ['customers.view', 'عرض العملاء', Permission::CAT_CUSTOMERS],
            ['customers.manage', 'إدارة العملاء', Permission::CAT_CUSTOMERS],
            ['customers.credit_limit', 'تعديل الحدّ الائتماني', Permission::CAT_CUSTOMERS],

            // Suppliers
            ['suppliers.view', 'عرض الموردين', Permission::CAT_SUPPLIERS],
            ['suppliers.manage', 'إدارة الموردين', Permission::CAT_SUPPLIERS],

            // Finance
            ['finance.collections', 'تحصيل دفعات', Permission::CAT_FINANCE],
            ['finance.expenses', 'تسجيل مصاريف', Permission::CAT_FINANCE],
            ['finance.cash_register', 'إدارة الصندوق', Permission::CAT_FINANCE],
            ['finance.bank_settle', 'تسوية بنكية', Permission::CAT_FINANCE],

            // Settings
            ['settings.business', 'إعدادات النشاط', Permission::CAT_SETTINGS],
            ['settings.subscription', 'إدارة الاشتراك', Permission::CAT_SETTINGS],
        ];

        $map = [];
        foreach ($defs as [$code, $label, $cat]) {
            $p = Permission::updateOrCreate(
                ['code' => $code],
                ['label_ar' => $label, 'category' => $cat],
            );
            $map[$code] = $p;
        }
        return $map;
    }

    private function seedSystemRoles(array $perms): void
    {
        $roles = [
            // 1) Super Admin — كل الصلاحيات
            Role::SUPER_ADMIN => [
                'label' => 'مدير عام',
                'description' => 'صلاحيات كاملة على كل النظام',
                'perms' => array_keys($perms),
            ],

            // 2) Branch Manager — كل شيء عدا إعدادات النشاط/الاشتراك
            Role::BRANCH_MANAGER => [
                'label' => 'مدير فرع',
                'description' => 'إدارة كاملة لفرع واحد',
                'perms' => [
                    'sales.view', 'sales.create', 'sales.refund', 'sales.void', 'sales.discount',
                    'products.view', 'products.edit', 'products.stock_adjust',
                    'reports.daily', 'reports.monthly', 'reports.profit', 'reports.export',
                    'branches.view',
                    'employees.view', 'employees.manage',
                    'customers.view', 'customers.manage',
                    'suppliers.view',
                    'finance.collections', 'finance.cash_register',
                ],
            ],

            // 3) Cashier — بيع + استرجاع فقط
            Role::CASHIER => [
                'label' => 'كاشير',
                'description' => 'البيع والاسترجاع',
                'perms' => [
                    'sales.view', 'sales.create', 'sales.refund', 'sales.discount',
                    'products.view',
                    'customers.view',
                ],
            ],

            // 4) Accountant — تقارير + تحصيلات
            Role::ACCOUNTANT => [
                'label' => 'محاسب',
                'description' => 'التقارير المالية والتحصيلات',
                'perms' => [
                    'sales.view',
                    'reports.daily', 'reports.monthly', 'reports.profit', 'reports.export',
                    'finance.collections', 'finance.expenses', 'finance.bank_settle',
                    'customers.view', 'customers.credit_limit',
                    'suppliers.view',
                ],
            ],

            // 5) Warehouse Keeper — المخزون فقط
            Role::WAREHOUSE_KEEPER => [
                'label' => 'أمين مخزن',
                'description' => 'إدارة المنتجات والمخزون',
                'perms' => [
                    'products.view', 'products.create', 'products.edit',
                    'products.import', 'products.stock_adjust',
                    'suppliers.view', 'suppliers.manage',
                ],
            ],

            // 6) Sales Rep — مندوب مبيعات
            Role::SALES_REP => [
                'label' => 'مندوب مبيعات',
                'description' => 'إنشاء فواتير + تحصيل من العملاء',
                'perms' => [
                    'sales.view', 'sales.create',
                    'products.view',
                    'customers.view', 'customers.manage',
                    'finance.collections',
                ],
            ],
        ];

        foreach ($roles as $code => $cfg) {
            $role = Role::updateOrCreate(
                ['code' => $code, 'merchant_user_id' => null],
                [
                    'label_ar' => $cfg['label'],
                    'is_system' => true,
                    'description' => $cfg['description'],
                ],
            );
            // اربط الصلاحيات
            $permIds = array_filter(array_map(
                fn($code) => $perms[$code]?->id ?? null,
                $cfg['perms'],
            ));
            $role->permissions()->sync($permIds);
        }
    }
}
