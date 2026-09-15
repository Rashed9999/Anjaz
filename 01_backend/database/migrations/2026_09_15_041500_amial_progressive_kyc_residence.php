<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-PROGRESSIVE-KYC-001 + AMIAL-RESIDENCE-001
 *
 * يفصل «من أنت؟» عن «أين تقيم فعلياً؟». محافظة الأصل تبقى حقيقة KYC،
 * أما الأهلية التشغيلية فتُشتق حصراً من إقامة راجعها موظف على دليل مسجل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'verified_residence_governorate')) {
                $table->string('verified_residence_governorate', 16)->nullable()->index();
            }
            if (!Schema::hasColumn('users', 'residence_verified_at')) {
                $table->timestamp('residence_verified_at')->nullable();
            }
            if (!Schema::hasColumn('users', 'residence_verification_id')) {
                $table->unsignedBigInteger('residence_verification_id')->nullable()->index();
            }
        });

        if (!Schema::hasTable('residence_verifications')) {
            Schema::create('residence_verifications', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('kyc_document_id')->nullable()->index();
                $table->string('declared_governorate', 16)->index();
                $table->string('evidence_type', 64);
                $table->string('evidence_strength', 16);
                $table->date('evidence_date')->nullable();
                $table->string('status', 32)->default('pending')->index();
                $table->unsignedBigInteger('reviewed_by')->nullable()->index();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->text('decision_reason')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'status']);
                $table->index(['declared_governorate', 'status']);
            });
        }

        // سياسة الحدود الجديدة تُكتب في DB لأنها مصدر الحقيقة في التشغيل.
        if (Schema::hasTable('kyc_tier_limits')) {
            $tiers = [
                0 => ['غير موثق', '0', '0', '0', '0', [], []],
                1 => ['أساسي', '100000', '100000', '100000', '100000',
                    ['phone_verified', 'verified_residence'],
                    ['send_money', 'receive_money', 'bill_pay', 'cash_out', 'merchant_pay']],
                2 => ['هوية موثقة', '250000', '250000', '250000', '250000',
                    ['phone_verified', 'national_id', 'verified_residence'],
                    ['send_money', 'receive_money', 'bill_pay', 'cash_out', 'merchant_pay', 'safe_payment', 'donations', 'family_fund']],
                3 => ['كامل', '2000000', '400000', '700000', '2000000',
                    ['phone_verified', 'national_id', 'selfie_or_approved_ownership', 'verified_residence', 'full_kyc_profile'],
                    ['*']],
            ];

            foreach ($tiers as $tier => [$name, $balance, $single, $daily, $monthly, $required, $features]) {
                DB::table('kyc_tier_limits')->updateOrInsert(
                    ['tier' => $tier],
                    [
                        'name_ar' => $name,
                        'max_balance' => $balance,
                        'max_single_transaction' => $single,
                        'max_daily_total' => $daily,
                        'max_monthly_total' => $monthly,
                        'required_documents' => json_encode($required, JSON_UNESCAPED_UNICODE),
                        'allowed_features' => json_encode($features, JSON_UNESCAPED_UNICODE),
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('residence_verifications');

        Schema::table('users', function (Blueprint $table) {
            foreach (['residence_verification_id', 'residence_verified_at', 'verified_residence_governorate'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        // إعادة القيم السابقة عند rollback بدلاً من ترك سياسة هجينة.
        if (Schema::hasTable('kyc_tier_limits')) {
            $old = [
                0 => ['غير موثق', 0, 0, 0, 0, [], []],
                1 => ['أساسي', 50000, 5000, 10000, 50000, ['phone_verified'], ['send_money', 'receive_money', 'bill_pay']],
                2 => ['قياسي', 500000, 50000, 100000, 500000, ['phone_verified', 'national_id'], ['send_money', 'receive_money', 'bill_pay', 'safe_payment', 'donations', 'family_fund']],
                3 => ['كامل', 5000000, 500000, 1000000, 5000000, ['phone_verified', 'national_id', 'address_proof', 'selfie'], ['*']],
            ];

            foreach ($old as $tier => [$name, $balance, $single, $daily, $monthly, $required, $features]) {
                DB::table('kyc_tier_limits')->where('tier', $tier)->update([
                    'name_ar' => $name,
                    'max_balance' => $balance,
                    'max_single_transaction' => $single,
                    'max_daily_total' => $daily,
                    'max_monthly_total' => $monthly,
                    'required_documents' => json_encode($required, JSON_UNESCAPED_UNICODE),
                    'allowed_features' => json_encode($features, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }
    }
};
