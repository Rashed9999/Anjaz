<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reconciliation_cases')) {
            return;
        }

        Schema::create('reconciliation_cases', function (Blueprint $table) {
            $table->id();
            $table->ulid('case_ulid')->unique();
            $table->string('case_type', 40)->index();
            $table->string('source', 80)->index();
            $table->unsignedBigInteger('subject_user_id')->nullable()->index();
            $table->unsignedBigInteger('ledger_account_id')->nullable()->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('till_id')->nullable()->index();
            $table->decimal('expected_amount', 24, 4);
            $table->decimal('actual_amount', 24, 4);
            $table->decimal('difference', 24, 4);
            $table->string('currency', 8)->default('YER');
            $table->string('status', 40)->default('detected')->index();
            $table->string('severity', 20)->default('warning')->index();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->unsignedInteger('detection_count')->default(1);
            $table->string('assigned_team', 80)->nullable();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->string('root_cause', 80)->nullable();
            $table->json('evidence')->nullable();
            $table->json('linked_transaction_ids')->nullable();
            $table->json('linked_journal_ids')->nullable();
            $table->json('linked_settlement_ids')->nullable();
            $table->json('linked_cash_movement_ids')->nullable();
            $table->text('action_taken')->nullable();
            $table->unsignedBigInteger('maker_admin_id')->nullable();
            $table->unsignedBigInteger('checker_admin_id')->nullable();
            $table->unsignedBigInteger('resolution_journal_entry_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['case_type', 'subject_user_id', 'status'], 'recon_case_subject_open');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_cases');
    }
};
