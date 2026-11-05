<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-BUSINESS-GATEWAY-001 — جداول قناة «أميال أعمال».
 *
 *   business_links            ربط تاجر أميال باي بمنشأة في أميال أعمال (واحد لواحد).
 *   business_payment_intents  نيّة دفع = طلب QR في payment_requests + مرجع منشأة فريد.
 *   business_outbox_events    أحداث تُكتب داخل معاملة السبب نفسها ثم تُسلَّم لاحقاً.
 *   business_request_nonces   منع إعادة إرسال طلب موقّع ضمن نافذة الوقت.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_links', function (Blueprint $table) {
            $table->id();
            $table->char('link_ulid', 26)->unique();
            $table->unsignedBigInteger('merchant_user_id')->unique();
            $table->string('status', 16)->default('active');
            $table->string('status_reason', 255)->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('business_payment_intents', function (Blueprint $table) {
            $table->id();
            $table->char('intent_ulid', 26)->unique();
            $table->unsignedBigInteger('business_link_id');
            $table->string('business_reference', 64);
            $table->unsignedBigInteger('payment_request_id')->unique();
            $table->decimal('amount', 24, 4);
            $table->char('currency', 3);
            $table->char('request_fingerprint', 64);
            $table->string('status', 16)->default('pending');
            $table->string('paid_transaction_id', 64)->nullable()->unique();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('expires_at');
            $table->string('description', 255)->nullable();
            $table->string('branch_ref', 64)->nullable();
            $table->string('device_ref', 64)->nullable();
            $table->timestamps();

            $table->unique(['business_link_id', 'business_reference'], 'bpi_link_reference_unique');
            $table->index(['status', 'expires_at']);
        });

        Schema::create('business_outbox_events', function (Blueprint $table) {
            $table->id();
            $table->char('event_ulid', 26)->unique();
            $table->unsignedBigInteger('business_link_id')->nullable();
            $table->string('event_type', 64);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_until')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
        });

        Schema::create('business_request_nonces', function (Blueprint $table) {
            $table->id();
            $table->string('nonce', 64)->unique();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_request_nonces');
        Schema::dropIfExists('business_outbox_events');
        Schema::dropIfExists('business_payment_intents');
        Schema::dropIfExists('business_links');
    }
};
