<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('control_center_requests', function (Blueprint $table) {
            $table->id();
            $table->string('feature_key', 50)->index();
            $table->boolean('expected_enabled');
            $table->boolean('desired_enabled');
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('reason');
            $table->text('review_note')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('control_center_events', function (Blueprint $table) {
            $table->id();
            $table->string('feature_key', 50)->index();
            $table->string('action', 32);
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('request_id')->nullable()->index();
            $table->text('reason');
            $table->timestamps();
        });

        $now = now();
        DB::table('permissions')->updateOrInsert(
            ['code' => 'platform.control.approve'],
            [
                'label_ar' => 'اعتماد أو رفض تغيير تشغيلي حساس',
                'category' => 'platform',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        $permissionId = DB::table('permissions')->where('code', 'platform.control.approve')->value('id');
        $roleIds = DB::table('roles')->whereNull('merchant_user_id')
            ->where('code', 'platform_admin')->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                ['created_at' => $now, 'updated_at' => $now]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('control_center_events');
        Schema::dropIfExists('control_center_requests');
        $id = DB::table('permissions')->where('code', 'platform.control.approve')->value('id');
        if ($id !== null) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('admin_user_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
