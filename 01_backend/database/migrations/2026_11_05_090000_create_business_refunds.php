<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-BUSINESS-REFUND-001 — استرداد دفعات «أميال أعمال» إلى محفظة الدافع.
 *
 *   business_refunds            طلب استرداد من أميال أعمال لنيّة دفع ناجحة. لا يُنفَّذ
 *                               إلا بموافقة التاجر نفسه (PIN) أو ضمن تفويضه المسبق.
 *   business_links.refund_*     تفويض التاجر للاسترداد التلقائي: حدٌّ للعملية وحدٌّ يومي،
 *                               يضعه التاجر برمز PIN. صفر = كل استرداد ينتظر موافقته.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_links', function (Blueprint $table) {
            $table->decimal('refund_auto_limit', 24, 4)->default(0)->after('credit_handover_fingerprint');
            $table->decimal('refund_daily_limit', 24, 4)->default(0)->after('refund_auto_limit');
            $table->timestamp('refund_mandate_set_at')->nullable()->after('refund_daily_limit');
        });

        Schema::create('business_refunds', function (Blueprint $table) {
            $table->id();
            $table->char('refund_ulid', 26)->unique();
            $table->unsignedBigInteger('business_link_id');
            $table->unsignedBigInteger('business_payment_intent_id');
            $table->string('business_reference', 64);
            $table->decimal('amount', 24, 4);
            $table->char('currency', 3);
            $table->char('request_fingerprint', 64);
            $table->unsignedBigInteger('customer_user_id');
            $table->string('status', 24)->default('pending_approval'); // pending_approval | completed | rejected | expired
            $table->string('approval', 16)->nullable();                 // mandate | merchant_pin
            $table->string('reason', 255)->nullable();
            $table->string('decision_note', 255)->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('ledger_entry_ulid', 40)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['business_link_id', 'business_reference'], 'brf_link_reference_unique');
            $table->index(['business_payment_intent_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_refunds');
        Schema::table('business_links', function (Blueprint $table) {
            $table->dropColumn(['refund_auto_limit', 'refund_daily_limit', 'refund_mandate_set_at']);
        });
    }
};
