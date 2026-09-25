<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Vertical\VerticalBootstrapService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * لا باقةٍ مكتوبة ثانيةً، ولا قدرةَ قطاعٍ آخر لمجرّد تبديل قائمة المقارنة.
 * بوابة الويب للمالك؛ نطاق الموظف والعميل يبقى خارجها.
 */
class MerchantWebPlansSectorTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $sector, int $number = 1): User
    {
        $merchant = User::factory()->create([
            'role' => A::ROLE_MERCHANT,
            'type' => MERCHANT_TYPE,
            'is_active' => 1,
            'phone' => '967775' . str_pad((string) $number, 6, '0', STR_PAD_LEFT),
            'email' => "owner{$number}@example.test",
        ]);
        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => $sector,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);
        $store = new Merchant();
        $store->user_id = $merchant->id;
        $store->store_name = "منشأة {$number}";
        $store->save();
        app(VerticalBootstrapService::class)->ensureFor($merchant);
        return $merchant->refresh();
    }

    public function test_owner_sees_current_plan_live_capabilities_and_their_own_sector(): void
    {
        $owner = $this->owner(A::BIZ_PHARMACY);
        $res = $this->actingAs($owner, 'merchant_web')
            ->getJson('/merchant/data/plans')
            ->assertOk()
            ->assertJsonPath('meta.actual_sector', A::BIZ_PHARMACY)
            ->assertJsonPath('meta.preview_only', false)
            ->assertJsonPath('meta.current_plan.code', A::PLAN_ENTERPRISE)
            ->assertJsonPath('meta.comparison.business_type', A::BIZ_PHARMACY)
            ->assertJsonPath('meta.manifest.business_type', A::BIZ_PHARMACY)
            ->assertJsonPath('meta.upgrade.automated', false);

        $this->assertCount(3, $res->json('meta.comparison.plans'));
        $this->assertCount(3, $res->json('meta.live_plans'));
        $this->assertNotEmpty($res->json('meta.manifest.capabilities'));
    }

    public function test_other_sector_plan_preview_is_read_only_not_a_grant(): void
    {
        $owner = $this->owner(A::BIZ_PHARMACY);
        $this->actingAs($owner, 'merchant_web')
            ->getJson('/merchant/data/plans?preview_sector=fuel')
            ->assertOk()
            ->assertJsonPath('meta.actual_sector', A::BIZ_PHARMACY)
            ->assertJsonPath('meta.preview_sector', A::BIZ_FUEL)
            ->assertJsonPath('meta.preview_only', true)
            ->assertJsonPath('meta.comparison.business_type', A::BIZ_FUEL)
            ->assertJsonPath('meta.manifest.business_type', A::BIZ_PHARMACY);
        $this->assertDatabaseHas('merchant_profiles', [
            'user_id' => $owner->id,
            'business_type' => A::BIZ_PHARMACY,
        ]);
    }

    public function test_unknown_sector_is_not_accepted_for_preview(): void
    {
        $owner = $this->owner(A::BIZ_RETAIL);
        $this->actingAs($owner, 'merchant_web')
            ->getJson('/merchant/data/plans?preview_sector=unregistered')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'UNKNOWN_SECTOR');
    }

    public function test_customer_cannot_read_plans_or_sector_workspace(): void
    {
        $this->owner(A::BIZ_FUEL);
        $customer = User::factory()->create(['role' => A::ROLE_USER, 'type' => 2]);
        $this->actingAs($customer, 'merchant_web')
            ->getJson('/merchant/data/plans')->assertForbidden();
        $this->getJson('/merchant/data/sector')->assertForbidden();
    }

    public function test_all_built_in_sectors_get_their_own_portal_header(): void
    {
        foreach (A::ALL_BUSINESS_TYPES as $i => $type) {
            $owner = $this->owner($type, $i + 10);
            $this->actingAs($owner, 'merchant_web')
                ->get('/merchant')->assertOk()
                ->assertSee(A::BUSINESS_TYPE_LABELS[$type]);
        }
    }

    public function test_sector_and_plan_routes_have_merchant_owner_gate(): void
    {
        foreach (['plans', 'sector', 'sector.products',
                  'sector.products.create', 'sector.operations'] as $key) {
            $route = Route::getRoutes()->getByName('merchant.web.data.' . $key);
            $this->assertNotNull($route, "Route missing: {$key}");
            $this->assertContains('merchant.web', $route->gatherMiddleware());
        }

        $productCreate = Route::getRoutes()->getByName('merchant.web.data.sector.products.create');
        $this->assertContains('amial.usage:add_product', $productCreate->gatherMiddleware());
    }
}
