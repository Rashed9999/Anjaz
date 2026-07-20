<?php

namespace Tests\Feature;

use App\Models\LoyaltyAccount;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CashierService;
use App\Services\LoyaltyService;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** AMIAL-LOYALTY-001 — برنامج الولاء والنقاط. */
class LoyaltyTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => A::PLAN_FREE]);
    }

    private function upgrade(): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, A::PLAN_BUSINESS, $admin);
    }

    /** @test الباقة المجّانية ممنوعة → 402. */
    public function free_plan_cannot_use_loyalty(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/loyalty/program')->assertStatus(402);
    }

    /** @test بيع بعميل معروف يكسب نقاطاً مركزياً حسب المعدّل. */
    public function completed_sale_earns_points(): void
    {
        $this->upgrade();
        // فعّل البرنامج: 2 نقطة لكل 100 ر.ي
        app(LoyaltyService::class)->saveProgram($this->merchant->fresh(),
            ['is_active' => true, 'earn_points_per_100' => '2', 'redeem_value_per_point' => '1']);

        // بيع نقدي 1000 ر.ي بعميل معروف → 20 نقطة
        app(CashierService::class)->recordSale(
            merchant: $this->merchant->fresh(),
            total: '1000',
            paymentMethod: 'cash',
            items: [],
            customer: ['name' => 'سعيد', 'phone' => '777123456'],
        );

        $acct = LoyaltyAccount::where('merchant_user_id', $this->merchant->id)->first();
        $this->assertNotNull($acct);
        $this->assertSame('20.00', (string) $acct->points_balance);
    }

    /** @test استبدال النقاط يعيد خصماً صحيحاً ويخفض الرصيد. */
    public function redeem_returns_discount_and_lowers_balance(): void
    {
        $this->upgrade();
        $m = $this->merchant->fresh();
        app(LoyaltyService::class)->saveProgram($m,
            ['is_active' => true, 'earn_points_per_100' => '1', 'redeem_value_per_point' => '5']);
        app(LoyaltyService::class)->adjust($m, '777123456', 50, 'رصيد ابتدائي');

        Passport::actingAs($m, [], 'api');
        // استبدال 10 نقاط × 5 = خصم 50 ر.ي
        $this->postJson('/api/v1/amial/merchant/loyalty/redeem', ['phone' => '777123456', 'points' => 10])
            ->assertOk()
            ->assertJsonPath('meta.discount', '50')
            ->assertJsonPath('meta.points_balance', '40.00');

        // استبدال أكثر من الرصيد يُرفض
        $this->postJson('/api/v1/amial/merchant/loyalty/redeem', ['phone' => '777123456', 'points' => 9999])
            ->assertStatus(422);
    }
}
