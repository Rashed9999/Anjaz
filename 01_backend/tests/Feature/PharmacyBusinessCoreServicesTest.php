<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Access\EntitlementService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-PHARMACY-BUSINESS-CORE-001
 *
 * لا تُختبر باقة الأعمال بظهور بطاقاتها: المسارات المشتركة التي يفتحها
 * التاجر فعلاً (فريقه، مقاعد POS، والوردية) يجب أن تُرجع بيانات صالحة
 * لصيدلية، بينما لا تتسرّب إليها شاشة تجزئة لا يملك خادمها صلاحيتها.
 */
class PharmacyBusinessCoreServicesTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => 3,
            'role' => A::ROLE_MERCHANT,
            'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'business_type' => A::BIZ_PHARMACY,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);
    }

    /** @test */
    public function pharmacy_business_owner_can_load_every_common_business_service(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $this->getJson('/api/v1/amial/merchant/staff')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.count', 0);

        $this->getJson('/api/v1/amial/merchant/pos-devices')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.used', 0);

        $this->getJson('/api/v1/amial/cashier/shift')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.shift', null);
    }

    /** @test */
    public function pharmacy_customer_records_are_a_real_business_feature_not_a_coming_soon_card(): void
    {
        Passport::actingAs($this->merchant->fresh(), [], 'api');

        $this->getJson('/api/v1/amial/merchant/pharmacy/customers')
            ->assertOk()
            ->assertJsonPath('success', true);

        $state = app(EntitlementService::class)->state(
            $this->merchant->fresh(), A::F_PHARMACY_CUSTOMERS);
        $this->assertSame(EntitlementService::AVAILABLE, $state['state']);

        $free = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $free->id,
            'business_type' => A::BIZ_PHARMACY,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_FREE,
        ]);

        Passport::actingAs($free->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/pharmacy/customers')
            ->assertStatus(402)
            ->assertJsonPath('code', 'PLAN_UPGRADE_REQUIRED');

        $retail = User::factory()->create([
            'type' => 3, 'role' => A::ROLE_MERCHANT, 'zone_code' => 'SOUTH',
        ]);
        MerchantProfile::create([
            'user_id' => $retail->id,
            'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified',
            'subscription_plan' => A::PLAN_BUSINESS,
        ]);

        Passport::actingAs($retail->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/merchant/pharmacy/customers')
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOR_BUSINESS_TYPE');
    }

    /** @test */
    public function pharmacy_business_manifest_never_advertises_retail_only_surfaces(): void
    {
        $rows = app(EntitlementService::class)->manifestFor($this->merchant->fresh())['capabilities'];
        $codes = array_map(static fn (array $row): string => $row['capability']['code'], $rows);

        foreach ([
            A::F_PRODUCTS,
            A::F_BARCODE,
            A::F_INVENTORY,
            A::F_LOW_STOCK_ALERTS,
            A::F_INVENTORY_AUDIT,
            A::F_SUPPLIERS,
            A::F_PURCHASES,
            'retail.catalog',
            'retail.variants',
            'retail.price_versions',
            'retail.locations',
            'retail.transfers',
            'retail.waste',
        ] as $retailOnly) {
            $this->assertNotContains($retailOnly, $codes,
                "الصيدلية رأت {$retailOnly}، وهو يفتح سطح التجزئة لا سطح الصيدلية.");
        }

        foreach ([A::F_EMPLOYEES, A::F_MULTI_POS, A::F_SHIFT_CLOSE, A::F_PHARMACY_CUSTOMERS] as $expected) {
            $this->assertContains($expected, $codes,
                "خدمة أعمال مشتركة أو صيدلانية غابت: {$expected}");
        }
    }
}
