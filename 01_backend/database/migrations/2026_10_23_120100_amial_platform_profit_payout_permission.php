<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** فصلُ صرف أرباح المنصّة عن إصدار الريال الإلكتروني وعن تسوية الوكيل. */
return new class extends Migration
{
    private const CODE = 'platform.treasury.payout';

    public function up(): void
    {
        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['code' => self::CODE],
            [
                'label_ar' => 'طلب وصرف أرباح المنصّة من الخزينة',
                'category' => 'platform_money',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $permissionId = DB::table('permissions')->where('code', self::CODE)->value('id');
        foreach (['platform_admin', 'platform_finance'] as $roleCode) {
            $roleId = DB::table('roles')->whereNull('merchant_user_id')->where('code', $roleCode)->value('id');
            if ($roleId && $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('code', self::CODE)->value('id');
        if ($id) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
