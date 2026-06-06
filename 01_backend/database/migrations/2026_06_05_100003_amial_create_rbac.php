<?php

/**
 * P1-RBAC — نظام أدوار وصلاحيات حقيقي.
 *
 * البنية:
 *   - permissions: ذرّات (sales.create, products.edit, ...)
 *   - roles: مجموعات (cashier, branch_manager, ...)
 *   - role_permissions: ربط
 *   - pos_user_roles: ربط الموظّف بدور + نطاق فرع
 *
 * أنواع الأدوار:
 *   - is_system=true: أدوار جاهزة (لا تُحذَف)
 *   - is_system=false: أدوار مُخصَّصة لكل تاجر
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Permissions (نظام ثابت)
        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('code', 64)->unique(); // 'sales.create', 'products.edit'
            $t->string('label_ar', 100);
            $t->string('category', 32)->index(); // 'sales', 'products', 'reports', ...
            $t->text('description')->nullable();
            $t->timestamps();
        });

        // 2) Roles
        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32);            // 'cashier', 'branch_manager'
            $t->string('label_ar', 100);
            $t->boolean('is_system')->default(false);
            $t->unsignedBigInteger('merchant_user_id')->nullable(); // null = system role
            $t->text('description')->nullable();
            $t->timestamps();

            $t->index('merchant_user_id', 'r_merchant');
            $t->unique(['merchant_user_id', 'code'], 'r_merchant_code_unique');
        });

        // 3) Role ↔ Permissions
        Schema::create('role_permissions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('permission_id');
            $t->timestamps();

            $t->unique(['role_id', 'permission_id'], 'rp_unique');
            $t->index('role_id', 'rp_role');
        });

        // 4) PosUser ↔ Role (مع branch scope)
        Schema::create('pos_user_roles', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('pos_user_id');
            $t->unsignedBigInteger('role_id');
            // branch_scope: null = كل الفروع، >0 = فرع محدّد فقط
            $t->unsignedBigInteger('branch_scope_id')->nullable();
            $t->unsignedBigInteger('granted_by_user_id')->nullable();
            $t->timestamps();

            $t->index('pos_user_id', 'pur_pos');
            $t->index('role_id', 'pur_role');
            $t->unique(
                ['pos_user_id', 'role_id', 'branch_scope_id'],
                'pur_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
