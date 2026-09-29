<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_payout_requests', function (Blueprint $table) {
            $table->id();
            $table->string('payout_ulid', 26)->unique();
            $table->unsignedBigInteger('merchant_user_id');
            $table->decimal('amount', 20, 4);
            $table->string('currency', 3)->default('YER');
            $table->enum('status', ['pending', 'approved', 'paid', 'rejected'])->default('pending');
            $table->string('request_note', 500)->nullable();
            // لا يُعطى التاجر عنواناً أو مرجعاً قبل أن يعتمد موظف المالية الطلب.
            $table->string('collection_instructions', 1000)->nullable();
            $table->string('handover_ulid', 26)->nullable()->index();
            $table->unsignedBigInteger('approved_by_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('paid_by_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('rejected_by_id')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(
                ['merchant_user_id', 'status', 'created_at'],
                'merchant_payout_user_status_created_idx'
            );
            $table->foreign('merchant_user_id')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_payout_requests');
    }
};
