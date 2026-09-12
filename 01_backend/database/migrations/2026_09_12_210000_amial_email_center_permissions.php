<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-EMAIL-ADMIN-001 — صلاحيات دقيقة لمركز البريد والتحقق.
 *
 * القراءة لا تمنح كشف البريد الكامل؛ ذلك يبقى خلف
 * platform.customers.pii.reveal. والإدارة هنا تعني إبطال تحدي OTP نشط فقط،
 * ولا تسمح بتغيير بريد المستخدم أو توليد رمز له.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'platform.email.view' => [
            'label' => 'عرض مركز البريد والتحقق وسجل التسليم',
            'category' => 'platform_read',
            'roles' => [
                'platform_admin',
                'platform_supervisor',
                'platform_support',
                'platform_risk',
                'platform_compliance',
            ],
        ],
        'platform.email.manage' => [
            'label' => 'إبطال تحديات التحقق البريدية النشطة',
            'category' => 'platform_write',
            'roles' => [
                'platform_admin',
                'platform_supervisor',
                'platform_risk',
            ],
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
        $ids = DB::table('permissions')
            ->whereIn('code', array_keys(self::PERMISSIONS))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
