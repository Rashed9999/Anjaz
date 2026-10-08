<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-BILL-HUB-001
 *
 * Adds the safe integration boundary for external bill providers.  It is
 * intentionally additive: existing bill orders remain readable and any order
 * created before this migration keeps a null funds_state, which the service
 * recognises as the legacy direct-debit flow during reconciliation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_providers', function (Blueprint $table) {
            // Secrets never live in the JSON config exposed to normal admin reads.
            $table->text('credentials_encrypted')->nullable()->after('api_key_encrypted');
            $table->string('integration_status', 32)->default('not_configured')->after('is_active');
            $table->timestamp('configured_at')->nullable();
            $table->timestamp('last_health_checked_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->string('last_health_message', 500)->nullable();
            $table->decimal('last_known_balance', 20, 4)->nullable();
            $table->string('balance_currency', 16)->nullable();
            $table->timestamp('balance_checked_at')->nullable();
            $table->unsignedInteger('failure_streak')->default(0);

            $table->index(['integration_status', 'is_active'], 'bill_providers_health_status_idx');
        });

        Schema::table('bill_payment_orders', function (Blueprint $table) {
            // The API idempotency key is also persisted at the business boundary.
            // This protects non-HTTP callers and makes replay traceable.
            $table->string('idempotency_key', 128)->nullable()->after('order_ulid');

            // held -> captured/released. Null is deliberately reserved for orders
            // written by the old debit/refund path before this migration.
            $table->string('funds_state', 32)->nullable()->after('total_debited');
            $table->unsignedInteger('provider_attempt_count')->default(0)->after('provider_message');
            $table->timestamp('last_provider_check_at')->nullable();
            $table->timestamp('next_reconciliation_at')->nullable();

            // Immutable fee-rule reference at the instant the customer approved.
            $table->unsignedBigInteger('fee_scheme_id')->nullable();
            $table->unsignedInteger('fee_scheme_version')->nullable();
            $table->string('fee_configuration_state', 32)->nullable();

            $table->unique(['user_id', 'idempotency_key'], 'bill_orders_user_idempotency_unique');
            $table->index(['status', 'next_reconciliation_at'], 'bill_orders_reconciliation_idx');
        });

        // Durable inbox for asynchronous provider callbacks. A webhook only
        // schedules an authoritative status query; it never moves money itself.
        Schema::create('bill_provider_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('provider_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('event_fingerprint', 64)->unique();
            $table->string('provider_transaction_id', 128)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->string('operation_status', 32)->nullable();
            $table->decimal('price', 20, 4)->nullable();
            $table->string('message', 500)->nullable();
            $table->json('payload');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->string('processing_result', 32)->nullable();
            $table->string('processing_error', 500)->nullable();

            $table->index(['provider_id', 'received_at'], 'bill_webhooks_provider_received_idx');
            $table->index(['order_id', 'received_at'], 'bill_webhooks_order_received_idx');
            $table->index('provider_transaction_id', 'bill_webhooks_transaction_idx');
            $table->foreign('provider_id')->references('id')->on('bill_providers')->onDelete('restrict');
            $table->foreign('order_id')->references('id')->on('bill_payment_orders')->onDelete('set null');
        });

        // The real provider is visible to Operations after migration but remains
        // disabled until credentials are encrypted, the balance test succeeds,
        // and an authorized administrator explicitly activates it.
        DB::table('bill_providers')->updateOrInsert(
            ['code' => 'free_sadad'],
            [
                'name' => 'Free Sadad',
                'display_name_ar' => 'فري سداد',
                'integration_type' => 'free_sadad',
                'endpoint_url' => 'https://free-sadad.com/rest-api',
                'config' => json_encode([
                    'timeout_seconds' => 15,
                    'currency' => 'YER',
                    'description' => 'تكامل فواتير اتصالات وإنترنت عبر Free Sadad. لا يُفعّل قبل اختبار الرصيد.',
                ], JSON_UNESCAPED_UNICODE),
                'is_active' => false,
                'integration_status' => 'not_configured',
                'zone_code' => 'SOUTH',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_provider_webhook_events');

        Schema::table('bill_payment_orders', function (Blueprint $table) {
            $table->dropUnique('bill_orders_user_idempotency_unique');
            $table->dropIndex('bill_orders_reconciliation_idx');
            $table->dropColumn([
                'idempotency_key', 'funds_state', 'provider_attempt_count',
                'last_provider_check_at', 'next_reconciliation_at',
                'fee_scheme_id', 'fee_scheme_version', 'fee_configuration_state',
            ]);
        });

        Schema::table('bill_providers', function (Blueprint $table) {
            $table->dropIndex('bill_providers_health_status_idx');
            $table->dropColumn([
                'credentials_encrypted', 'integration_status', 'configured_at',
                'last_health_checked_at', 'last_success_at', 'last_failure_at',
                'last_health_message', 'last_known_balance', 'balance_currency',
                'balance_checked_at', 'failure_streak',
            ]);
        });
    }
};
