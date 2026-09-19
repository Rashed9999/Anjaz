<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** AMIAL-PROGRESSIVE-KYC-001 — حدود مستويات التحقق التدريجي. */
class KycTierLimitsSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'tier' => 0, 'name_ar' => 'عميل غير موثق',
                'max_balance' => 0, 'max_single_transaction' => 0,
                'max_daily_total' => 0, 'max_monthly_total' => 0,
                'max_annual_total' => 0,
                'required_documents' => json_encode([]),
                'allowed_features' => json_encode([]),
            ],
            [
                'tier' => 1, 'name_ar' => 'عميل موثق جزئيا',
                'max_balance' => 100000, 'max_single_transaction' => 100000,
                'max_daily_total' => 100000, 'max_monthly_total' => 100000,
                'max_annual_total' => 0,
                'required_documents' => json_encode(['phone_verified', 'verified_residence']),
                'allowed_features' => json_encode([
                    'send_money', 'receive_money', 'bill_pay', 'cash_out', 'merchant_pay',
                ]),
            ],
            [
                'tier' => 2, 'name_ar' => 'عميل موثق بهوية',
                'max_balance' => 250000, 'max_single_transaction' => 250000,
                'max_daily_total' => 250000, 'max_monthly_total' => 250000,
                'max_annual_total' => 0,
                'required_documents' => json_encode(['phone_verified', 'national_id', 'verified_residence']),
                'allowed_features' => json_encode([
                    'send_money', 'receive_money', 'bill_pay', 'cash_out', 'merchant_pay',
                    'safe_payment', 'donations', 'family_fund',
                ]),
            ],
            [
                'tier' => 3, 'name_ar' => 'عميل موثق',
                'max_balance' => 8000000, 'max_single_transaction' => 1000000,
                'max_daily_total' => 2000000, 'max_monthly_total' => 5000000,
                'max_annual_total' => 50000000,
                'required_documents' => json_encode([
                    'phone_verified', 'national_id', 'selfie_or_approved_ownership',
                    'verified_residence', 'full_kyc_profile',
                ]),
                'allowed_features' => json_encode(['*']),
            ],
        ];

        foreach ($tiers as $tier) {
            // يدعم seed أثناء rolling deploy قبل/بعد migration السنوي.
            if (! Schema::hasColumn('kyc_tier_limits', 'max_annual_total')) {
                unset($tier['max_annual_total']);
            }

            DB::table('kyc_tier_limits')->updateOrInsert(
                ['tier' => $tier['tier']],
                array_merge($tier, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]),
            );
        }

        $this->command->info('Seeded ' . count($tiers) . ' progressive KYC tier limits.');
    }
}
