<?php

/**
 * AMIAL-ZONE-001 + AMIAL-PIN-SECURITY-001 (extension)
 *
 * إضافة zone_code للمستخدمين + حقول security_hold.
 *
 * زon_code:
 *   كل user له منطقة تشغيلية واحدة فقط: SOUTH (v1).
 *   مستقبلاً قد نضيف NORTH، MIDDLE، إلخ. لكن في v0.7 كل المستخدمين SOUTH.
 *
 * security_hold:
 *   فترة تجميد بعد PIN Reset / phone change. خلالها، العمليات المالية ممنوعة
 *   حتى لو الـ PIN صحيح. (سياسة قسم 9 و10 من الوثيقة.)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // المنطقة التشغيلية للمستخدم
            // SOUTH = الجنوب (الوحيدة المسموحة في v1)
            // NORTH, MIDDLE, OTHER = محجوزة للمستقبل، لا تسمح بعمليات مالية الآن
            $table->string('zone_code', 16)->default('SOUTH')->after('type');

            // ملاحظة: لا نضع foreign key لجدول zones لأنه ليس موجوداً ولن يكون
            // (الـ zones ثابتة دلالياً، تُدار من config)

            // قفل أمني مؤقت (security hold) بعد أحداث حساسة
            $table->timestamp('security_hold_until')->nullable()->after('pin_locked_until');
            $table->string('security_hold_reason', 100)->nullable()->after('security_hold_until');

            // فهرس على zone للتقارير
            $table->index('zone_code', 'users_zone_idx');
            $table->index('security_hold_until', 'users_security_hold_idx');
        });

        // إعداد كل المستخدمين الموجودين على SOUTH (default سيُطبق، لكن نضمن صراحة)
        DB::table('users')->whereNull('zone_code')->update(['zone_code' => 'SOUTH']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_zone_idx');
            $table->dropIndex('users_security_hold_idx');
            $table->dropColumn(['zone_code', 'security_hold_until', 'security_hold_reason']);
        });
    }
};
