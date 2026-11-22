<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saher_remediation_cases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('finding_id')->unique();
            $table->string('finding_fingerprint', 64);
            $table->string('rule_id', 100);
            $table->string('strategy', 32);
            $table->string('status', 32)->default('OPEN');
            $table->json('plan');
            $table->text('reason');
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamps();
            $table->index(['status', 'created_at']);
        });

        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['code' => 'saher.autofix.plan'],
            [
                'label_ar' => 'إنشاء طلب إصلاح موثق لاكتشاف ساهر',
                'category' => 'saher_act',
                'created_at' => $now, 'updated_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')
            ->where('code', 'saher.autofix.plan')->value('id');
        if ($permissionId === null) {
            return;
        }

        $allowedIds = DB::table('roles')->whereNull('merchant_user_id')
            ->whereIn('code', ['platform_admin', 'platform_security'])
            ->pluck('id')->all();

        DB::table('role_permissions')->where('permission_id', $permissionId)
            ->whereNotIn('role_id', $allowedIds ?: [0])->delete();

        foreach ($allowedIds as $roleId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('saher_remediation_cases');

        $permissionId = DB::table('permissions')
            ->where('code', 'saher.autofix.plan')->value('id');
        if ($permissionId !== null) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('admin_user_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
