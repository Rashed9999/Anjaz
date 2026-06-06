<?php

/**
 * AMIAL-RBAC-001 (v1.0-A)
 *
 * نظام أدوار وصلاحيات للوحة الإدارة.
 *
 * **المبدأ:** كل admin له role واحد (أو أكثر)، كل role له permissions، كل
 * endpoint إداري يتطلب permission محددة.
 *
 * **الأدوار الافتراضية (تُنشأ في seeder):**
 *   - super_admin       : كل صلاحية (root)
 *   - finance_manager   : عمليات مالية، تسويات، تقارير
 *   - compliance_officer: KYC، نزاعات، AML، توثيق التجار
 *   - support_agent     : رؤية وقراءة فقط، رد على شكاوى
 *   - read_only_auditor : قراءة كل شيء، لا تعديل (للمراجعين الخارجيين)
 *
 * هذا يحل ثغرة "كل admin يفعل كل شيء" المذكورة في v0.9-D analysis.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ====== Roles ======
        Schema::create('rbac_roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique(); // super_admin, finance_manager, ...
            $table->string('name_ar', 100);
            $table->string('description_ar', 500)->nullable();
            $table->boolean('is_system')->default(false); // system roles لا تُحذف
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ====== Permissions ======
        Schema::create('rbac_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique(); // 'users.view', 'transactions.refund'
            $table->string('group', 50)->index();  // users, transactions, kyc, ...
            $table->string('name_ar', 200);
            $table->string('description_ar', 500)->nullable();
            $table->boolean('is_sensitive')->default(false); // عمليات حساسة تحتاج 2FA حتى لو الـ session موثق
            $table->timestamps();
        });

        // ====== Role-Permission pivot ======
        Schema::create('rbac_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('granted_by_user_id')->nullable();
            $table->timestamp('granted_at')->useCurrent();

            $table->unique(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('rbac_roles')->onDelete('cascade');
            $table->foreign('permission_id')->references('id')->on('rbac_permissions')->onDelete('cascade');
        });

        // ====== User-Role pivot ======
        // ملاحظة: نستخدم users table الموجود (admins هم users لهم role).
        Schema::create('rbac_user_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 500)->nullable();

            $table->unique(['user_id', 'role_id'], 'user_role_unique');
            $table->index(['user_id', 'revoked_at']);

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('role_id')->references('id')->on('rbac_roles')->onDelete('cascade');
        });

        // ====== Audit: changes to roles/permissions ======
        // كل تغيير في النظام (assign/revoke/grant/deny) يُسجَّل
        Schema::create('rbac_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('action', 64); // role.created, permission.granted, user.role.assigned, ...
            $table->string('subject_type', 50)->nullable(); // role, permission, user
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('actor_user_id');
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rbac_audit_log');
        Schema::dropIfExists('rbac_user_roles');
        Schema::dropIfExists('rbac_role_permissions');
        Schema::dropIfExists('rbac_permissions');
        Schema::dropIfExists('rbac_roles');
    }
};
