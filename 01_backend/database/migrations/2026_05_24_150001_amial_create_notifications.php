<?php

/**
 * AMIAL-NOTIFICATIONS-001 — مركز الإشعارات (in-app).
 *
 * شعار الجرس في كل شاشة كان فارغاً (Flutter يتوقّع endpoint لم يكن موجوداً).
 * هذا الجدول الأساس: إشعار مستهدف لمستخدم واحد.
 *
 * الـ type:
 *   - transfer_received     | transfer_sent
 *   - withdrawal_completed  | withdrawal_failed
 *   - credit_sale | credit_payment | credit_over_limit
 *   - merchant_payment_received
 *   - system | promo | terms_update
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amial_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();

            $table->string('type', 48);
            $table->string('title', 160);
            $table->text('body');

            // أيقونة بصرية (اسم أو URL) للعرض في الـ UI
            $table->string('icon', 80)->nullable();

            // deep-link اختياري (e.g. amyal://credit/customers/123)
            $table->string('action_url', 255)->nullable();

            // بيانات تتعلق بالإشعار (مرجع عملية، مبلغ، إلخ)
            $table->json('data')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amial_notifications');
    }
};
