<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-SUPPORT-DIAGNOSTICS-001
 *
 * نفصل القراءة عن الفعل، وفتح البلاغ عن حسم المال:
 *
 * - الدعم يرى أجهزة العميل ولا يملك قطع الجلسات أو حظر الجهاز.
 * - الدعم يقرأ طلبات استعادة الحساب ولا يعتمدها.
 * - الدعم يستطيع فتح بلاغ تحويل إلى مستلم خاطئ لأن الحجز احترازي ومؤقت،
 *   بينما الاسترداد/الرفض النهائيان يبقيان لدى صاحب صلاحية حسم النزاعات.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'platform.customers.devices.view' => [
            'عرض أجهزة العميل دون إنهاء الجلسات أو حظر الأجهزة',
            'platform_read',
        ],
        'platform.wrong_transfer.claim.open' => [
            'فتح بلاغ تحويل إلى مستلم خاطئ وحجز احترازي قابل للرجوع',
            'platform_support',
        ],
        'platform.recovery.view' => [
            'عرض طلبات استعادة الحساب وحالتها',
            'platform_read',
        ],
        'platform.recovery.approve' => [
            'اعتماد طلب استعادة حساب',
            'platform_decide',
        ],
        'platform.recovery.reject' => [
            'رفض طلب استعادة حساب',
            'platform_decide',
        ],
    ];

    private const ROLE_GRANTS = [
        'platform_support' => [
            'platform.customers.devices.view',
            'platform.wrong_transfer.claim.open',
            'platform.recovery.view',
        ],
        'platform_supervisor' => [
            'platform.customers.devices.view',
            'platform.wrong_transfer.claim.open',
            'platform.recovery.view',
            'platform.recovery.approve',
            'platform.recovery.reject',
        ],
        'platform_security' => [
            'platform.customers.devices.view',
            'platform.recovery.view',
            'platform.recovery.approve',
            'platform.recovery.reject',
        ],
        'platform_risk' => [
            'platform.customers.devices.view',
            'platform.recovery.view',
        ],
        'platform_compliance' => [
            'platform.recovery.view',
        ],
    ];

    public function up(): void
    {
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

        foreach (self::ROLE_GRANTS as $roleCode => $codes) {
            $roleId = DB::table('roles')
                ->whereNull('merchant_user_id')
                ->where('code', $roleCode)
                ->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($codes as $code) {
                $permissionId = DB::table('permissions')->where('code', $code)->value('id');

                if (! $permissionId) {
                    continue;
                }

                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }

        // مدير المنصة يجب أن يلتقط الصلاحيات التي أُضيفت بعد إنشاء دوره.
        $adminRoleId = DB::table('roles')
            ->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')
            ->value('id');

        if ($adminRoleId) {
            foreach (array_keys(self::PERMISSIONS) as $code) {
                $permissionId = DB::table('permissions')->where('code', $code)->value('id');
                if ($permissionId) {
                    DB::table('role_permissions')->updateOrInsert(
                        ['role_id' => $adminRoleId, 'permission_id' => $permissionId],
                        ['created_at' => $now, 'updated_at' => $now],
                    );
                }
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('code', array_keys(self::PERMISSIONS))
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('admin_user_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
