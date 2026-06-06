<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-KYC-TIERS-001 (v1.9)
 *
 * بذور حدود مستويات التحقق.
 */
class KycTierLimitsSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'tier' => 0, 'name_ar' => 'غير موثق',
                'max_balance' => 0, 'max_single_transaction' => 0,
                'max_daily_total' => 0, 'max_monthly_total' => 0,
                'required_documents' => json_encode([]),
                'allowed_features' => json_encode([]),
            ],
            [
                'tier' => 1, 'name_ar' => 'أساسي',
                'max_balance' => 50000, 'max_single_transaction' => 5000,
                'max_daily_total' => 10000, 'max_monthly_total' => 50000,
                'required_documents' => json_encode(['phone_verified']),
                'allowed_features' => json_encode(['send_money', 'receive_money', 'bill_pay']),
            ],
            [
                'tier' => 2, 'name_ar' => 'قياسي',
                'max_balance' => 500000, 'max_single_transaction' => 50000,
                'max_daily_total' => 100000, 'max_monthly_total' => 500000,
                'required_documents' => json_encode(['phone_verified', 'national_id']),
                'allowed_features' => json_encode([
                    'send_money', 'receive_money', 'bill_pay',
                    'safe_payment', 'donations', 'family_fund',
                ]),
            ],
            [
                'tier' => 3, 'name_ar' => 'كامل',
                'max_balance' => 5000000, 'max_single_transaction' => 500000,
                'max_daily_total' => 1000000, 'max_monthly_total' => 5000000,
                'required_documents' => json_encode(['phone_verified', 'national_id', 'address_proof', 'selfie']),
                'allowed_features' => json_encode(['*']),
            ],
        ];

        foreach ($tiers as $tier) {
            DB::table('kyc_tier_limits')->updateOrInsert(
                ['tier' => $tier['tier']],
                array_merge($tier, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]),
            );
        }

        $this->command->info('Seeded ' . count($tiers) . ' KYC tier limits.');
    }
}
