<?php

/**
 * AMIAL-REFACTOR-CORE-001
 * تحويل أعمدة e_money من FLOAT إلى DECIMAL(20,4).
 *
 * السبب: FLOAT يسبب أخطاء تقريب — راجع AUDIT_v0.6.md نقطة 1.4.
 *
 * استراتيجية الانتقال (في v0.7 سننقل إلى minor_units BIGINT):
 * - v0.6: DECIMAL(20,4) — يحل مشكلة التقريب فوراً، ولا يكسر الكود الحالي.
 * - v0.7+: نضيف BIGINT minor_units (= amount × 10000) للمنطق الجديد فقط،
 *   مع متزامن (writer) يحدث العمود المقابل.
 *
 * أيضاً: إضافة فهارس وعمود user_id UNIQUE (محفظة واحدة لكل مستخدم).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE e_money MODIFY current_balance DECIMAL(20,4) NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE e_money MODIFY charge_earned DECIMAL(20,4) NOT NULL DEFAULT 0');
            // pending_balance قد لا يكون موجوداً في schema الأصلية لكنه في الـ casts — نضيفه آمناً
            if (!Schema::hasColumn('e_money', 'pending_balance')) {
                DB::statement('ALTER TABLE e_money ADD COLUMN pending_balance DECIMAL(20,4) NOT NULL DEFAULT 0 AFTER charge_earned');
            } else {
                DB::statement('ALTER TABLE e_money MODIFY pending_balance DECIMAL(20,4) NOT NULL DEFAULT 0');
            }
        }

        Schema::table('e_money', function (Blueprint $table) {
            // محفظة واحدة لكل user_id
            $table->unique('user_id', 'e_money_user_id_unique');

            // ميزة v0.7: held_balance للدفع الآمن (يحجز ولا يفرج إلا بقرار)
            // نضيفه الآن لأنه يخص schema المركزي
            if (!Schema::hasColumn('e_money', 'held_balance')) {
                $table->decimal('held_balance', 20, 4)->default(0)->after('pending_balance');
            }

            // zone — يربط المحفظة بمنطقة تشغيلية (SOUTH افتراضياً لـ v0.6)
            if (!Schema::hasColumn('e_money', 'zone_code')) {
                $table->string('zone_code', 16)->default('SOUTH')->after('held_balance');
                $table->index('zone_code', 'e_money_zone_idx');
            }

            // version optimistic للـ optimistic locking كبديل مساعد لـ pessimistic
            if (!Schema::hasColumn('e_money', 'version')) {
                $table->unsignedBigInteger('version')->default(0)->after('zone_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('e_money', function (Blueprint $table) {
            $table->dropUnique('e_money_user_id_unique');
            $table->dropIndex('e_money_zone_idx');
            $table->dropColumn(['held_balance', 'zone_code', 'version']);
        });
        // current_balance, charge_earned, pending_balance يبقون DECIMAL — لا نعود لـ FLOAT
    }
};
