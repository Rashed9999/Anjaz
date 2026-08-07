<?php

/**
 * AMIAL-PAYMENT-REQUESTS-001 — طلب أموال (Payment Requests).
 *
 * يحلّ ميزة "طلب أموال / فواتير" (شاشات 22، 25).
 * المستخدم ينشئ طلباً برمز QR أو رابط قصير، ويرسله للمستلم.
 * المستلم يفتحه ويدفعه — يحوّل المال من محفظته لمحفظة الطالب.
 *
 *   request_ulid: 26 char ULID (مرجع داخلي)
 *   short_code:   6 char (لتقصير الرابط amial.pay/req/XYZ123)
 *   recipient:    nullable — قد يكون عاماً (مع أيّ شخص يفتح الرابط)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_ulid', 26)->unique();
            $table->string('short_code', 8)->unique(); // للرابط القصير
            $table->unsignedBigInteger('requester_user_id')->index(); // من يطلب

            // المستلم: قد يكون مسجّلاً، أو فقط رقم هاتف، أو عاماً (الكل nullable)
            $table->unsignedBigInteger('recipient_user_id')->nullable()->index();
            $table->string('recipient_phone', 32)->nullable();
            $table->string('recipient_name', 120)->nullable();

            $table->decimal('amount', 20, 4);
            $table->string('note', 255)->nullable();
            $table->string('share_method', 16)->default('link'); // 'link' | 'qr'

            // pending | paid | cancelled | expired
            $table->string('status', 16)->default('pending')->index();

            // عند الدفع
            $table->unsignedBigInteger('paid_by_user_id')->nullable();
            $table->string('paid_transaction_id', 64)->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamp('expires_at');

            // التكرار (للفواتير الدورية)
            $table->boolean('is_recurring')->default(false);
            $table->string('recurring_period', 16)->nullable(); // daily | weekly | monthly
            $table->unsignedBigInteger('parent_request_id')->nullable()->index();

            $table->string('zone_code', 16)->default('SOUTH');
            $table->timestamps();

            $table->index(['requester_user_id', 'status']);
            $table->index(['recipient_user_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_requests');
    }
};
