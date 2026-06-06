<?php

/**
 * AMIAL-AML-002 (v2.5) — توسعة AML بـ Shadow Mode + قواعد سلوكية.
 *
 * بناءً على وثيقة "رصد" — لكن بدمج ذكي في الـ AML الموجود بدل نظام موازٍ.
 *
 * الإضافات:
 *   1. shadow_mode على القواعد (مراقبة دون إيقاف فعلي)
 *   2. أنواع قواعد جديدة: circular_transfer, agent_velocity, settlement_anomaly
 *   3. سجل قرارات الـ shadow (ماذا كان النظام سيفعل لو لم يكن shadow)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) shadow_mode على كل قاعدة
        Schema::table('aml_rules', function (Blueprint $table) {
            if (!Schema::hasColumn('aml_rules', 'shadow_mode')) {
                $table->boolean('shadow_mode')->default(false)->after('is_active');
                // shadow_mode = true: القاعدة تُقيَّم وتُسجَّل، لكن لا تُوقف فعلياً
            }
        });

        // 2) توسعة enum أنواع القواعد (نستخدم raw لإضافة قيم للـ enum)
        // ملاحظة: في SQLite (اختبارات) الـ enum نص حر، فلا مشكلة.
        try {
            DB::statement("ALTER TABLE aml_rules MODIFY COLUMN rule_type VARCHAR(50)");
        } catch (\Throwable $e) {
            // SQLite أو سبق التعديل — تجاهل
        }

        // 3) سجل قرارات shadow (للمقارنة: ماذا كان سيحدث)
        if (!Schema::hasTable('aml_shadow_decisions')) {
            Schema::create('aml_shadow_decisions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('transaction_ulid', 32)->nullable();
                $table->string('transaction_type', 50);
                $table->decimal('amount', 20, 4)->default(0);

                // ماذا كان النظام سيقرر لو لم يكن shadow
                $table->enum('would_be_action', ['allow', 'flag', 'hold', 'block']);
                $table->decimal('total_risk_score', 5, 2)->default(0);
                $table->json('triggered_rules')->nullable(); // أي قواعد أطلقت

                // ماذا حدث فعلاً (في shadow: دائماً allow)
                $table->enum('actual_action', ['allow', 'flag', 'hold', 'block'])->default('allow');

                $table->timestamp('created_at')->useCurrent();

                $table->index(['would_be_action', 'created_at']);
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('aml_shadow_decisions');
        Schema::table('aml_rules', function (Blueprint $table) {
            if (Schema::hasColumn('aml_rules', 'shadow_mode')) {
                $table->dropColumn('shadow_mode');
            }
        });
    }
};
