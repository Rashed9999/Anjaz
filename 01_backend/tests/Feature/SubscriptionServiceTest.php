<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\SubscriptionChange;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $svc;
    private User $merchant;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(SubscriptionService::class);

        $this->admin = User::factory()->create(['type' => 1]);
        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'verification_status' => 'verified',
            'business_type' => A::BIZ_WHOLESALE,
            'subscription_plan' => A::PLAN_FREE,
        ]);
    }

    /** @test */
    public function changing_plan_creates_audit_log(): void
    {
        $this->svc->changePlan($this->merchant, A::PLAN_STARTER, $this->admin, [
            'price_paid_sar' => 15.0,
            'payment_method' => 'cash',
        ]);

        $log = SubscriptionChange::where('merchant_user_id', $this->merchant->id)->first();
        $this->assertNotNull($log);
        $this->assertEquals(A::PLAN_FREE, $log->old_plan);
        $this->assertEquals(A::PLAN_STARTER, $log->new_plan);
        $this->assertEquals(SubscriptionChange::ACTION_UPGRADE, $log->action);
        $this->assertEquals($this->admin->id, $log->actor_user_id);
        $this->assertEquals(15.0, $log->price_paid_sar);
    }

    /** @test */
    public function upgrade_is_detected_correctly(): void
    {
        $this->svc->changePlan($this->merchant, A::PLAN_BUSINESS);
        $log = SubscriptionChange::latest()->first();
        $this->assertEquals(SubscriptionChange::ACTION_UPGRADE, $log->action);
    }

    /** @test */
    public function downgrade_is_detected_correctly(): void
    {
        $this->svc->changePlan($this->merchant, A::PLAN_MERCHANT_PRO);
        $this->svc->changePlan($this->merchant, A::PLAN_STARTER);
        $log = SubscriptionChange::latest()->first();
        $this->assertEquals(SubscriptionChange::ACTION_DOWNGRADE, $log->action);
    }

    /** @test */
    public function renew_keeps_same_plan_extends_30_days(): void
    {
        $this->svc->changePlan($this->merchant, A::PLAN_STARTER);
        $profile1 = MerchantProfile::where('user_id', $this->merchant->id)->first();
        $oldExp = $profile1->subscription_expires_at;

        sleep(1); // ensure timestamp differs
        $this->svc->renew($this->merchant, $this->admin);

        $profile2 = MerchantProfile::where('user_id', $this->merchant->id)->first();
        $this->assertEquals(A::PLAN_STARTER, $profile2->subscription_plan);
        $this->assertTrue($profile2->subscription_expires_at->gt($oldExp));

        $log = SubscriptionChange::latest()->first();
        $this->assertEquals(SubscriptionChange::ACTION_RENEW, $log->action);
    }

    /** @test */
    public function extend_adds_days_to_existing_expiry(): void
    {
        $this->svc->changePlan($this->merchant, A::PLAN_STARTER, null, [
            'expires_at' => now()->addDays(10),
        ]);

        $this->svc->extend($this->merchant, 15, $this->admin);
        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();
        // 10 + 15 = 25 days from now (approximately)
        $this->assertEqualsWithDelta(25, now()->diffInDays($profile->subscription_expires_at), 1);
    }

    /** @test */
    public function cannot_extend_free_plan(): void
    {
        $this->expectException(\LogicException::class);
        $this->svc->extend($this->merchant, 30);
    }

    /** @test */
    public function process_expired_reverts_to_free(): void
    {
        // STARTER منتهي
        MerchantProfile::where('user_id', $this->merchant->id)->update([
            'subscription_plan' => A::PLAN_STARTER,
            'subscription_expires_at' => now()->subDay(),
        ]);

        $count = $this->svc->processExpired();
        $this->assertEquals(1, $count);

        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();
        $this->assertEquals(A::PLAN_FREE, $profile->subscription_plan);
        $this->assertNull($profile->subscription_expires_at);

        $log = SubscriptionChange::latest()->first();
        $this->assertEquals(SubscriptionChange::ACTION_EXPIRE_AUTO, $log->action);
        $this->assertEquals(SubscriptionChange::ACTOR_SYSTEM, $log->actor_role);
    }

    /** @test */
    public function summary_calculates_mrr_correctly(): void
    {
        // 3 تجار: 1 STARTER (15), 1 BUSINESS (35), 1 PRO (65)
        $u1 = User::factory()->create(['type' => 3]);
        $u2 = User::factory()->create(['type' => 3]);
        $u3 = User::factory()->create(['type' => 3]);
        MerchantProfile::create(['user_id' => $u1->id, 'verification_status' => 'verified',
            'business_type' => A::BIZ_WHOLESALE,
            'subscription_plan' => A::PLAN_STARTER, 'subscription_expires_at' => now()->addDays(20)]);
        MerchantProfile::create(['user_id' => $u2->id, 'verification_status' => 'verified',
            'business_type' => A::BIZ_WHOLESALE,
            'subscription_plan' => A::PLAN_BUSINESS, 'subscription_expires_at' => now()->addDays(20)]);
        MerchantProfile::create(['user_id' => $u3->id, 'verification_status' => 'verified',
            'business_type' => A::BIZ_WHOLESALE,
            'subscription_plan' => A::PLAN_MERCHANT_PRO, 'subscription_expires_at' => now()->addDays(20)]);

        $summary = $this->svc->summary();
        $this->assertEquals(15 + 35 + 65, $summary['mrr_sar']);
        $this->assertEquals((15 + 35 + 65) * 12, $summary['arr_sar']);
    }

    /** @test */
    public function expiring_soon_returns_correct_merchants(): void
    {
        MerchantProfile::where('user_id', $this->merchant->id)->update([
            'subscription_plan' => A::PLAN_STARTER,
            'subscription_expires_at' => now()->addDays(3),
        ]);

        $expiring = $this->svc->expiringSoon(7);
        $this->assertCount(1, $expiring);

        $expiringFar = $this->svc->expiringSoon(2);
        $this->assertCount(0, $expiringFar); // 3 days > 2 days window
    }

    /** @test */
    public function invalid_plan_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->changePlan($this->merchant, 'invalid_plan');
    }

    /** @test */
    public function concurrent_change_uses_lock(): void
    {
        // مجرّد فحص أن lockForUpdate موجود ولا يخفق
        $this->svc->changePlan($this->merchant, A::PLAN_STARTER);
        $this->svc->changePlan($this->merchant, A::PLAN_BUSINESS);
        $this->assertEquals(2, SubscriptionChange::count());
    }
}
