<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\Promotion;
use App\Models\User;
use App\Services\CashierService;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** AMIAL-PROMOTIONS-001 — العروض والخصومات. */
class PromotionTest extends TestCase
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

    private function upgrade(string $plan = A::PLAN_STARTER): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, $plan, $admin);
    }

    /** @test المجّاني ممنوع → 402. */
    public function free_plan_cannot_manage_promotions(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/promotions')->assertStatus(402);
    }

    /** @test إنشاء عرض تلقائي بنسبة ثم تقييمه على فاتورة. */
    public function percent_promo_evaluates_correctly(): void
    {
        $this->upgrade();
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $this->postJson('/api/v1/amial/merchant/promotions', [
            'name' => 'خصم 10%', 'type' => 'percent', 'value' => 10, 'min_order_amount' => 500,
        ])->assertStatus(201);

        // فاتورة 1000 → خصم 100
        $this->postJson('/api/v1/amial/merchant/promotions/apply', ['subtotal' => 1000])
            ->assertOk()->assertJsonPath('meta.discount', '100');

        // فاتورة 400 تحت الحدّ الأدنى → لا خصم
        $this->postJson('/api/v1/amial/merchant/promotions/apply', ['subtotal' => 400])
            ->assertOk()->assertJsonPath('meta.discount', '0');
    }

    /** @test كوبون بحدّ استخدام: البيع يستهلكه ويُخزَّن الخصم على الفاتورة. */
    public function coupon_usage_is_consumed_and_stored(): void
    {
        $this->upgrade();
        $m = $this->merchant->fresh();
        $promo = Promotion::create([
            'merchant_user_id' => $m->id, 'name' => 'كوبون', 'type' => 'fixed', 'value' => '200',
            'code' => 'EID', 'usage_limit' => 1, 'used_count' => 0, 'is_active' => true, 'zone_code' => 'SOUTH',
        ]);

        // بيع صافٍ 800 (1000 - 200) مع تمرير الخصم والعرض
        app(CashierService::class)->recordSale(
            merchant: $m, total: '800', paymentMethod: 'cash', items: [],
            discountAmount: '200', promotionId: $promo->id,
        );

        $sale = MerchantSale::where('merchant_user_id', $m->id)->first();
        $this->assertSame('200.00', (string) $sale->discount_amount);
        $this->assertSame($promo->id, $sale->promotion_id);
        $this->assertSame(1, $promo->fresh()->used_count);

        // تجاوز الحدّ: التقييم لا يعيد خصماً بعد نفاد الاستخدام
        Passport::actingAs($m, [], 'api');
        $this->postJson('/api/v1/amial/merchant/promotions/apply', ['subtotal' => 1000, 'code' => 'EID'])
            ->assertOk()->assertJsonPath('meta.discount', '0');
    }
}
