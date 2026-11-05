<?php

namespace Tests\Feature;

use App\Exceptions\UsageLimitExceededException;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use App\Support\Access\CapabilityRegistry;
use Tests\TestCase;

class ThreePlanCatalogTest extends TestCase
{
    public function test_the_only_sellable_merchant_plans_are_free_business_and_enterprise(): void
    {
        $this->assertSame([A::PLAN_FREE, A::PLAN_BUSINESS, A::PLAN_ENTERPRISE], A::ALL_PLANS);
        $this->assertSame(0, A::PLAN_PRICES_SAR[A::PLAN_FREE]);
        $this->assertSame(35, A::PLAN_PRICES_SAR[A::PLAN_BUSINESS]);
        $this->assertSame(99, A::PLAN_PRICES_SAR[A::PLAN_ENTERPRISE]);
        $this->assertSame([A::PLAN_FREE, A::PLAN_BUSINESS, A::PLAN_ENTERPRISE],
            array_keys(CapabilityRegistry::PLAN_ORDER));
    }

    public function test_legacy_plan_values_canonicalize_without_becoming_sellable_plans(): void
    {
        $this->assertSame(A::PLAN_BUSINESS, A::canonicalPlan('starter'));
        $this->assertSame(A::PLAN_ENTERPRISE, A::canonicalPlan('merchant_pro'));
        $this->assertNotContains('starter', A::ALL_PLANS);
        $this->assertNotContains('merchant_pro', A::ALL_PLANS);
    }

    public function test_business_is_the_next_upgrade_and_enterprise_is_the_last_one(): void
    {
        $this->assertSame(A::PLAN_BUSINESS, UsageLimitExceededException::suggestUpgrade(A::PLAN_FREE));
        $this->assertSame(A::PLAN_ENTERPRISE, UsageLimitExceededException::suggestUpgrade(A::PLAN_BUSINESS));
        $this->assertNull(UsageLimitExceededException::suggestUpgrade(A::PLAN_ENTERPRISE));
    }

    public function test_business_has_full_operational_depth_and_enterprise_adds_scale_and_governance(): void
    {
        $business = AccessPresets::planFeatures(A::PLAN_BUSINESS);
        $enterprise = AccessPresets::planFeatures(A::PLAN_ENTERPRISE);

        $this->assertContains(A::F_WHOLESALE_MULTI_PRICING,
            AccessPresets::verticalPlanFeatures(A::BIZ_WHOLESALE, A::PLAN_BUSINESS));
        $this->assertContains(A::F_PHARMACY_PRESCRIPTIONS,
            AccessPresets::verticalPlanFeatures(A::BIZ_PHARMACY, A::PLAN_BUSINESS));
        $this->assertContains(A::F_FUEL_COMPANIES,
            AccessPresets::verticalPlanFeatures(A::BIZ_FUEL, A::PLAN_BUSINESS));
        $this->assertNotContains(A::F_FUEL_COMPANIES,
            AccessPresets::verticalPlanFeatures(A::BIZ_RETAIL, A::PLAN_BUSINESS));
        $this->assertContains(A::F_BRANCHES, $enterprise);
        $this->assertContains(A::F_API_ACCESS, $enterprise);
        $this->assertNotContains(A::F_API_ACCESS, $business);
    }
}
