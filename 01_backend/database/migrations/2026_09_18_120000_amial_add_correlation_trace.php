<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-TRACE-001
 *
 * A single operational correlation id joins the customer request, the bill
 * order, provider calls, webhooks, and audit decisions. All columns are
 * nullable so records created before this migration remain valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_decisions', function (Blueprint $table) {
            $table->string('correlation_id', 64)->nullable()->after('idempotency_key');
            $table->index('correlation_id', 'audit_correlation_idx');
        });

        Schema::table('bill_payment_orders', function (Blueprint $table) {
            $table->string('correlation_id', 64)->nullable()->after('idempotency_key');
            $table->index('correlation_id', 'bill_orders_correlation_idx');
        });

        Schema::table('bill_provider_requests', function (Blueprint $table) {
            $table->string('correlation_id', 64)->nullable()->after('provider_id');
            $table->index('correlation_id', 'bill_requests_correlation_idx');
        });

        Schema::table('bill_provider_webhook_events', function (Blueprint $table) {
            $table->string('correlation_id', 64)->nullable()->after('order_id');
            $table->index('correlation_id', 'bill_webhooks_correlation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('bill_provider_webhook_events', function (Blueprint $table) {
            $table->dropIndex('bill_webhooks_correlation_idx');
            $table->dropColumn('correlation_id');
        });
        Schema::table('bill_provider_requests', function (Blueprint $table) {
            $table->dropIndex('bill_requests_correlation_idx');
            $table->dropColumn('correlation_id');
        });
        Schema::table('bill_payment_orders', function (Blueprint $table) {
            $table->dropIndex('bill_orders_correlation_idx');
            $table->dropColumn('correlation_id');
        });
        Schema::table('audit_decisions', function (Blueprint $table) {
            $table->dropIndex('audit_correlation_idx');
            $table->dropColumn('correlation_id');
        });
    }
};
