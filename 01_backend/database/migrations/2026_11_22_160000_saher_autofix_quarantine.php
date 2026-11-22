<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Saher cannot hold a foreign key to business tables: containment is auxiliary.
        Schema::create('saher_route_quarantines', function (Blueprint $table) {
            $table->id();
            $table->string('route_name', 190)->unique();
            $table->unsignedBigInteger('finding_id')->index();
            $table->string('finding_fingerprint', 64);
            $table->string('status', 16)->default('ACTIVE')->index();
            $table->text('reason');
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamp('released_at')->nullable();
            $table->unsignedBigInteger('released_by_user_id')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamps();
        });

        // The emergency switch is deliberately granted to platform_admin only.
        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['code' => 'saher.autofix.quarantine'],
            [
                'label_ar' => 'احتواء مسار إداري خطير وإلغاء احتوائه',
                'category' => 'saher_act',
                'created_at' => $now, 'updated_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')
            ->where('code', 'saher.autofix.quarantine')->value('id');

        if ($permissionId !== null) {
            $adminRoleIds = DB::table('roles')->whereNull('merchant_user_id')
                ->where('code', 'platform_admin')->pluck('id')->all();

            // No implicit grant to support/security/auditor/finance.
            DB::table('role_permissions')->where('permission_id', $permissionId)
                ->whereNotIn('role_id', $adminRoleIds ?: [0])->delete();

            foreach ($adminRoleIds as $roleId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now]
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saher_route_quarantines');
        $permissionId = DB::table('permissions')
            ->where('code', 'saher.autofix.quarantine')->value('id');
        if ($permissionId !== null) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('admin_user_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
