<?php

namespace Tests\Unit;

use App\Services\Access\EntitlementService;
use App\Services\Merchant\MerchantPortalNavigationService;
use App\Support\Access\AccessConstants as A;
use PHPUnit\Framework\TestCase;

class MerchantPortalNavigationServiceTest extends TestCase
{
    public function test_fuel_navigation_never_borrows_the_retail_debt_or_product_doors(): void
    {
        $service = new MerchantPortalNavigationService();
        $items = $service->forOwner(A::BIZ_FUEL, [
            'capabilities' => [
                ['state' => EntitlementService::AVAILABLE, 'capability' => ['code' => A::F_FUEL_PUMPS]],
                ['state' => EntitlementService::LOCKED_BY_PLAN, 'capability' => ['code' => A::F_FUEL_PRODUCTS]],
            ],
        ]);

        $tabs = array_column($items, 'tab');
        $this->assertSame('sector', $tabs[0]);
        $this->assertContains('sales', $tabs);
        $this->assertNotContains('products', $tabs);
        $this->assertNotContains('debts', $tabs);
    }

    public function test_workspace_keeps_an_honest_unavailable_state_instead_of_a_fake_door(): void
    {
        $service = new MerchantPortalNavigationService();
        $items = $service->forOwner(A::BIZ_RESTAURANT, [
            'capabilities' => [
                ['state' => EntitlementService::COMING_SOON, 'capability' => ['code' => A::F_RESTAURANT_TABLES]],
                ['state' => EntitlementService::COMING_SOON, 'capability' => ['code' => A::F_RESTAURANT_ORDERS]],
            ],
        ]);

        $workspace = $items[0];
        $this->assertSame('sector', $workspace['tab']);
        $this->assertSame(EntitlementService::COMING_SOON, $workspace['state']);
        $this->assertSame(EntitlementService::COMING_SOON, $items[1]['state']);
    }
}
