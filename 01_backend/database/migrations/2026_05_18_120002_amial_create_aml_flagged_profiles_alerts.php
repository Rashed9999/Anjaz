<?php

/**
 * AMIAL-AML-001 (v1.4)
 *
 * Flagged transactions + user risk profiles + alerts.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ====== Flagged Transactions — للمراجعة الإدارية ======
        Schema::create('aml_flagged_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('flag_ulid', 26)->unique();

            $table->string('transaction_ulid', 32)->nullable();
            $table->string('transaction_type', 50);
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('counterparty_user_id')->nullable();
            $table->decimal('amount', 20, 4)->nullable();

            // الـ risk total
            $table->decimal('total_risk_score', 6, 2);
            $table->json('triggered_rules'); // [{code, score, context}, ...]

            // الـ decision
            $table->enum('initial_decision', ['flag', 'hold', 'block']);
            $table->enum('current_status', [
                'pending_review',
                'approved_by_admin',
                'rejected_by_admin',
                'escalated',
                'auto_resolved',
            ])->default('pending_review')->index();

            // الإدارة
            $table->unsignedBigInteger('assigned_to_admin_id')->nullable();
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_decision_note')->nullable();

            // الـ transaction status بعد المراجعة
            $table->boolean('transaction_executed')->default(false);
            $table->timestamp('executed_at')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['current_status', 'created_at']);
            $table->index(['actor_user_id', 'current_status']);
            $table->index(['transaction_type', 'current_status']);

            $table->foreign('actor_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('counterparty_user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('assigned_to_admin_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('reviewed_by_admin_id')->references('id')->on('users')->onDelete('set null');
        });

        // ====== User Risk Profiles — denormalized stats per user ======
        Schema::create('aml_user_risk_profiles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();

            $table->decimal('current_risk_score', 6, 2)->default(0);
            $table->enum('risk_level', ['low', 'medium', 'high', 'critical'])
                ->default('low')->index();

            // إحصاءات (rolling)
            $table->unsignedInteger('total_transactions')->default(0);
            $table->unsignedInteger('total_flagged')->default(0);
            $table->unsignedInteger('total_blocked')->default(0);
            $table->unsignedInteger('total_held')->default(0);

            $table->decimal('avg_transaction_amount', 20, 4)->nullable();
            $table->decimal('max_transaction_amount', 20, 4)->nullable();
            $table->decimal('lifetime_volume', 20, 4)->default(0);

            // آخر تحديث
            $table->timestamp('last_evaluation_at')->nullable();
            $table->timestamp('last_flagged_at')->nullable();
            $table->timestamp('last_review_at')->nullable();

            // Override من الإدارة (إذا تم whitelist أو blacklist يدوياً)
            $table->enum('manual_override', ['none', 'whitelist', 'blacklist'])
                ->default('none');
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('override_admin_id')->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('override_admin_id')->references('id')->on('users')->onDelete('set null');
        });

        // ====== Alerts — للـ admin ======
        Schema::create('aml_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('alert_ulid', 26)->unique();
            $table->string('alert_code', 64)->index(); // VELOCITY_BREACH, NEW_ACCOUNT_HIGH_VALUE, ...

            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->index();
            $table->string('subject_type', 50); // user, transaction, flagged_transaction
            $table->unsignedBigInteger('subject_id');

            $table->string('title_ar', 200);
            $table->text('message_ar');
            $table->json('context')->nullable();

            $table->enum('status', ['open', 'acknowledged', 'resolved', 'dismissed'])
                ->default('open')->index();

            $table->unsignedBigInteger('assigned_to_admin_id')->nullable();
            $table->unsignedBigInteger('resolved_by_admin_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();

            $table->timestamps();

            $table->index(['status', 'severity', 'created_at']);
            $table->index(['subject_type', 'subject_id']);

            $table->foreign('assigned_to_admin_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('resolved_by_admin_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aml_alerts');
        Schema::dropIfExists('aml_user_risk_profiles');
        Schema::dropIfExists('aml_flagged_transactions');
    }
};
