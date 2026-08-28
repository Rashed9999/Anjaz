<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholesale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_ulid', 26)->unique();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('invoice_id')->index();
            $table->unsignedBigInteger('customer_id')->index();
            $table->unsignedBigInteger('requested_by_user_id');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->string('status', 16)->default('requested'); // requested|approved|rejected
            $table->string('settlement_type', 24)->nullable(); // credit_note|refund_pending
            $table->decimal('subtotal_amount', 14, 4);
            $table->decimal('discount_amount', 14, 4)->default(0);
            $table->decimal('tax_amount', 14, 4)->default(0);
            $table->decimal('total_amount', 14, 4);
            $table->decimal('credited_amount', 14, 4)->default(0);
            $table->decimal('refund_due_amount', 14, 4)->default(0);
            $table->text('reason');
            $table->text('decision_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['business_id', 'status']);
        });

        Schema::create('wholesale_return_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('return_id')->index();
            $table->unsignedBigInteger('invoice_item_id')->index();
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->string('product_name', 200);
            $table->string('unit', 32);
            $table->decimal('quantity', 14, 4);
            $table->decimal('unit_price', 14, 4);
            $table->decimal('discount_per_unit', 14, 4)->default(0);
            $table->decimal('line_total', 14, 4);
            $table->timestamps();
            $table->unique(['return_id', 'invoice_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholesale_return_items');
        Schema::dropIfExists('wholesale_returns');
    }
};
