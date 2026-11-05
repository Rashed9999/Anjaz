<?php

use App\Support\Access\AccessConstants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $changed = DB::transaction(function (): array {
            $changed = [];
            $schemes = DB::table('fee_schemes')
                ->whereIn('code', ['MERCHANT_QR', 'MERCHANT_POS'])
                ->where('applies_to', 'merchant')
                ->where('is_active', true)
                ->lockForUpdate()
                ->get();

            foreach ($schemes as $current) {
                // A zero rate with a positive minimum is still a charge.
                if ((float) $current->percent_rate === 0.0
                    && (float) $current->fixed_amount === 0.0
                    && (float) $current->min_fee === 0.0) {
                    continue;
                }

                $now = now();
                $version = DB::table('fee_schemes')
                    ->where('code', $current->code)
                    ->where('zone_code', $current->zone_code)
                    ->where('applies_to', $current->applies_to)
                    ->when($current->plan === null,
                        fn ($q) => $q->whereNull('plan'),
                        fn ($q) => $q->where('plan', $current->plan))
                    ->max('version') + 1;

                DB::table('fee_schemes')->where('id', $current->id)->update([
                    'is_active' => false, 'effective_to' => $now, 'updated_at' => $now,
                ]);

                $newId = DB::table('fee_schemes')->insertGetId([
                    'code' => $current->code,
                    'label' => $current->label,
                    'zone_code' => $current->zone_code,
                    'applies_to' => $current->applies_to,
                    'plan' => $current->plan,
                    'fee_type' => 'percent',
                    'percent_rate' => '0.0000',
                    'fixed_amount' => '0.0000',
                    'min_fee' => null,
                    'max_fee' => null,
                    'agent_commission_percent' => '0.0000',
                    'agent_commission_fixed' => '0.0000',
                    'bearer' => $current->bearer,
                    'version' => $version,
                    'is_active' => true,
                    'effective_from' => $now,
                    'effective_to' => null,
                    'notes' => 'Merchant acceptance is free; confirmed by owner 2026-11-05.',
                    'created_by' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('fee_change_logs')->insert([
                    'fee_scheme_id' => $current->id,
                    'code' => $current->code,
                    'action' => 'deactivated',
                    'old_values' => json_encode($current, JSON_THROW_ON_ERROR),
                    'new_values' => null,
                    'admin_id' => null,
                    'ip' => null,
                    'created_at' => $now,
                ]);
                DB::table('fee_change_logs')->insert([
                    'fee_scheme_id' => $newId,
                    'code' => $current->code,
                    'action' => 'created',
                    'old_values' => null,
                    'new_values' => json_encode(['version' => $version, 'percent_rate' => '0.0000', 'fixed_amount' => '0.0000'], JSON_THROW_ON_ERROR),
                    'admin_id' => null,
                    'ip' => null,
                    'created_at' => $now,
                ]);

                $changed[] = [$current->code, $current->zone_code, $current->applies_to];
            }

            return $changed;
        });

        foreach ($changed as [$code, $zone, $appliesTo]) {
            Cache::forget("fee_scheme:{$code}:{$zone}:{$appliesTo}:*");
            Cache::forget("fee_scheme:{$code}:{$zone}:{$appliesTo}");
            foreach (AccessConstants::ALL_PLANS as $plan) {
                Cache::forget("fee_scheme:{$code}:{$zone}:{$appliesTo}:{$plan}");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Reinstating a merchant fee requires a separate approved pricing decision.');
    }
};
