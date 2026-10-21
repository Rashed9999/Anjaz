<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ZonePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OperationalCoveragePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_governorate_policy_controls_cash_without_changing_verification(): void
    {
        DB::table('operational_governorate_policies')->where('is_active', true)->update(['is_active' => false]);
        DB::table('operational_governorate_policies')->insert([
            'version' => 99,
            'governorate_codes' => json_encode(['YE-AD']),
            'reason' => 'Test operational coverage policy',
            'is_active' => true,
            'effective_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create([
            'zone_code' => 'NORTH',
            'verified_residence_governorate' => 'YE-SN',
            'residence_verified_at' => now(),
        ]);
        $policy = app(ZonePolicyService::class);

        $this->assertTrue($policy->authorize($user, 'send_money')['allowed']);
        $this->assertSame('TX_ZONE_BLOCKED', $policy->authorize($user, 'cash_out')['decision_code']);

        DB::table('operational_governorate_policies')->where('version', 99)->update([
            'governorate_codes' => json_encode(['YE-AD', 'YE-SN']),
        ]);
        $this->assertTrue($policy->authorize($user->fresh(), 'cash_out')['allowed']);
    }

    public function test_unverified_location_blocks_money_but_not_reading(): void
    {
        $user = User::factory()->create(['zone_code' => 'UNKNOWN']);
        $policy = app(ZonePolicyService::class);

        $this->assertSame('ACCOUNT_LOCATION_UNVERIFIED', $policy->authorize($user, 'send_money')['decision_code']);
        $this->assertTrue($policy->authorize($user, 'view_balance')['allowed']);
    }
}
