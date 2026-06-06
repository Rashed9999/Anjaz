<?php

/**
 * AMIAL-UNIFIED-AUTH-001 (v1.5)
 *
 * إضافة أعمدة + جداول لدعم Unified Login.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // إضافة agent_number إلى users
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'agent_number')) {
                $table->string('agent_number', 20)->nullable()->after('type');
                $table->index('agent_number', 'idx_users_agent_number');
            }
        });

        // POS Users - موظفو نقاط البيع التابعون لتاجر
        if (!Schema::hasTable('pos_users')) {
            Schema::create('pos_users', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id'); // الموظف (User account)
                $table->unsignedBigInteger('merchant_user_id'); // التاجر الرئيسي
                $table->string('pos_number', 20); // رقم نقطة البيع (مثل POS-001)
                $table->string('display_name', 100)->nullable(); // اسم عرض
                $table->boolean('is_active')->default(true);
                $table->json('permissions')->nullable(); // can_refund, can_split_bill, ...
                $table->timestamp('last_login_at')->nullable();
                $table->timestamps();

                $table->unique(['merchant_user_id', 'pos_number'], 'pos_merchant_number_unique');
                $table->index(['user_id', 'is_active']);

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('merchant_user_id')->references('id')->on('users')->onDelete('cascade');
            });
        }

        // Login attempts log (للأمان + rate limiting)
        if (!Schema::hasTable('unified_login_attempts')) {
            Schema::create('unified_login_attempts', function (Blueprint $table) {
                $table->id();
                $table->string('role', 20);
                $table->string('identifier', 100); // phone, agent_number, merchant_number, email
                $table->string('identifier_masked', 100)->nullable();
                $table->boolean('success')->default(false);
                $table->string('failure_reason', 100)->nullable();
                $table->string('ip_address', 45);
                $table->string('user_agent', 500)->nullable();
                $table->unsignedBigInteger('user_id')->nullable(); // لو نجح
                $table->timestamp('attempted_at')->useCurrent();

                $table->index(['ip_address', 'attempted_at']);
                $table->index(['role', 'success', 'attempted_at']);
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('unified_login_attempts');
        Schema::dropIfExists('pos_users');
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'agent_number')) {
                try { $table->dropIndex('idx_users_agent_number'); } catch (\Throwable $e) {}
                $table->dropColumn('agent_number');
            }
        });
    }
};
