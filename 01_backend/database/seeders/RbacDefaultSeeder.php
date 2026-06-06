<?php

namespace Database\Seeders;

use App\Models\Rbac\Permission;
use App\Models\Rbac\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-RBAC-001 (v1.0-A)
 *
 * بذور أساسية: 5 roles + 40+ permission + المصفوفة بينها.
 *
 * **آمن للتشغيل مرات متعددة (idempotent):**
 *   php artisan db:seed --class=RbacDefaultSeeder
 *
 * بعد التشغيل، يدوياً:
 *   1. أنشئ super_admin user
 *   2. عيّن role 'super_admin' له
 */
class RbacDefaultSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Seeding RBAC: permissions...');
        $permissions = $this->seedPermissions();

        $this->command->info('Seeding RBAC: roles...');
        $roles = $this->seedRoles();

        $this->command->info('Mapping roles → permissions...');
        $this->mapRolePermissions($roles, $permissions);

        $this->command->info('✓ RBAC seeded successfully');
        $this->command->info('  Next: create a super_admin user and assign role.');
    }

    /**
     * @return array<string, Permission>
     */
    private function seedPermissions(): array
    {
        $all = [
            // ====== Users management ======
            'users.view'           => ['group' => 'users',  'name' => 'عرض المستخدمين'],
            'users.edit'           => ['group' => 'users',  'name' => 'تعديل بيانات مستخدم', 'sensitive' => true],
            'users.suspend'        => ['group' => 'users',  'name' => 'إيقاف مستخدم', 'sensitive' => true],
            'users.export'         => ['group' => 'users',  'name' => 'تصدير قائمة المستخدمين'],

            // ====== Transactions ======
            'transactions.view'    => ['group' => 'transactions', 'name' => 'عرض العمليات'],
            'transactions.refund'  => ['group' => 'transactions', 'name' => 'استرجاع عملية', 'sensitive' => true],
            'transactions.reverse' => ['group' => 'transactions', 'name' => 'عكس عملية مالية', 'sensitive' => true],
            'transactions.export'  => ['group' => 'transactions', 'name' => 'تصدير العمليات'],

            // ====== KYC ======
            'kyc.view'             => ['group' => 'kyc', 'name' => 'عرض طلبات KYC'],
            'kyc.approve'          => ['group' => 'kyc', 'name' => 'الموافقة على KYC', 'sensitive' => true],
            'kyc.reject'           => ['group' => 'kyc', 'name' => 'رفض KYC', 'sensitive' => true],

            // ====== Account Recovery (AMIAL-001) ======
            'recovery.view'        => ['group' => 'recovery', 'name' => 'عرض طلبات استرداد الحساب'],
            'recovery.approve'     => ['group' => 'recovery', 'name' => 'الموافقة على استرداد', 'sensitive' => true],
            'recovery.reject'      => ['group' => 'recovery', 'name' => 'رفض استرداد'],

            // ====== Legal Terms (AMIAL-001) ======
            'legal.view'           => ['group' => 'legal', 'name' => 'عرض سياسات الاستخدام'],
            'legal.create'         => ['group' => 'legal', 'name' => 'إنشاء نسخة سياسة جديدة', 'sensitive' => true],
            'legal.publish'        => ['group' => 'legal', 'name' => 'نشر/إلزام سياسة', 'sensitive' => true],

            // ====== Zone Management ======
            'zones.view'           => ['group' => 'zones', 'name' => 'عرض المناطق'],
            'zones.assign'         => ['group' => 'zones', 'name' => 'تعديل zone لمستخدم', 'sensitive' => true],

            // ====== Audit ======
            'audit.view'           => ['group' => 'audit', 'name' => 'عرض سجلات التدقيق'],
            'audit.export'         => ['group' => 'audit', 'name' => 'تصدير سجل التدقيق'],

            // ====== Security Events ======
            'security.view'        => ['group' => 'security', 'name' => 'عرض الأحداث الأمنية'],
            'security.resolve'     => ['group' => 'security', 'name' => 'إغلاق حدث أمني'],

            // ====== Receipts (AMIAL-RECEIPTS-001) ======
            'receipts.view'        => ['group' => 'receipts', 'name' => 'عرض الإيصالات'],
            'receipts.regenerate'  => ['group' => 'receipts', 'name' => 'إعادة توليد PDF'],

            // ====== Family Funds (AMIAL-FUND-FAMILY-001) ======
            'funds.view'           => ['group' => 'funds', 'name' => 'عرض الصناديق العائلية'],
            'funds.suspend'        => ['group' => 'funds', 'name' => 'تجميد صندوق', 'sensitive' => true],

            // ====== Bill Pay (AMIAL-BILL-PAY-001) ======
            'billpay.view'         => ['group' => 'billpay', 'name' => 'عرض عمليات دفع الفواتير'],
            'billpay.providers'    => ['group' => 'billpay', 'name' => 'إدارة مزودي الفواتير', 'sensitive' => true],
            'billpay.reconcile'    => ['group' => 'billpay', 'name' => 'تشغيل reconcile يدوي'],

            // ====== Merchants (for future merchant batch) ======
            'merchants.view'       => ['group' => 'merchants', 'name' => 'عرض التجار'],
            'merchants.verify'     => ['group' => 'merchants', 'name' => 'توثيق تاجر', 'sensitive' => true],
            'merchants.suspend'    => ['group' => 'merchants', 'name' => 'إيقاف تاجر', 'sensitive' => true],
            'merchants.ledger'     => ['group' => 'merchants', 'name' => 'عرض دفتر تاجر'],

            // ====== Agents ======
            'agents.view'          => ['group' => 'agents', 'name' => 'عرض الوكلاء'],
            'agents.approve'       => ['group' => 'agents', 'name' => 'اعتماد وكيل', 'sensitive' => true],
            'agents.suspend'       => ['group' => 'agents', 'name' => 'إيقاف وكيل', 'sensitive' => true],

            // ====== Reports ======
            'reports.view'         => ['group' => 'reports', 'name' => 'عرض التقارير'],
            'reports.export'       => ['group' => 'reports', 'name' => 'تصدير التقارير'],

            // ====== System / Settings ======
            'settings.view'        => ['group' => 'settings', 'name' => 'عرض الإعدادات'],
            'settings.edit'        => ['group' => 'settings', 'name' => 'تعديل الإعدادات', 'sensitive' => true],

            // ====== RBAC management ======
            'rbac.view'            => ['group' => 'rbac', 'name' => 'عرض الأدوار والصلاحيات'],
            'rbac.manage'          => ['group' => 'rbac', 'name' => 'إدارة الأدوار', 'sensitive' => true],
            'rbac.assign'          => ['group' => 'rbac', 'name' => 'إسناد دور لمستخدم', 'sensitive' => true],
        ];

        $result = [];
        foreach ($all as $code => $meta) {
            $result[$code] = Permission::updateOrCreate(
                ['code' => $code],
                [
                    'group' => $meta['group'],
                    'name_ar' => $meta['name'],
                    'is_sensitive' => $meta['sensitive'] ?? false,
                ],
            );
        }
        return $result;
    }

    /**
     * @return array<string, Role>
     */
    private function seedRoles(): array
    {
        $defs = [
            'super_admin' => [
                'name_ar' => 'مدير عام',
                'description_ar' => 'صلاحية كاملة على كل النظام. للاستخدام بحذر شديد.',
            ],
            'finance_manager' => [
                'name_ar' => 'مدير مالي',
                'description_ar' => 'مسؤول عن العمليات المالية والتسويات والتقارير.',
            ],
            'compliance_officer' => [
                'name_ar' => 'مسؤول الالتزام والمخاطر',
                'description_ar' => 'KYC، توثيق التجار، النزاعات، الأحداث الأمنية.',
            ],
            'support_agent' => [
                'name_ar' => 'موظف خدمة عملاء',
                'description_ar' => 'رؤية بيانات المستخدم والعمليات للرد على الاستفسارات.',
            ],
            'read_only_auditor' => [
                'name_ar' => 'مدقق (للقراءة فقط)',
                'description_ar' => 'للمراجعين الخارجيين والتدقيق. لا يستطيع التعديل.',
            ],
        ];

        $result = [];
        foreach ($defs as $code => $meta) {
            $result[$code] = Role::updateOrCreate(
                ['code' => $code],
                [
                    'name_ar' => $meta['name_ar'],
                    'description_ar' => $meta['description_ar'],
                    'is_system' => true,
                    'is_active' => true,
                ],
            );
        }
        return $result;
    }

    private function mapRolePermissions(array $roles, array $permissions): void
    {
        // ====== super_admin: كل شيء ======
        foreach ($permissions as $perm) {
            $roles['super_admin']->grantPermission($perm);
        }

        // ====== finance_manager ======
        $financePerms = [
            'users.view', 'users.export',
            'transactions.view', 'transactions.refund', 'transactions.reverse', 'transactions.export',
            'receipts.view', 'receipts.regenerate',
            'funds.view',
            'billpay.view', 'billpay.providers', 'billpay.reconcile',
            'merchants.view', 'merchants.ledger',
            'agents.view',
            'reports.view', 'reports.export',
        ];
        foreach ($financePerms as $code) {
            if (isset($permissions[$code])) {
                $roles['finance_manager']->grantPermission($permissions[$code]);
            }
        }

        // ====== compliance_officer ======
        $compliancePerms = [
            'users.view', 'users.suspend',
            'transactions.view',
            'kyc.view', 'kyc.approve', 'kyc.reject',
            'recovery.view', 'recovery.approve', 'recovery.reject',
            'legal.view',
            'zones.view',
            'audit.view', 'audit.export',
            'security.view', 'security.resolve',
            'merchants.view', 'merchants.verify', 'merchants.suspend',
            'agents.view', 'agents.approve', 'agents.suspend',
            'reports.view',
        ];
        foreach ($compliancePerms as $code) {
            if (isset($permissions[$code])) {
                $roles['compliance_officer']->grantPermission($permissions[$code]);
            }
        }

        // ====== support_agent ======
        $supportPerms = [
            'users.view',
            'transactions.view',
            'recovery.view',
            'receipts.view',
            'funds.view',
            'billpay.view',
            'merchants.view',
            'agents.view',
        ];
        foreach ($supportPerms as $code) {
            if (isset($permissions[$code])) {
                $roles['support_agent']->grantPermission($permissions[$code]);
            }
        }

        // ====== read_only_auditor ======
        // كل permissions التي تنتهي بـ .view أو .export
        foreach ($permissions as $code => $perm) {
            if (str_ends_with($code, '.view') || str_ends_with($code, '.export')) {
                $roles['read_only_auditor']->grantPermission($perm);
            }
        }
    }
}
