<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSIONS = [
        'platform.customers.kyc.restricted.view' => [
            'عرض طابور KYC المقيد ومستنداته',
            'platform_pii',
        ],
        'platform.customers.kyc.restricted.decide' => [
            'اعتماد ورفض حالات KYC المقيدة والتحقق الحضوري',
            'platform_sensitive',
        ],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        foreach (self::PERMISSIONS as $code => [$label, $category]) {
            DB::table('permissions')->updateOrInsert(
                ['code' => $code],
                [
                    'label_ar' => $label,
                    'category' => $category,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        // الخصوصية لا تُمنح تلقائياً لفريق الامتثال. مدير المنصة وحده
        // يحصل على المفتاح بعد الترحيل، ثم يختار أفراد الفريق من شاشة
        // الصلاحيات. هذا يمنع أن تتحول إضافة الميزة إلى كشف جماعي للصور.
        if (!Schema::hasTable('roles') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $adminRoleId = DB::table('roles')
            ->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')
            ->value('id');

        if (!$adminRoleId) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('code', array_keys(self::PERMISSIONS))
            ->pluck('id');

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $adminRoleId, 'permission_id' => $permissionId],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')
            ->whereIn('code', array_keys(self::PERMISSIONS))
            ->pluck('id');

        if (Schema::hasTable('admin_user_permissions')) {
            DB::table('admin_user_permissions')->whereIn('permission_id', $ids)->delete();
        }
        if (Schema::hasTable('role_permissions')) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }

        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
