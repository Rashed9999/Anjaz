<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_return_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_ulid', 26)->unique();
            $table->unsignedBigInteger('return_id')->index();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('paid_by_user_id');
            $table->unsignedBigInteger('cashier_shift_id')->nullable()->index();
            $table->string('method', 24); // cash|amial_pay
            $table->decimal('amount', 14, 4);
            $table->unsignedBigInteger('customer_user_id')->nullable()->index();
            $table->string('ledger_entry_ulid', 26)->nullable()->index();
            $table->string('reference', 100)->nullable();
            $table->string('idempotency_key', 120)->unique();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['return_id', 'created_at']);
            $table->index(['merchant_user_id', 'method', 'created_at'], 'wret_settle_merchant_method_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_return_settlements');
    }
};
