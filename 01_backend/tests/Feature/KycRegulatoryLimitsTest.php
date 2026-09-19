<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\KycTierService;
use Database\Seeders\KycTierLimitsSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KycRegulatoryLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(KycTierLimitsSeeder::class);
        config(['amial.operational_governorates' => ['YE-AD']]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function approved_customer_tier_ceiling_matrix_is_the_runtime_source_of_truth(): void
    {
        $service = app(KycTierService::class);

        $tier1 = $service->getLimits(1);
        $this->assertSame(0, bccomp('100000', (string) $tier1['max_balance'], 4));
        $this->assertSame(0, bccomp('100000', (string) $tier1['max_single_transaction'], 4));
        $this->assertSame(0, bccomp('100000', (string) $tier1['max_daily_total'], 4));
        $this->assertSame(0, bccomp('100000', (string) $tier1['max_monthly_total'], 4));

        $tier2 = $service->getLimits(2);
        $this->assertSame(0, bccomp('250000', (string) $tier2['max_balance'], 4));
        $this->assertSame(0, bccomp('250000', (string) $tier2['max_single_transaction'], 4));
        $this->assertSame(0, bccomp('250000', (string) $tier2['max_daily_total'], 4));
        $this->assertSame(0, bccomp('250000', (string) $tier2['max_monthly_total'], 4));

        $tier3 = $service->getLimits(3);
        $this->assertSame(0, bccomp('8000000', (string) $tier3['max_balance'], 4));
        $this->assertSame(0, bccomp('1000000', (string) $tier3['max_single_transaction'], 4));
        $this->assertSame(0, bccomp('2000000', (string) $tier3['max_daily_total'], 4));
        $this->assertSame(0, bccomp('5000000', (string) $tier3['max_monthly_total'], 4));
        $this->assertSame(0, bccomp('50000000', (string) $tier3['max_annual_total'], 4));
    }

    /** @test */
    public function a_legacy_customer_override_can_never_raise_the_effective_tier_ceiling(): void
    {
        $user = User::factory()->create([
            'type' => 2,
            'kyc_tier' => 1,
            'is_phone_verified' => 1,
            'is_kyc_verified' => 0,
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now(),
            'zone_code' => 'SOUTH',
            'limit_override' => [
                'max_balance' => '900000',
                'max_single_transaction' => '900000',
                'max_daily_total' => '900000',
                'max_monthly_total' => '900000',
            ],
        ]);

        $limits = app(KycTierService::class)->getLimitsForUser($user->fresh());

        $this->assertSame(0, bccomp('100000', (string) $limits['max_balance'], 4));
        $this->assertSame(0, bccomp('100000', (string) $limits['max_single_transaction'], 4));
        $this->assertSame(0, bccomp('100000', (string) $limits['max_daily_total'], 4));
        $this->assertSame(0, bccomp('100000', (string) $limits['max_monthly_total'], 4));
    }

    /** @test */
    public function fully_verified_customer_is_stopped_at_the_annual_turnover_ceiling(): void
    {
        Carbon::setTestNow('2026-09-19 08:00:00');

        $user = User::factory()->create([
            'type' => 2,
            'kyc_tier' => 3,
            'is_phone_verified' => 1,
            'is_kyc_verified' => 1,
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now(),
            'zone_code' => 'SOUTH',
        ]);

        DB::table('customer_turnover_usage')->insert([
            'user_id' => $user->id,
            'source_key' => 'test:annual-history',
            'transaction_row_id' => null,
            'transaction_id' => null,
            'transaction_type' => 'send_money',
            'direction' => 'out',
            'principal_amount' => '49500000',
            'status' => 'posted',
            'occurred_at' => '2026-01-15 12:00:00',
            'finalized_at' => '2026-01-15 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('السنوي');

        app(KycTierService::class)->assertTransactionAllowed($user->fresh(), '600000', 'send_money');
    }

    /** @test */
    public function tier_policy_service_rejects_values_above_the_hard_ceiling(): void
    {
        $actor = User::factory()->create();

        $this->expectException(DomainException::class);
        app(KycTierService::class)->updateTierPolicy(
            tier: 3,
            payload: ['max_single_transaction' => '1000001'],
            actor: $actor,
            reason: 'محاولة اختبار تجاوز السقف التنظيمي',
        );
    }
}
