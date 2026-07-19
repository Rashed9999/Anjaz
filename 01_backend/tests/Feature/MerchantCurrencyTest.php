<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** AMIAL-MULTI-CURRENCY-001 — عملات التاجر (التاجر برو فأعلى). */
class MerchantCurrencyTest extends TestCase
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

    private function upgrade(string $plan = 'merchant_pro'): void
    {
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        app(SubscriptionService::class)->changePlan($this->merchant, $plan, $admin);
    }

    /** @test المجاني ممنوع → 402. */
    public function free_cannot_manage_currencies(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/currencies')->assertStatus(402);
    }

    /** @test التاجر برو: إضافة + قائمة + تحديث السعر + حذف. */
    public function pro_can_crud_currencies(): void
    {
        $this->upgrade();
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $id = $this->postJson('/api/v1/amial/merchant/currencies', [
            'code' => 'usd', 'name' => 'دولار', 'symbol' => '\$', 'rate_to_base' => '530',
        ])->assertStatus(201)->assertJsonPath('meta.currency.code', 'USD')->json('meta.currency.id');

        $this->getJson('/api/v1/amial/merchant/currencies')
            ->assertOk()->assertJsonPath('meta.count', 1)
            ->assertJsonPath('meta.currencies.0.rate_to_base', '530.000000');

        // تكرار مرفوض
        $this->postJson('/api/v1/amial/merchant/currencies', [
            'code' => 'USD', 'name' => 'x', 'rate_to_base' => '1',
        ])->assertStatus(422);

        // تحديث السعر
        $this->postJson("/api/v1/amial/merchant/currencies/{$id}", ['rate_to_base' => '545'])
            ->assertOk()->assertJsonPath('meta.currency.rate_to_base', '545.000000');

        // حذف
        $this->deleteJson("/api/v1/amial/merchant/currencies/{$id}")->assertOk();
        $this->getJson('/api/v1/amial/merchant/currencies')->assertJsonPath('meta.count', 0);
    }
}
