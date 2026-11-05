<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_credit_collections', function (Blueprint $table) {
            $table->id();
            $table->string('collection_ulid',26)->unique();
            $table->unsignedBigInteger('merchant_user_id')->index();
            $table->unsignedBigInteger('account_id')->index();
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('pos_user_id')->nullable();
            $table->unsignedBigInteger('cashier_shift_id')->nullable()->index();
            $table->string('payment_method',16);
            $table->string('status',16);
            $table->decimal('amount',20,4);
            $table->string('idempotency_key',128);
            $table->unsignedBigInteger('payment_request_id')->nullable()->unique();
            $table->string('paid_transaction_id',40)->nullable()->unique();
            $table->unsignedBigInteger('credit_movement_id')->nullable()->unique();
            $table->unsignedBigInteger('receipt_id')->nullable()->unique();
            $table->string('sale_movement_ulid',40)->nullable();
            $table->string('note',255)->nullable();
            $table->timestamps();
            $table->unique(['merchant_user_id','idempotency_key'],'credit_collection_idem');
            $table->index(['merchant_user_id','status','created_at'],'credit_collection_owner_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_credit_collections');
    }
};
