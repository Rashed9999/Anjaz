<?php

/**
 * AMIAL-SANCTION-001 + AMIAL-KYC-TIERS-001 (v1.9)
 *
 * Sanction screening lists + KYC tiers مع حدود مختلفة.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ====== قوائم العقوبات (sanction lists) ======
        Schema::create('sanction_list_entries', function (Blueprint $table) {
            $table->id();
            $table->string('list_source', 50); // OFAC, UN, EU, LOCAL
            $table->string('entry_type', 20)->default('individual'); // individual, entity

            // الاسم + بدائله (للمطابقة الضبابية)
            $table->string('full_name', 300);
            $table->string('normalized_name', 300)->index(); // للبحث
            $table->json('aliases')->nullable();

            // معرفات إضافية
            $table->string('national_id_hash', 64)->nullable()->index(); // hashed
            $table->string('passport_hash', 64)->nullable()->index();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality', 100)->nullable();

            $table->string('program', 200)->nullable(); // برنامج العقوبات
            $table->text('remarks')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['list_source', 'is_active']);
        });

        // ====== سجل فحوصات العقوبات ======
        Schema::create('sanction_screening_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('screened_name', 300);
            $table->enum('result', ['clear', 'potential_match', 'confirmed_match'])->default('clear');
            $table->decimal('match_score', 5, 2)->nullable(); // 0-100
            $table->unsignedBigInteger('matched_entry_id')->nullable();
            $table->string('screening_context', 50); // registration, transaction, periodic
            $table->json('details')->nullable();
            $table->timestamp('screened_at')->useCurrent();

            $table->index(['user_id', 'result']);
            $table->index(['result', 'screened_at']);
        });

        // ====== KYC tiers على المستخدمين ======
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'kyc_tier')) {
                $table->unsignedTinyInteger('kyc_tier')->default(0)->index();
                // 0 = unverified, 1 = basic, 2 = standard, 3 = full
                $table->timestamp('kyc_tier_updated_at')->nullable();
                $table->boolean('sanction_checked')->default(false);
                $table->enum('sanction_status', ['clear', 'flagged', 'blocked'])->default('clear');
            }
        });

        // ====== تعريف الـ tiers وحدودها ======
        Schema::create('kyc_tier_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('tier');
            $table->string('name_ar', 100);
            $table->decimal('max_balance', 20, 4); // أقصى رصيد
            $table->decimal('max_single_transaction', 20, 4);
            $table->decimal('max_daily_total', 20, 4);
            $table->decimal('max_monthly_total', 20, 4);
            $table->json('required_documents')->nullable();
            $table->json('allowed_features')->nullable(); // ['send_money', 'safe_payment', ...]
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_tier_limits');
        Schema::dropIfExists('sanction_screening_logs');
        Schema::dropIfExists('sanction_list_entries');
        Schema::table('users', function (Blueprint $table) {
            foreach (['kyc_tier', 'kyc_tier_updated_at', 'sanction_checked', 'sanction_status'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
