<?php

/**
 * AMIAL-REFACTOR-CORE-001
 * إنشاء جدول idempotency_keys لمنع تكرار العمليات المالية بسبب retry
 *
 * يستخدم بواسطة: App\Services\IdempotencyService
 * Middleware: App\Http\Middleware\EnforceIdempotency
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();

            // المفتاح الذي يرسله العميل في header Idempotency-Key
            $table->string('key', 128);

            // المستخدم الذي أرسل الطلب (نفس key + user = نفس العملية)
            // نسمح NULL لـ pre-auth endpoints لكن نلزم عمليًا لـ financial endpoints
            $table->unsignedBigInteger('user_id')->nullable();

            // المسار (POST /api/v1/customer/send-money مثلاً) — كي لا يعاد استخدام key لمسار مختلف
            $table->string('endpoint', 191);

            // hash لـ request body — كي نكتشف لو العميل أرسل نفس key بقيم مختلفة (نضع status conflict)
            $table->string('request_hash', 64);

            // نتيجة محفوظة لإرجاعها للـ retry
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();

            // معرف العملية المالية الناتجة (لو نجحت) — يربط idempotency بـ transactions
            $table->string('transaction_id', 64)->nullable();

            // حالة المعالجة:
            // 'processing' = طلب قيد التنفيذ (يمنع طلب ثاني متزامن بنفس key)
            // 'completed'  = انتهى بنجاح، استخدم response المحفوظ
            // 'failed'     = انتهى بفشل، يمكن إعادة المحاولة بـ key جديد
            $table->enum('status', ['processing', 'completed', 'failed'])->default('processing');

            $table->timestamp('expires_at')->index(); // TTL 24h لتنظيف الجدول
            $table->timestamps();

            // UNIQUE constraint رئيسي: نفس key + user + endpoint = طلب واحد
            $table->unique(['key', 'user_id', 'endpoint'], 'idempotency_unique');

            // للاستعلام السريع عن status أثناء polling
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
