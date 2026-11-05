<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-UNIFIED-VERIFICATION-003
 *
 * Agent onboarding is an identity and operating-profile workflow. Staff
 * onboarding is deliberately different: it proves secure workforce access
 * (MFA + assigned role) and never creates a financial-KYC entitlement.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('verification_requirement_policies')) return;

        $now = now();
        $policies = [
            [
                'subject_kind' => 'agent', 'merchant_vertical' => null, 'target_level' => 2,
                'policy_version' => '2026.10.1', 'is_active' => true,
                'requirements' => [
                    ['key' => 'contact', 'label' => 'إثبات الهاتف والبريد الإلكتروني'],
                    ['key' => 'identity', 'label' => 'الهوية الشخصية'],
                    ['key' => 'agent_operating_profile', 'label' => 'رقم الوكيل والنطاق التشغيلي'],
                    ['key' => 'risk_review', 'label' => 'مراجعة المخاطر'],
                    ['key' => 'final_activation', 'label' => 'القرار والاعتماد النهائي'],
                ],
            ],
            [
                'subject_kind' => 'admin_staff', 'merchant_vertical' => null, 'target_level' => 0,
                'policy_version' => '2026.10.1', 'is_active' => true,
                'requirements' => [
                    ['key' => 'staff_security', 'label' => 'المصادقة الثنائية والدور الإداري'],
                ],
            ],
        ];

        foreach ($policies as $policy) {
            DB::table('verification_requirement_policies')->updateOrInsert(
                [
                    'subject_kind' => $policy['subject_kind'],
                    'merchant_vertical' => null,
                    'target_level' => $policy['target_level'],
                    'policy_version' => $policy['policy_version'],
                ],
                [
                    'requirements' => json_encode($policy['requirements'], JSON_UNESCAPED_UNICODE),
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        if (! Schema::hasTable('permissions')) return;
        DB::table('permissions')->updateOrInsert(
            ['code' => 'platform.staff.security.view'],
            [
                'label_ar' => 'عرض حالة أمان موظفي المنصّة',
                'category' => 'platform_read',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        // Least privilege: this sensitive list is not inherited by every KYC
        // reviewer. Platform administrators receive the new permission, while
        // other staff need an explicit role assignment through normal RBAC.
        if (Schema::hasTable('roles') && Schema::hasTable('role_permissions')) {
            $adminRoleId = DB::table('roles')->whereNull('merchant_user_id')
                ->where('code', 'platform_admin')->value('id');
            $permissionId = DB::table('permissions')
                ->where('code', 'platform.staff.security.view')->value('id');
            if ($adminRoleId && $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $adminRoleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('verification_requirement_policies')) {
            DB::table('verification_requirement_policies')->whereIn('subject_kind', ['agent', 'admin_staff'])
                ->where('policy_version', '2026.10.1')->delete();
        }
        if (Schema::hasTable('permissions')) {
            $permissionId = DB::table('permissions')->where('code', 'platform.staff.security.view')->value('id');
            if ($permissionId && Schema::hasTable('role_permissions')) {
                DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            }
            DB::table('permissions')->where('code', 'platform.staff.security.view')->delete();
        }
    }
};
