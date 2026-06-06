<?php

/**
 * AMIAL-BILL-PAY-001 (v0.9-C)
 *
 *   - bill_payment_orders: طلبات الدفع التي ينشئها العميل
 *   - bill_provider_requests: سجل كل request/response مع المزود (للتدقيق)
 *
 * الحالات (قسم 19):
 *   pending, processing, success, failed, pending_provider_confirmation, reversed
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_payment_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_ulid', 26)->unique();

            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('provider_id');
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('product_id')->nullable();

            // معلومات العميل عند المزود
            $table->string('subscriber_account', 100);
            $table->json('subscriber_extra')->nullable(); // حقول إضافية (PIN، رقم العداد، إلخ)

            // المبلغ
            $table->decimal('amount', 20, 4);
            $table->decimal('fee', 20, 4)->default(0);
            $table->decimal('total_debited', 20, 4); // amount + fee (مخصوم فعلياً)

            // الحالة
            $table->enum('status', [
                'pending',                       // أُنشئ، لم يُرسل للمزود
                'processing',                    // أُرسل، ننتظر response
                'pending_provider_confirmation', // المزود رد بـ pending
                'success',                       // مؤكد ناجح
                'failed',                        // فشل (الأموال أُعيدت)
                'reversed',                      // ناجح ثم عُكس (admin أو dispute)
            ])->default('pending')->index();

            // ID العملية المالية الأصلية (debit)
            $table->string('wallet_transaction_id', 32)->nullable();

            // ID المزود (reference خارجي)
            $table->string('provider_reference', 100)->nullable();

            // رسالة المزود الأخيرة
            $table->string('provider_message', 500)->nullable();

            // Audit
            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reverse_reason', 255)->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['provider_id', 'status']);
            $table->index('provider_reference');

            $table->foreign('user_id')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('provider_id')->references('id')->on('bill_providers')->onDelete('restrict');
            $table->foreign('service_id')->references('id')->on('bill_services')->onDelete('restrict');
            $table->foreign('product_id')->references('id')->on('bill_service_products')->onDelete('set null');
        });

        Schema::create('bill_provider_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('provider_id');

            $table->enum('request_type', ['inquire', 'pay', 'status_check', 'reverse'])->index();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->integer('http_status')->nullable();
            $table->integer('latency_ms')->nullable();
            $table->boolean('was_successful')->default(false);
            $table->string('error_message', 500)->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
            $table->index('provider_id');

            $table->foreign('order_id')->references('id')->on('bill_payment_orders')->onDelete('cascade');
            $table->foreign('provider_id')->references('id')->on('bill_providers')->onDelete('restrict');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_provider_requests');
        Schema::dropIfExists('bill_payment_orders');
    }
};
