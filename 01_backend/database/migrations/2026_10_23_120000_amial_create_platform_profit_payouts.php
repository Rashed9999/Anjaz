<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PLATFORM-PROFIT-PAYOUT-001
 *
 * طلبُ الصرف ليس محفظةَ مستخدم جديدة. هو وثيقة تشغيلية تربط الإيراد
 * المعتمد بالقيد، ثم بحركة النقد أو البنك، ثم بإثبات الاستلام عند النقد.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_profit_payouts')) {
            Schema::create('platform_profit_payouts', function (Blueprint $table) {
                $table->id();
                $table->ulid('payout_ulid')->unique();
                $table->unsignedBigInteger('requested_by_id');
                $table->unsignedBigInteger('approval_request_id')->nullable()->unique();
                $table->string('idempotency_key', 80)->nullable()->unique();
                $table->decimal('amount', 20, 4);
                $table->string('currency', 3)->default('YER');
                $table->string('status', 32)->default('pending_approval')->index();
                $table->string('payout_method', 16); // cash | bank
                $table->string('beneficiary_name', 200);
                $table->string('destination_reference', 500);
                $table->string('request_note', 500);
                $table->string('approval_ledger_entry_ulid', 26)->nullable()->unique();
                $table->string('payment_ledger_entry_ulid', 26)->nullable()->unique();
                $table->string('handover_ulid', 26)->nullable()->unique();
                $table->string('payment_reference', 160)->nullable();
                $table->string('proof_reference', 500)->nullable();
                $table->unsignedBigInteger('approved_by_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('paid_by_id')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('rejected_by_id')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->string('rejection_reason', 500)->nullable();
                $table->timestamps();

                $table->index(['requested_by_id', 'status', 'created_at'], 'platform_profit_owner_status_idx');
                $table->index(['status', 'created_at'], 'platform_profit_status_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_profit_payouts');
    }
};
