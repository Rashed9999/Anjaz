<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_permission_overrides', function (Blueprint $t) {
            // يربط الإذن بالفعل الذي رآه المالك، لا بنوع الصلاحية والمبلغ فقط.
            // مثال: مرتجع فاتورة A لا يجوز أن يُستهلك لمرتجع فاتورة B
            // حتى لو تطابق المبلغ.
            $t->string('context_key', 191)->nullable()->after('permission_code');

            $t->index(
                ['merchant_user_id', 'requested_by_user_id', 'permission_code', 'status', 'context_key'],
                'mpo_merchant_staff_perm_status_ctx_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('merchant_permission_overrides', function (Blueprint $t) {
            $t->dropIndex('mpo_merchant_staff_perm_status_ctx_idx');
            $t->dropColumn('context_key');
        });
    }
};
