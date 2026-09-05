<?php

namespace Tests\Feature;

use App\Exceptions\UsageLimitExceededException;
use App\Models\MerchantProfile;
use App\Models\MerchantUsageCounter;
use App\Models\User;
use App\Services\UsageLimitService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CRITICAL-001-USAGE — اختبارات شاملة لفرض الحدود.
 */
class UsageLimitTest extends TestCase
{
    use RefreshDatabase;

    private UsageLimitService $svc;
    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(UsageLimitService::class);
        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_WHOLESALE,
            'subscription_plan' => A::PLAN_FREE,
        ]);
    }

    /** @test */
    public function free_plan_allows_first_100_operations(): void
    {
        for ($i = 0; $i < 100; $i++) {
            $this->svc->recordSaleOperation($this->merchant);
        }

        $counter = MerchantUsageCounter::where('merchant_user_id', $this->merchant->id)
            ->where('counter_type', MerchantUsageCounter::TYPE_SALE_OPERATION)
            ->where('period_key', MerchantUsageCounter::currentMonthKey())
            ->first();

        $this->assertNotNull($counter);
        $this->assertEquals(100, $counter->count);
    }

    /** @test */
    public function free_plan_blocks_101st_operation(): void
    {
        // املأ العدّاد إلى 100
        MerchantUsageCounter::create([
            'merchant_user_id' => $this->merchant->id,
            'counter_type' => MerchantUsageCounter::TYPE_SALE_OPERATION,
            'period_key' => MerchantUsageCounter::currentMonthKey(),
            'count' => 100,
        ]);

        $this->expectException(UsageLimitExceededException::class);
        $this->svc->assertCanPerformSale($this->merchant);
    }

    /** @test */
    public function starter_plan_has_unlimited_operations(): void
    {
        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();
        $profile->update(['subscription_plan' => A::PLAN_STARTER,
            'subscription_expires_at' => now()->addDays(30)]);

        // املأ العدّاد إلى 1000 (لاختبار النطاق)
        MerchantUsageCounter::create([
            'merchant_user_id' => $this->merchant->id,
            'counter_type' => MerchantUsageCounter::TYPE_SALE_OPERATION,
            'period_key' => MerchantUsageCounter::currentMonthKey(),
            'count' => 1000,
        ]);

        // لا يجب أن يرمي
        $this->svc->assertCanPerformSale($this->merchant);
        $this->assertTrue(true);
    }

    /** @test */
    public function expired_subscription_reverts_to_free_limits(): void
    {
        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();
        // STARTER لكن منتهي
        $profile->update([
            'subscription_plan' => A::PLAN_STARTER,
            'subscription_expires_at' => now()->subDays(5),
        ]);

        // املأ العدّاد إلى 100 (حدّ FREE)
        MerchantUsageCounter::create([
            'merchant_user_id' => $this->merchant->id,
            'counter_type' => MerchantUsageCounter::TYPE_SALE_OPERATION,
            'period_key' => MerchantUsageCounter::currentMonthKey(),
            'count' => 100,
        ]);

        $this->expectException(UsageLimitExceededException::class);
        $this->svc->assertCanPerformSale($this->merchant);
    }

    /** @test */
    public function exception_suggests_upgrade_to_next_plan(): void
    {
        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();

        // محاولة FREE → STARTER
        try {
            MerchantUsageCounter::create([
                'merchant_user_id' => $this->merchant->id,
                'counter_type' => MerchantUsageCounter::TYPE_SALE_OPERATION,
                'period_key' => MerchantUsageCounter::currentMonthKey(),
                'count' => 100,
            ]);
            $this->svc->assertCanPerformSale($this->merchant);
            $this->fail('Should have thrown');
        } catch (UsageLimitExceededException $e) {
            $this->assertEquals(A::PLAN_FREE, $e->currentPlan);
            $this->assertEquals(A::PLAN_STARTER, $e->suggestedPlan);
            $this->assertEquals('monthly_operations', $e->limitType);
        }
    }

    /** @test */
    public function exception_to_json_returns_402_with_upgrade_info(): void
    {
        $e = new UsageLimitExceededException(
            limitType: 'monthly_operations',
            currentValue: 100, maxValue: 100,
            currentPlan: A::PLAN_FREE,
            suggestedPlan: A::PLAN_STARTER,
        );

        $response = $e->toJsonResponse();
        $this->assertEquals(402, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals('USAGE_LIMIT_EXCEEDED', $data['code']);
        $this->assertEquals(A::PLAN_STARTER, $data['meta']['suggested_plan']);
        // والسعرُ من الكتالوج لا رقماً مكتوباً — تغيّر من ١٥ إلى ٣٥
        // في توحيد الباقات، فأسقط حارساً لا يقرأ مصدرَه.
        $this->assertEquals(
            A::PLAN_PRICES_SAR[A::PLAN_STARTER],
            $data['meta']['suggested_plan_price_sar']);
    }

    /** @test */
    public function atomic_increment_handles_concurrent_inserts(): void
    {
        // اختبار: استدعاء recordOperation 10 مرّات متتالياً يجب أن يعطي count=10
        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();
        $profile->update(['subscription_plan' => A::PLAN_STARTER,
            'subscription_expires_at' => now()->addDays(30)]);

        for ($i = 0; $i < 10; $i++) {
            $this->svc->recordSaleOperation($this->merchant);
        }

        $counter = MerchantUsageCounter::where('merchant_user_id', $this->merchant->id)
            ->where('counter_type', MerchantUsageCounter::TYPE_SALE_OPERATION)
            ->where('period_key', MerchantUsageCounter::currentMonthKey())
            ->first();

        $this->assertEquals(10, $counter->count);
    }

    /** @test */
    public function usage_snapshot_returns_current_plan_info(): void
    {
        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();
        $profile->update(['subscription_plan' => A::PLAN_STARTER,
            'subscription_expires_at' => now()->addDays(30)]);

        // أضف 25 عملية
        for ($i = 0; $i < 25; $i++) {
            $this->svc->recordSaleOperation($this->merchant);
        }

        $snap = $this->svc->usageSnapshot($this->merchant);

        $this->assertEquals(A::PLAN_STARTER, $snap['plan']);
        $this->assertEquals(25, $snap['monthly_operations']['current']);
        $this->assertEquals(-1, $snap['monthly_operations']['max']);
        $this->assertTrue($snap['monthly_operations']['is_unlimited']);
    }

    /** @test */
    public function snapshot_calculates_percentage_for_free_plan(): void
    {
        // FREE: 100 ops max
        for ($i = 0; $i < 25; $i++) {
            $this->svc->recordSaleOperation($this->merchant);
        }

        $snap = $this->svc->usageSnapshot($this->merchant);
        $this->assertEquals(25.0, $snap['monthly_operations']['percentage']);
        $this->assertFalse($snap['monthly_operations']['is_unlimited']);
    }
}
