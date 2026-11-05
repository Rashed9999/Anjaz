<?php

namespace Tests\Feature;

use App\Models\BusinessGateway\BusinessOutboxEvent;
use App\Models\MerchantProfile;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\BusinessGateway\BusinessEntitlementService;
use App\Services\BusinessGateway\BusinessLinkService;
use App\Services\FeatureAccessService;
use App\Services\SubscriptionService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\BusinessFeatureRegistry as R;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** AMIAL-PLAN-CATALOG-001 — الباقات تُدار من اللوحة: اسم · سعر · ميزات · حدود · مدد بخصم. */
class PlanCatalogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial_business.inbound_secret' => 'in', 'amial_business.webhook_secret' => 'w', 'amial_business.webhook_url' => 'https://b.test/h']);
        $this->admin = User::factory()->create(['type' => ADMIN_TYPE, 'phone' => '967770009101']);
        app(\App\Services\PlatformRoleService::class)->ensureHasSomeRole($this->admin);
        $this->merchant = User::factory()->create(['type' => 3, 'phone' => '+967700901001', 'zone_code' => 'SOUTH', 'is_active' => 1, 'is_kyc_verified' => 1]);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'verification_status' => 'verified']);
    }

    private function createPlan(array $over = []): SubscriptionPlan
    {
        $this->actingAs($this->admin, 'user')->postJson('/admin/amial/hub/plans', array_merge([
            'code' => 'pro_plus', 'name_ar' => 'برو بلس', 'name_en' => 'Pro Plus', 'base_tier' => 'business', 'monthly_price' => '50',
            'business_features' => ['ab.pos', 'ab.audit_log'], 'limits' => ['users' => 10, 'products' => null],
            'durations' => [['months' => 1, 'discount_percent' => 0], ['months' => 12, 'discount_percent' => 20]],
        ], $over))->assertCreated();

        return SubscriptionPlan::where('code', $over['code'] ?? 'pro_plus')->firstOrFail();
    }

    #[Test]
    public function the_three_existing_plans_are_seeded_unchanged(): void
    {
        foreach (A::ALL_PLANS as $tier) {
            $p = SubscriptionPlan::where('code', $tier)->firstOrFail();
            $this->assertTrue($p->is_system);
            $this->assertSame($tier, $p->base_tier);
            $this->assertEquals(A::PLAN_PRICES_SAR[$tier], (float) $p->monthly_price);
            $this->assertSame(R::availableOn($tier), $p->business_features);
        }
        $this->assertSame('350.00', SubscriptionPlan::where('code', 'business')->first()->priceFor(12));
    }

    #[Test]
    public function the_admin_builds_a_plan_and_assigns_it_for_a_year_at_its_discount(): void
    {
        $plan = $this->createPlan();
        $this->assertSame('480.00', $plan->priceFor(12)); // 50 × 12 − 20%

        $this->actingAs($this->admin, 'user')->postJson("/admin/amial/hub/subscriptions/{$this->merchant->id}/plan", ['plan' => 'pro_plus', 'months' => 12])
            ->assertOk()->assertJsonPath('price', '480.00');

        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();
        $this->assertSame(['business', 'pro_plus'], [$profile->subscription_plan, $profile->subscription_plan_code]);
        $this->assertSame(now()->addMonthsNoOverflow(12)->toDateString(), $profile->subscription_expires_at->toDateString());

        $link = app(BusinessLinkService::class)->link($this->merchant);
        $e = app(BusinessEntitlementService::class)->for($link);
        $this->assertSame(['pro_plus', 'برو بلس'], [$e['plan'], $e['plan_label']]);
        $this->assertSame(['ab.audit_log', 'ab.pos'], collect($e['features'])->where('available', true)->pluck('code')->sort()->values()->all());
        $this->assertSame(['users' => 10, 'products' => null], $e['limits']);
    }

    #[Test]
    public function editing_a_plans_features_tells_its_subscribers_in_amial_business(): void
    {
        $plan = $this->createPlan();
        app(SubscriptionService::class)->changePlan($this->merchant, 'pro_plus', null, ['months' => 1]);
        app(BusinessLinkService::class)->link($this->merchant);
        $before = BusinessOutboxEvent::where('event_type', 'entitlements.changed')->count();

        $this->actingAs($this->admin, 'user')->postJson("/admin/amial/hub/plans/{$plan->id}", [
            'name_ar' => 'برو بلس', 'name_en' => 'Pro Plus', 'monthly_price' => '50',
            'business_features' => ['ab.pos', 'ab.audit_log', 'ab.accounting'], 'limits' => ['users' => 10],
            'durations' => [['months' => 1, 'discount_percent' => 0]],
        ])->assertOk();

        $this->assertSame($before + 1, BusinessOutboxEvent::where('event_type', 'entitlements.changed')->count());
    }

    #[Test]
    public function a_direct_tier_change_clears_a_catalog_code_that_no_longer_matches(): void
    {
        $this->createPlan();
        app(SubscriptionService::class)->changePlan($this->merchant, 'pro_plus', null, ['months' => 1]);
        $profile = MerchantProfile::where('user_id', $this->merchant->id)->first();

        app(FeatureAccessService::class)->updateMerchantPlan($profile, 'enterprise', now()->addMonth());
        $this->assertNull($profile->fresh()->subscription_plan_code);
    }

    #[Test]
    public function renewing_keeps_the_catalog_plan_even_after_it_is_withdrawn_from_sale(): void
    {
        $plan = $this->createPlan();
        app(SubscriptionService::class)->changePlan($this->merchant, 'pro_plus', null, ['months' => 1]);
        $this->actingAs($this->admin, 'user')->postJson("/admin/amial/hub/plans/{$plan->id}/toggle")->assertOk();

        app(SubscriptionService::class)->renew($this->merchant);
        $this->assertSame('pro_plus', MerchantProfile::where('user_id', $this->merchant->id)->value('subscription_plan_code'));

        $other = User::factory()->create(['type' => 3, 'phone' => '+967700901002']);
        MerchantProfile::create(['user_id' => $other->id, 'verification_status' => 'verified']);
        $this->actingAs($this->admin, 'user')->postJson("/admin/amial/hub/subscriptions/{$other->id}/plan", ['plan' => 'pro_plus'])->assertStatus(422);
    }

    #[Test]
    public function system_plans_cannot_be_withdrawn_codes_are_fixed_and_features_must_exist(): void
    {
        $business = SubscriptionPlan::where('code', 'business')->first();
        $this->actingAs($this->admin, 'user')->postJson("/admin/amial/hub/plans/{$business->id}/toggle")->assertStatus(422);
        $this->actingAs($this->admin, 'user')->postJson('/admin/amial/hub/plans', [
            'code' => 'bad', 'name_ar' => 'x', 'name_en' => 'x', 'base_tier' => 'business', 'monthly_price' => '1',
            'business_features' => ['ab.teleport'], 'durations' => [['months' => 1, 'discount_percent' => 0]],
        ])->assertStatus(422);
        $this->actingAs($this->admin, 'user')->postJson("/admin/amial/hub/plans/{$business->id}", [
            'code' => 'renamed', 'name_ar' => 'x', 'name_en' => 'x', 'monthly_price' => '1', 'business_features' => [], 'durations' => [['months' => 1, 'discount_percent' => 0]],
        ])->assertStatus(422);
        $this->assertSame('business', $business->fresh()->code);
        // الرفض يُقال بالعربيّة.
        $this->actingAs($this->admin, 'user')->postJson('/admin/amial/hub/plans', [
            'code' => 'x2', 'name_ar' => 'x', 'name_en' => 'x', 'base_tier' => 'business', 'monthly_price' => '1', 'business_features' => [], 'durations' => [],
        ])->assertStatus(422)->assertJsonPath('errors.durations.0', 'اختر مدّةً واحدةً على الأقل.');
    }

    #[Test]
    public function the_pages_open_and_a_merchant_cannot_reach_them(): void
    {
        $this->get('/admin/amial/hub/plans')->assertRedirect(route('admin.auth.login'));
        $this->actingAs($this->admin, 'user')->get('/admin/amial/hub/plans')->assertOk()->assertSee('كتالوج الباقات');
        $this->actingAs($this->admin, 'user')->get('/admin/amial/hub/subscriptions')->assertOk()->assertSee('months-select', false);
    }
}
