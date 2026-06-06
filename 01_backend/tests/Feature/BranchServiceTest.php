<?php

namespace Tests\Feature;

use App\Exceptions\UsageLimitExceededException;
use App\Models\Branch;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\BranchService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchServiceTest extends TestCase
{
    use RefreshDatabase;

    private BranchService $svc;
    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(BranchService::class);
        $this->merchant = User::factory()->create(['type' => 3]);
    }

    private function setPlan(string $plan, ?\Carbon\Carbon $expiresAt = null): void
    {
        MerchantProfile::updateOrCreate(
            ['user_id' => $this->merchant->id],
            [
                'verification_status' => 'verified',
                'business_type' => A::BIZ_WHOLESALE,
                'subscription_plan' => $plan,
                'subscription_expires_at' => $expiresAt ?? ($plan === A::PLAN_FREE ? null : now()->addDays(30)),
            ],
        );
    }

    /** @test */
    public function free_plan_cannot_create_branch(): void
    {
        $this->setPlan(A::PLAN_FREE);
        $this->expectException(UsageLimitExceededException::class);
        $this->svc->create($this->merchant, ['name' => 'فرع 1']);
    }

    /** @test */
    public function pro_plan_can_create_up_to_3_branches(): void
    {
        $this->setPlan(A::PLAN_MERCHANT_PRO);
        $this->svc->create($this->merchant, ['name' => 'فرع 1']);
        $this->svc->create($this->merchant, ['name' => 'فرع 2']);
        $this->svc->create($this->merchant, ['name' => 'فرع 3']);
        $this->assertEquals(3, Branch::count());

        // الرابع يجب أن يفشل
        $this->expectException(UsageLimitExceededException::class);
        $this->svc->create($this->merchant, ['name' => 'فرع 4']);
    }

    /** @test */
    public function enterprise_plan_is_unlimited(): void
    {
        $this->setPlan(A::PLAN_ENTERPRISE);
        for ($i = 1; $i <= 10; $i++) {
            $this->svc->create($this->merchant, ['name' => "فرع $i"]);
        }
        $this->assertEquals(10, Branch::count());
    }

    /** @test */
    public function first_branch_is_marked_default(): void
    {
        $this->setPlan(A::PLAN_MERCHANT_PRO);
        $b1 = $this->svc->create($this->merchant, ['name' => 'فرع 1']);
        $b2 = $this->svc->create($this->merchant, ['name' => 'فرع 2']);

        $this->assertTrue($b1->fresh()->is_default);
        $this->assertFalse($b2->fresh()->is_default);
    }

    /** @test */
    public function cannot_delete_default_branch(): void
    {
        $this->setPlan(A::PLAN_MERCHANT_PRO);
        $b = $this->svc->create($this->merchant, ['name' => 'فرع 1']);

        $this->expectException(\LogicException::class);
        $this->svc->delete($b);
    }

    /** @test */
    public function set_as_default_moves_flag_correctly(): void
    {
        $this->setPlan(A::PLAN_MERCHANT_PRO);
        $b1 = $this->svc->create($this->merchant, ['name' => 'فرع 1']);
        $b2 = $this->svc->create($this->merchant, ['name' => 'فرع 2']);

        $this->svc->setAsDefault($b2);

        $this->assertFalse($b1->fresh()->is_default);
        $this->assertTrue($b2->fresh()->is_default);
    }

    /** @test */
    public function cannot_create_duplicate_branch_name(): void
    {
        $this->setPlan(A::PLAN_MERCHANT_PRO);
        $this->svc->create($this->merchant, ['name' => 'فرع الغيضة']);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->create($this->merchant, ['name' => 'فرع الغيضة']);
    }

    /** @test */
    public function expired_subscription_blocks_new_branches(): void
    {
        $this->setPlan(A::PLAN_MERCHANT_PRO, now()->subDay()); // منتهي
        $this->expectException(UsageLimitExceededException::class);
        $this->svc->create($this->merchant, ['name' => 'فرع 1']);
    }

    /** @test */
    public function ensure_default_creates_one_only_once(): void
    {
        $this->setPlan(A::PLAN_MERCHANT_PRO);
        $b1 = $this->svc->ensureDefaultBranch($this->merchant);
        $b2 = $this->svc->ensureDefaultBranch($this->merchant);

        $this->assertNotNull($b1);
        $this->assertEquals($b1->id, $b2->id); // idempotent
        $this->assertEquals(1, Branch::count());
    }
}
