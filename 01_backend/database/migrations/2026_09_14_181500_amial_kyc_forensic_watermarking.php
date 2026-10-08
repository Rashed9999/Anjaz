<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERMISSION = 'platform.customers.kyc.biometric.view';

    public function up(): void
    {
        if (!Schema::hasTable('kyc_forensic_views')) {
            Schema::create('kyc_forensic_views', function (Blueprint $table) {
                $table->id();
                $table->string('trace_code', 24)->unique();
                $table->unsignedBigInteger('document_id')->index();
                $table->unsignedBigInteger('subject_user_id')->index();
                $table->unsignedBigInteger('actor_user_id')->index();
                $table->string('doc_type', 40)->index();
                $table->string('watermark_version', 24)->default('fw-1');
                $table->string('source_mime', 100)->nullable();
                $table->string('session_fingerprint', 64)->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('access_reason', 500)->nullable();
                $table->timestamp('viewed_at')->index();
                $table->timestamps();

                // لا نضع cascade على أثرٍ جنائي: حذف حساب أو مستند لاحقاً
                // لا يجوز أن يمحو مَن رأى الصورة ومتى.
            });
        }

        if (Schema::hasTable('permissions')) {
            $now = now();
            DB::table('permissions')->updateOrInsert(
                ['code' => self::PERMISSION],
                [
                    'label_ar' => 'عرض الصور البيومترية الحساسة (السيلفي)',
                    'category' => 'platform_pii',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            // لا تُمنح تلقائياً لفريق الامتثال كلّه. المدير فقط يملكها بعد
            // الترحيل، ثم يمنحها صراحةً للمراجعين المقيّدين من شاشة الأدوار.
            $adminRoleId = DB::table('roles')
                ->whereNull('merchant_user_id')
                ->where('code', 'platform_admin')
                ->value('id');
            $permissionId = DB::table('permissions')
                ->where('code', self::PERMISSION)
                ->value('id');

            if ($adminRoleId && $permissionId && Schema::hasTable('role_permissions')) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $adminRoleId, 'permission_id' => $permissionId],
                    ['created_at' => $now, 'updated_at' => $now],
                );
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions') && Schema::hasTable('role_permissions')) {
            $permissionId = DB::table('permissions')
                ->where('code', self::PERMISSION)
                ->value('id');
            if ($permissionId) {
                DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            }
            DB::table('permissions')->where('code', self::PERMISSION)->delete();
        }

        Schema::dropIfExists('kyc_forensic_views');
    }
};
