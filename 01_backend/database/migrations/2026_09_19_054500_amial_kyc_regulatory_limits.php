<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-KYC-REGULATORY-LIMITS-001
 *
 * يضيف السقف السنوي ويثبت سياسة حدود العميل الفردي:
 * Tier 1 = 100,000 ر.ي، Tier 2 = 250,000 ر.ي،
 * Tier 3 = السقوف التنظيمية المعتمدة لأميال.
 *
 * الأرقام في DB هي مصدر التشغيل؛ DEFAULT_LIMITS في KycTierService احتياط
 * آمن فقط أثناء الإقلاع/الترحيل.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kyc_tier_limits')
            && ! Schema::hasColumn('kyc_tier_limits', 'max_annual_total')) {
            Schema::table('kyc_tier_limits', function (Blueprint $table) {
                $table->decimal('max_annual_total', 20, 4)
                    ->default(0)
                    ->after('max_monthly_total');
            });
        }

        if (! Schema::hasTable('kyc_tier_limits')) {
            return;
        }

        $limits = [
            0 => ['max_balance' => '0', 'max_single_transaction' => '0', 'max_daily_total' => '0', 'max_monthly_total' => '0', 'max_annual_total' => '0'],
            1 => ['max_balance' => '100000', 'max_single_transaction' => '100000', 'max_daily_total' => '100000', 'max_monthly_total' => '100000', 'max_annual_total' => '0'],
            2 => ['max_balance' => '250000', 'max_single_transaction' => '250000', 'max_daily_total' => '250000', 'max_monthly_total' => '250000', 'max_annual_total' => '0'],
            3 => ['max_balance' => '8000000', 'max_single_transaction' => '1000000', 'max_daily_total' => '2000000', 'max_monthly_total' => '5000000', 'max_annual_total' => '50000000'],
        ];

        foreach ($limits as $tier => $values) {
            DB::table('kyc_tier_limits')->where('tier', $tier)->update(
                $values + ['updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('kyc_tier_limits')) {
            return;
        }

        // السياسة السابقة مباشرةً قبل هذا الترحيل.
        DB::table('kyc_tier_limits')->where('tier', 3)->update([
            'max_balance' => '2000000',
            'max_single_transaction' => '400000',
            'max_daily_total' => '700000',
            'max_monthly_total' => '2000000',
            'updated_at' => now(),
        ]);

        if (Schema::hasColumn('kyc_tier_limits', 'max_annual_total')) {
            Schema::table('kyc_tier_limits', function (Blueprint $table) {
                $table->dropColumn('max_annual_total');
            });
        }
    }
};
