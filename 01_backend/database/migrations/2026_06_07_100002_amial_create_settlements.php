<?php

/**
 * USE-001 — جدول طلبات التسوية.
 * draft → pending_approval → approved → processing → completed
 *                                     ↘ rejected
 * pending_approval/approved/processing → cancelled
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number', 32)->unique();
            $table->string('ulid', 26)->unique();

            $table->unsignedBigInteger('source_ledger_account_id');
            $table->index('source_ledger_account_id', 'set_source');

            $table->unsignedBigInteger('destination_partner_id');
            $table->string('destination_account_detail', 120)->nullable();
            $table->index('destination_partner_id', 'set_dest');

            $table->string('currency', 3)->default('YER');
            $table->decimal('amount', 18, 4);

            $table->string('status', 24)->default('draft');

            $table->string('external_reference', 100)->nullable();
            $table->string('external_bank_ref', 100)->nullable();
            $table->string('attachment_path', 255)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->unsignedBigInteger('debit_journal_entry_id')->nullable();
            $table->unsignedBigInteger('reverse_journal_entry_id')->nullable();

            $table->decimal('balance_before', 18, 4)->nullable();
            $table->decimal('balance_after', 18, 4)->nullable();

            $table->text('notes')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index('status', 'set_status');
            $table->index('created_at', 'set_created');
            $table->index(['status', 'created_at'], 'set_status_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};
