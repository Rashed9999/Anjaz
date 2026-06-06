<?php

/**
 * AMIAL-SAFE-PAYMENT-001 (v1.1)
 *
 * safe_payment_events: سجل كل state transition ⇒ append-only.
 *
 * كل event يحتوي: من → إلى، الفاعل، السبب، context.
 * يُستخدم في:
 *   - عرض timeline للمشتري/البائع
 *   - audit للإدارة
 *   - حل النزاعات (تتبع التسلسل الزمني)
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safe_payment_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('safe_payment_id');

            $table->enum('event_type', [
                'created',
                'seller_accepted',
                'seller_rejected',
                'in_delivery_marked',
                'delivered_marked',
                'buyer_confirmed',
                'released_to_seller',
                'buyer_disputed',
                'buyer_cancelled',
                'admin_resolved_release',
                'admin_resolved_refund',
                'admin_resolved_partial',
                'expired',
                'attachment_added',
                'note_added',
            ]);

            $table->string('from_status', 50)->nullable();
            $table->string('to_status', 50)->nullable();

            // من قام بالـ event
            $table->enum('actor_type', ['buyer', 'seller', 'admin', 'system'])->index();
            $table->unsignedBigInteger('actor_user_id')->nullable();

            // محتوى الـ event
            $table->text('note')->nullable();
            $table->json('attachments')->nullable();
            $table->json('context')->nullable();

            // ip + user agent للـ audit
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamp('created_at')->useCurrent();
            // ← no updated_at — append-only

            $table->index(['safe_payment_id', 'created_at']);

            $table->foreign('safe_payment_id')->references('id')->on('safe_payments')->onDelete('cascade');
            $table->foreign('actor_user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_payment_events');
    }
};
