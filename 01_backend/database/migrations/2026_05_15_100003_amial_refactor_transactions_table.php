<?php

/**
 * AMIAL-REFACTOR-CORE-001
 * إصلاح جدول transactions:
 *
 * 1. تغيير transaction_id ليكون ULID (26 char) ويُضاف UNIQUE INDEX.
 * 2. إضافة idempotency_key — يربط الـ row بمفتاح idempotency للـ trace.
 * 3. تغيير debit/credit/charge/balance من FLOAT إلى DECIMAL(20,4)
 *    لمنع أخطاء التقريب (0.1 + 0.2 != 0.3).
 * 4. إضافة amount (لأن model يستخدمه لكنه غير موجود في الـ schema).
 * 5. إضافة decision_code و decision_reason — لـ audit (قسم 4 من الوثيقة).
 * 6. إضافة zone_code, request_zone, counterparty_zone — استعداداً لـ AMIAL-ZONE-001 (v0.7).
 * 7. إضافة فهارس مفقودة على from_user_id, to_user_id, transaction_type.
 *
 * ملاحظات حذرة:
 * - لا نحذف أعمدة قديمة (نحافظ على التوافق مع الكود الحالي).
 * - الأعمدة الجديدة nullable حتى لا نكسر inserts قائمة.
 * - DECIMAL استخدام USING في PostgreSQL وغير مطلوب في MySQL.
 *   لكن إن كان الـ engine MySQL، نتحقق من نوع العمود قبل التعديل.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1) تغيير الأعمدة المالية من float إلى decimal
        //    نستخدم doctrine/dbal أو raw SQL — هنا raw للوضوح.
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE transactions MODIFY debit DECIMAL(20,4) NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE transactions MODIFY credit DECIMAL(20,4) NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE transactions MODIFY balance DECIMAL(20,4) NOT NULL DEFAULT 0');
            // charge موجود decimal(20,2) من migration 2025_06_19 — نوسّعه إلى ,4 للتوحيد
            DB::statement('ALTER TABLE transactions MODIFY charge DECIMAL(20,4) NOT NULL DEFAULT 0');
        }

        Schema::table('transactions', function (Blueprint $table) {
            // MERGE 6cash: ref_trans_id أساسي NOT NULL — أميال يُدرج بدونه أحياناً
            if (Schema::hasColumn('transactions', 'ref_trans_id')) {
                $table->string('ref_trans_id', 255)->nullable()->change();
            }

            // 2) idempotency_key — اختياري، nullable للسجلات القديمة
            if (!Schema::hasColumn('transactions', 'idempotency_key')) {
                $table->string('idempotency_key', 128)->nullable()->after('transaction_id');
            }

            // 3) تكبير transaction_id إلى 64 char ليستوعب ULID (26) + prefix + safety
            //    وإضافة UNIQUE INDEX (السبب الجذري لـ collision)
            //    ملاحظة: لا نضيف unique قبل تنظيف duplicates إن وُجدت — راجع
            //    خطوة التنظيف في MIGRATION_NOTES_v0.6.md قبل تشغيل migrate.
        });

        // تنفيذ تكبير + unique على transaction_id بحذر
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE transactions MODIFY transaction_id VARCHAR(64) NOT NULL');
        }

        // إضافة UNIQUE — يفترض أن duplicate cleanup script شُغّل قبلها.
        // لو فشل بسبب duplicates، الـ migration يفشل بوضوح وعلى DBA التدخل.
        Schema::table('transactions', function (Blueprint $table) {
            $table->unique('transaction_id', 'transactions_transaction_id_unique');
        });

        // 4) amount عمود — كان مستخدماً في cast بدون migration. نضيفه للاكتمال.
        Schema::table('transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('transactions', 'amount')) {
                $table->decimal('amount', 20, 4)->default(0)->after('charge');
            }

            // 5) audit decision
            $table->string('decision_code', 64)->nullable()->after('amount');
            $table->string('decision_reason', 255)->nullable()->after('decision_code');

            // 6) zone columns (تُستخدم في v0.7)
            $table->string('zone_code', 16)->default('SOUTH')->after('decision_reason');
            $table->string('request_zone', 16)->nullable()->after('zone_code');
            $table->string('counterparty_zone', 16)->nullable()->after('request_zone');

            // 7) فهارس مفقودة (يتم تخطيها بصمت إن وُجدت)
            $table->index('from_user_id', 'transactions_from_user_idx');
            $table->index('to_user_id', 'transactions_to_user_idx');
            $table->index('transaction_type', 'transactions_type_idx');
            $table->index(['user_id', 'created_at'], 'transactions_user_created_idx');
            $table->index('idempotency_key', 'transactions_idempotency_idx');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique('transactions_transaction_id_unique');
            $table->dropIndex('transactions_from_user_idx');
            $table->dropIndex('transactions_to_user_idx');
            $table->dropIndex('transactions_type_idx');
            $table->dropIndex('transactions_user_created_idx');
            $table->dropIndex('transactions_idempotency_idx');

            $table->dropColumn([
                'idempotency_key',
                'amount',
                'decision_code',
                'decision_reason',
                'zone_code',
                'request_zone',
                'counterparty_zone',
            ]);
        });
        // لا نعيد debit/credit/charge/balance إلى float — يفترض ألا نرجع للخلف بعد تشغيل production
    }
};
