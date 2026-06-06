<?php

/**
 * AMIAL-SCALE-001 (v1.5)
 *
 * Indexes إضافية لتحسين الأداء عند 50k user.
 *
 * **المنطق:** كل query شائع يحتاج index على الأعمدة المُستخدَمة في WHERE/ORDER.
 * الـ indexes الموجودة من v0.6-v1.4 جيدة، لكن هذه تحسينات إضافية بناءً على
 * patterns متوقعة في production.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ====== aml_rule_evaluations: composite للـ velocity queries ======
        // الـ query الشائع: WHERE actor_user_id=? AND created_at >= ?
        if (Schema::hasTable('aml_rule_evaluations')) {
            Schema::table('aml_rule_evaluations', function (Blueprint $table) {
                if (!$this->hasIndex('aml_rule_evaluations', 'idx_aml_eval_user_time')) {
                    $table->index(['actor_user_id', 'created_at'], 'idx_aml_eval_user_time');
                }
                // للـ aggregate queries
                if (!$this->hasIndex('aml_rule_evaluations', 'idx_aml_eval_user_amount_time')) {
                    $table->index(['actor_user_id', 'amount', 'created_at'], 'idx_aml_eval_user_amount_time');
                }
            });
        }

        // ====== users: blind_index columns (إذا لم تكن مفهرسة) ======
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'phone_blind_index') &&
                    !$this->hasIndex('users', 'idx_users_phone_blind')) {
                    $table->index('phone_blind_index', 'idx_users_phone_blind');
                }
                if (Schema::hasColumn('users', 'email_blind_index') &&
                    !$this->hasIndex('users', 'idx_users_email_blind')) {
                    $table->index('email_blind_index', 'idx_users_email_blind');
                }
                if (Schema::hasColumn('users', 'national_id_blind_index') &&
                    !$this->hasIndex('users', 'idx_users_nid_blind')) {
                    $table->index('national_id_blind_index', 'idx_users_nid_blind');
                }
            });
        }

        // ====== receipts: queries common (للـ user + reference_type) ======
        if (Schema::hasTable('receipts')) {
            Schema::table('receipts', function (Blueprint $table) {
                if (!$this->hasIndex('receipts', 'idx_receipts_user_created')) {
                    $table->index(['user_id', 'created_at'], 'idx_receipts_user_created');
                }
                if (!$this->hasIndex('receipts', 'idx_receipts_ref_type')) {
                    $table->index(['reference_type', 'reference_id'], 'idx_receipts_ref_type');
                }
            });
        }

        // ====== donations: stats per user + per campaign ======
        if (Schema::hasTable('donations')) {
            Schema::table('donations', function (Blueprint $table) {
                if (!$this->hasIndex('donations', 'idx_donations_donor_time')) {
                    $table->index(['donor_user_id', 'donated_at'], 'idx_donations_donor_time');
                }
                if (!$this->hasIndex('donations', 'idx_donations_campaign_status')) {
                    $table->index(['campaign_id', 'status', 'donated_at'], 'idx_donations_campaign_status');
                }
            });
        }

        // ====== safe_payments: status + parties ======
        if (Schema::hasTable('safe_payments')) {
            Schema::table('safe_payments', function (Blueprint $table) {
                if (!$this->hasIndex('safe_payments', 'idx_safe_pay_buyer_status')) {
                    $table->index(['buyer_user_id', 'status', 'created_at'], 'idx_safe_pay_buyer_status');
                }
                if (!$this->hasIndex('safe_payments', 'idx_safe_pay_seller_status')) {
                    $table->index(['seller_user_id', 'status', 'created_at'], 'idx_safe_pay_seller_status');
                }
            });
        }

        // ====== bill_payment_orders ======
        if (Schema::hasTable('bill_payment_orders')) {
            Schema::table('bill_payment_orders', function (Blueprint $table) {
                if (!$this->hasIndex('bill_payment_orders', 'idx_bill_orders_user_status')) {
                    $table->index(['user_id', 'status', 'created_at'], 'idx_bill_orders_user_status');
                }
            });
        }
    }

    public function down(): void
    {
        // إزالة الـ indexes (احتياطاً - لكن indexes لا تأذي عادة)
        // اتركها للـ rollback اليدوي عند الحاجة فقط
    }

    /**
     * فحص وجود index قبل إنشاؤه (لتجنب error).
     */
    private function hasIndex(string $table, string $indexName): bool
    {
        try {
            $connection = Schema::getConnection();
            $databaseName = $connection->getDatabaseName();

            $result = $connection->select(
                "SELECT COUNT(*) as cnt FROM information_schema.statistics
                 WHERE table_schema = ? AND table_name = ? AND index_name = ?",
                [$databaseName, $table, $indexName],
            );
            return ($result[0]->cnt ?? 0) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
};
