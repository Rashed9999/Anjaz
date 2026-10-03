<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional. The first production attempt created
        // this table successfully, then failed while adding an overlong
        // composite-index name. Laravel therefore did not record the migration,
        // but the table remained. A retry must heal that partial state instead
        // of trying CREATE TABLE again.
        if (! Schema::hasTable('merchant_payout_requests')) {
            Schema::create('merchant_payout_requests', function (Blueprint $table) {
                $table->id();
                $table->string('payout_ulid', 26);
                $table->unsignedBigInteger('merchant_user_id');
                $table->decimal('amount', 20, 4);
                $table->string('currency', 3)->default('YER');
                $table->enum('status', ['pending', 'approved', 'paid', 'rejected'])->default('pending');
                $table->string('request_note', 500)->nullable();
                // لا يُعطى التاجر عنواناً أو مرجعاً قبل أن يعتمد موظف المالية الطلب.
                $table->string('collection_instructions', 1000)->nullable();
                $table->string('handover_ulid', 26)->nullable();
                $table->unsignedBigInteger('approved_by_id')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->unsignedBigInteger('paid_by_id')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->unsignedBigInteger('rejected_by_id')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->string('rejection_reason', 500)->nullable();
                $table->timestamps();
            });
        }

        // Heal both a fresh install and the production table left behind by the
        // failed first attempt. Detect by indexed columns, not just by name, so
        // an already-created Laravel default index is not duplicated.
        if (! $this->hasIndex(['payout_ulid'], true)) {
            Schema::table('merchant_payout_requests', function (Blueprint $table) {
                $table->unique('payout_ulid', 'merchant_payout_ulid_unique');
            });
        }

        if (! $this->hasIndex(['handover_ulid'])) {
            Schema::table('merchant_payout_requests', function (Blueprint $table) {
                $table->index('handover_ulid', 'merchant_payout_handover_idx');
            });
        }

        if (! $this->hasIndex(['merchant_user_id', 'status', 'created_at'])) {
            Schema::table('merchant_payout_requests', function (Blueprint $table) {
                $table->index(
                    ['merchant_user_id', 'status', 'created_at'],
                    'merchant_payout_user_status_created_idx'
                );
            });
        }

        if (! $this->hasMerchantForeignKey()) {
            Schema::table('merchant_payout_requests', function (Blueprint $table) {
                $table->foreign('merchant_user_id', 'merchant_payout_user_fk')
                    ->references('id')
                    ->on('users')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_payout_requests');
    }

    private function hasIndex(array $columns, ?bool $unique = null): bool
    {
        if (! Schema::hasTable('merchant_payout_requests')) {
            return false;
        }

        foreach (Schema::getIndexes('merchant_payout_requests') as $index) {
            $indexedColumns = array_values($index['columns'] ?? []);
            if ($indexedColumns !== $columns) {
                continue;
            }

            if ($unique !== null && (bool) ($index['unique'] ?? false) !== $unique) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function hasMerchantForeignKey(): bool
    {
        if (! Schema::hasTable('merchant_payout_requests')) {
            return false;
        }

        foreach (Schema::getForeignKeys('merchant_payout_requests') as $foreign) {
            if (array_values($foreign['columns'] ?? []) === ['merchant_user_id']
                && ($foreign['foreign_table'] ?? null) === 'users'
                && array_values($foreign['foreign_columns'] ?? []) === ['id']) {
                return true;
            }
        }

        return false;
    }
};
