<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('platform_operator_tab_access', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('tab_code', 64);
            $table->string('access_level', 8); // read | write
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'tab_code']);
            $table->index('user_id');
        });

        Schema::create('admin_user_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'permission_id']);
            $table->index('user_id');
        });

        DB::table('permissions')->updateOrInsert(['code' => 'platform.staff.view'], [
            'label_ar' => 'عرض الموظفين وصلاحياتهم', 'category' => 'platform_read',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('permissions')->updateOrInsert(['code' => 'platform.staff.manage'], [
            'label_ar' => 'إنشاء موظفي المنصة وتعديل تبويباتهم', 'category' => 'platform_settings',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('permissions')->updateOrInsert(['code' => 'platform.tickets.view'], [
            'label_ar' => 'عرض تذاكر الدعم', 'category' => 'platform_read',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // مدير المنصة لا يفقد باب الموظفين بعد إضافة الحارس الجديد.
        $adminRole = DB::table('roles')->whereNull('merchant_user_id')->where('code', 'platform_admin')->value('id');
        $permission = DB::table('permissions')->where('code', 'platform.staff.view')->value('id');
        if ($adminRole && $permission) DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $adminRole, 'permission_id' => $permission], ['created_at' => now(), 'updated_at' => now()]
        );
        $staffManage = DB::table('permissions')->where('code', 'platform.staff.manage')->value('id');
        if ($adminRole && $staffManage) DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $adminRole, 'permission_id' => $staffManage], ['created_at' => now(), 'updated_at' => now()]
        );
        $ticketView = DB::table('permissions')->where('code', 'platform.tickets.view')->value('id');
        if ($adminRole && $ticketView) DB::table('role_permissions')->updateOrInsert(
            ['role_id' => $adminRole, 'permission_id' => $ticketView], ['created_at' => now(), 'updated_at' => now()]
        );
        if ($ticketView) {
            $ticketManagers = DB::table('role_permissions')
                ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('permissions.code', 'platform.tickets.manage')->pluck('role_permissions.role_id');
            foreach ($ticketManagers as $roleId) DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $ticketView], ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('code', ['platform.staff.view', 'platform.staff.manage', 'platform.tickets.view'])
            ->pluck('id');
        if ($permissionIds->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        }
        Schema::dropIfExists('admin_user_permissions');
        Schema::dropIfExists('platform_operator_tab_access');
        DB::table('permissions')->whereIn('code', ['platform.staff.view', 'platform.staff.manage', 'platform.tickets.view'])->delete();
    }
};
