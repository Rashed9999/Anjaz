<?php

/**
 * AMIAL-MERCHANT-REFUND-001 (v1.7)
 *
 * استرجاع التاجر — جدول مرتبط بالعملية الأصلية.
 *
 * وفقاً للوثيقة Section 12:
 *   - الاسترجاع فقط من عملية أصلية ناجحة ولنفس العميل
 *   - لا يتجاوز المتبقي القابل للاسترجاع
 *   - لا نعدل العملية الأصلية؛ ننشئ عملية جديدة type=refund_merchant
 *   - حتى 5,000 مباشر، أكثر يحتاج موافقة
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_refunds', function (Blueprint $table) {
            $table->id();
            $table->string('refund_ulid', 26)->unique();

            $table->unsignedBigInteger('merchant_user_id');
            $table->unsignedBigInteger('customer_user_id');
            $table->unsignedBigInteger('pos_user_id')->nullable(); // الموظف الذي نفذ

            // العملية الأصلية
            $table->string('original_transaction_id', 64);
            $table->decimal('original_amount', 20, 4);

            // مبلغ الاسترجاع
            $table->decimal('refund_amount', 20, 4);
            $table->string('reason', 500)->nullable();

            // الحالة
            $table->enum('status', [
                'pending_approval',  // > 5000، ينتظر موافقة
                'completed',         // تم الاسترجاع
                'rejected',          // رُفض
            ])->default('completed')->index();

            $table->unsignedBigInteger('approved_by_admin_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('ledger_entry_ulid', 26)->nullable();
            $table->unsignedBigInteger('receipt_id')->nullable();

            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['merchant_user_id', 'status']);
            $table->index(['customer_user_id', 'created_at']);
            $table->index('original_transaction_id');

            $table->foreign('merchant_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('customer_user_id')->references('id')->on('users')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_refunds');
    }
};
