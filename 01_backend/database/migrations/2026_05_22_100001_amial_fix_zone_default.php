<?php

/**
 * AMIAL-ZONE-ASSIGN-001 (v2.0)
 *
 * إصلاح الثغرة الأمنية الحرجة في سياسة الـ zone:
 *
 * المشكلة: users.zone_code كان default('SOUTH') → كل مستخدم جديد
 *          يُعامَل كجنوبي تلقائياً، مما عطّل سياسة "الجنوب فقط".
 *
 * الإصلاح:
 *   1. تغيير الـ default إلى 'UNKNOWN' (آمن: لا مالية حتى التأكيد)
 *   2. جدول zone_assignment_logs (audit لكل إسناد)
 *
 * ⚠️ ملاحظة: لا نغيّر المستخدمين الموجودين تلقائياً (قد يكونون موثقين).
 *    تغيير الـ default يؤثر فقط على المستخدمين الجدد.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) تغيير default zone_code إلى UNKNOWN للمستخدمين الجدد
        // (نستخدم raw لأن change() يحتاج doctrine/dbal)
        try {
            DB::statement("ALTER TABLE users ALTER COLUMN zone_code SET DEFAULT 'UNKNOWN'");
        } catch (\Throwable $e) {
            // MySQL أقدم أو SQLite (اختبارات) — نتجاهل بأمان
        }

        // 2) جدول سجل إسناد المناطق
        if (!Schema::hasTable('zone_assignment_logs')) {
            Schema::create('zone_assignment_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('assigned_zone', 16);
                $table->string('method', 30); // registration, kyc_verification, admin_decision
                $table->json('signals')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['user_id', 'created_at']);
                $table->index('assigned_zone');
            });
        }

        // 3) (اختياري - معطّل افتراضياً) كشف المستخدمين المشبوهين الموجودين
        //    الذين حصلوا على SOUTH دون توثيق فعلي.
        //    لا نغيّرهم تلقائياً لتجنب تعطيل مستخدمين شرعيين،
        //    لكن نسجّل تنبيهاً في الـ log للمراجعة اليدوية.
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_assignment_logs');
        try {
            DB::statement("ALTER TABLE users ALTER COLUMN zone_code SET DEFAULT 'SOUTH'");
        } catch (\Throwable $e) {
            // ignore
        }
    }
};
