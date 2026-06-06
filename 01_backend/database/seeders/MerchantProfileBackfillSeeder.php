<?php

namespace Database\Seeders;

use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * AMIAL-MERCHANT-RISK-001 (v2.10)
 *
 * ينشئ MerchantProfile للتجار الحاليين (type=3) بلا profile.
 * يبدأ بـ micro (الأكثر تقييداً) للأمان — الإدارة ترفع التصنيف بعد المراجعة.
 */
class MerchantProfileBackfillSeeder extends Seeder
{
    public function run(): void
    {
        $count = 0;
        $microLimits = MerchantProfile::defaultLimitsForTier('micro');

        User::where('type', 3)->chunkById(100, function ($merchants) use (&$count, $microLimits) {
            foreach ($merchants as $merchant) {
                $created = MerchantProfile::firstOrCreate(
                    ['user_id' => $merchant->id],
                    array_merge($microLimits, [
                        'tier' => 'micro',
                        'risk_category' => 'standard',
                        'verification_status' => 'unverified',
                        'can_transfer_out' => false,
                        'requires_settlement_only' => true,
                        'zone_code' => $merchant->zone_code ?? 'SOUTH',
                    ])
                );
                if ($created->wasRecentlyCreated) $count++;
            }
        });

        $this->command->info("✓ Created {$count} merchant profiles (tier=micro, status=unverified)");
        $this->command->info('  راجعهم في لوحة الإدارة وارفع التصنيف حسب التوثيق.');
    }
}
