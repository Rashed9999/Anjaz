<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_login_pins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('pin_hash', 255);
            $table->boolean('must_change')->default(false);
            $table->unsignedTinyInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('issued_reason', 40)->default('manual');
            $table->string('delivery_status', 20)->default('not_required');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('delivery_failed_at')->nullable();
            $table->timestamps();

            $table->index(['delivery_status', 'updated_at']);
        });

        // AMIAL-PLATFORM-LOGIN-PIN-001
        // 1234 خاص بحساب مدير المنصة الجذري الموجود قبل هذه الميزة فقط.
        // لا يُعمّم على كل حساب type=0 ولا على كل موظف يحمل دوراً لاحقاً.
        // القيمة الصريحة لا تُخزّن؛ الموجود في DB هو Hash فقط.
        $rootAdminId = DB::table('users')
            ->join('admin_user_roles', 'admin_user_roles.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'admin_user_roles.role_id')
            ->where('users.type', 0)
            ->where('roles.code', 'platform_admin')
            ->whereNull('roles.merchant_user_id')
            ->orderBy('users.id')
            ->value('users.id');

        if ($rootAdminId) {
            DB::table('platform_login_pins')->insert([
                'user_id' => $rootAdminId,
                'pin_hash' => Hash::make('1234'),
                'must_change' => true,
                'failed_attempts' => 0,
                'issued_by_user_id' => null,
                'issued_reason' => 'bootstrap_admin_default',
                'delivery_status' => 'not_required',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_login_pins');
    }
};
