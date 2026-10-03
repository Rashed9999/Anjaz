<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** AMIAL-REPORTING-CENTER-001 — صلاحيات مستقلة للعرض والتصدير. */
return new class extends Migration
{
    private const PERMISSIONS = [
        'platform.reports.view' => [
            'label' => 'عرض مركز التقارير المؤسسية والتقارير المالية',
            'category' => 'platform_read',
            'roles' => ['platform_admin', 'platform_supervisor', 'platform_finance', 'platform_compliance', 'platform_risk'],
        ],
        'platform.reports.export' => [
            'label' => 'تصدير التقارير المالية والتشغيلية',
            'category' => 'platform_write',
            'roles' => ['platform_admin', 'platform_supervisor', 'platform_finance'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $code => $definition) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'label_ar' => $definition['label'],
                    'category' => $definition['category'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $permissionId = DB::table('permissions')->where('code', $code)->value('id');
            if ($permissionId === null) {
                continue;
            }

            foreach ($definition['roles'] as $roleCode) {
                $roleId = DB::table('roles')
                    ->whereNull('merchant_user_id')
                    ->where('code', $roleCode)
                    ->value('id');

                if ($roleId === null) {
                    continue;
                }

                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('code', array_keys(self::PERMISSIONS))->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
