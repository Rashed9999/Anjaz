<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-CUSTOMER-VERIFICATION-LABELS-001
 *
 * القيم 0..3 تبقى مفاتيح داخلية لأن الحدود والسياسات تعتمد عليها.
 * هذا الترحيل يغير الاسم الظاهر فقط في المصدر التشغيلي kyc_tier_limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('kyc_tier_limits')) {
            return;
        }

        $labels = [
            0 => 'عميل غير موثق',
            1 => 'عميل موثق جزئيا',
            2 => 'عميل موثق بهوية',
            3 => 'عميل موثق',
        ];

        foreach ($labels as $tier => $label) {
            DB::table('kyc_tier_limits')
                ->where('tier', $tier)
                ->update([
                    'name_ar' => $label,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('kyc_tier_limits')) {
            return;
        }

        $labels = [
            0 => 'غير موثق',
            1 => 'أساسي',
            2 => 'هوية موثقة',
            3 => 'كامل',
        ];

        foreach ($labels as $tier => $label) {
            DB::table('kyc_tier_limits')
                ->where('tier', $tier)
                ->update([
                    'name_ar' => $label,
                    'updated_at' => now(),
                ]);
        }
    }
};
