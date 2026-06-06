<?php

/**
 * AMIAL-AML-001 (v1.4)
 *
 * AML/Fraud Detection Engine — الجداول الأساسية.
 *
 * **Tables:**
 *   - aml_rules: تعريف القواعد (configurable من admin)
 *   - aml_rule_evaluations: سجل كل تقييم (audit log)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ====== Rules — تعريف ====== 
        Schema::create('aml_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique(); // MAX_SINGLE_TX, VELOCITY_5MIN, ...
            $table->string('name_ar', 200);
            $table->text('description_ar')->nullable();

            $table->enum('rule_type', [
                'max_single_transaction',
                'velocity',
                'daily_aggregate',
                'monthly_aggregate',
                'off_hours',
                'new_account_high_value',
                'structuring',
                'repeated_failures',
                'custom',
            ])->index();

            // ينطبق على أي transaction types (CSV: 'send_money,safe_payment,donation')
            $table->string('applies_to', 200)->default('send_money,safe_payment,donation,bill_pay');

            // المعاملات/الإعدادات (JSON: thresholds, time_window, etc.)
            $table->json('parameters');

            // النتيجة عند المطابقة
            $table->enum('action_on_match', ['allow', 'flag', 'hold', 'block'])
                ->default('flag');

            // المساهمة في الـ risk score (0-100)
            $table->decimal('risk_score_contribution', 5, 2)->default(10);

            // ترتيب التنفيذ
            $table->integer('priority')->default(100);

            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->unsignedBigInteger('updated_by_admin_id')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
            $table->foreign('created_by_admin_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('updated_by_admin_id')->references('id')->on('users')->onDelete('set null');
        });

        // ====== Rule Evaluations — سجل كل تقييم ====== 
        Schema::create('aml_rule_evaluations', function (Blueprint $table) {
            $table->id();

            // الـ transaction context
            $table->string('transaction_ulid', 32)->nullable();
            $table->string('transaction_type', 50)->index(); // send_money, safe_payment_fund, ...
            $table->unsignedBigInteger('actor_user_id')->index(); // المُرسل
            $table->unsignedBigInteger('counterparty_user_id')->nullable();
            $table->decimal('amount', 20, 4)->nullable();

            // الـ rule
            $table->unsignedBigInteger('rule_id');
            $table->string('rule_code', 64); // denormalized for queries

            // النتيجة
            $table->boolean('matched');
            $table->decimal('contributed_risk_score', 5, 2)->default(0);
            $table->json('evaluation_context')->nullable(); // ما حصل بالضبط

            $table->timestamp('created_at')->useCurrent();

            $table->index(['actor_user_id', 'created_at']);
            $table->index(['rule_code', 'matched']);
            $table->index(['transaction_type', 'created_at']);

            $table->foreign('rule_id')->references('id')->on('aml_rules')->onDelete('cascade');
            $table->foreign('actor_user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aml_rule_evaluations');
        Schema::dropIfExists('aml_rules');
    }
};
