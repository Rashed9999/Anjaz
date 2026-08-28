<?php

namespace Tests\Feature;

use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;
use Tests\TestCase;

/**
 * AMIAL-WHOLESALE-SCOPE-001 — ما يظهر في تصاميم الجملة يجب أن يتبع
 * الاستحقاقات الحقيقية لا أن يتحول إلى قائمة ثابتة لكل التجار أو لكل الباقات.
 */
class WholesalePlanSurfaceMatrixTest extends TestCase
{
    /** @test */
    public function free_wholesale_keeps_the_vertical_core_without_paid_catalog_depth(): void
    {
        $f = $this->featuresFor(A::PLAN_FREE);

        $this->assertContains(A::F_WHOLESALE_INVOICES, $f);
        $this->assertContains(A::F_WHOLESALE_COLLECTIONS, $f);
        $this->assertContains(A::F_DEBTS, $f);
        $this->assertContains(A::F_REFUNDS, $f);

        $this->assertNotContains(A::F_PRODUCTS, $f);
        $this->assertNotContains(A::F_INVENTORY, $f);
        $this->assertNotContains(A::F_CUSTOMERS, $f);
        $this->assertNotContains(A::F_ADVANCED_REPORTS, $f);
        $this->assertNotContains(A::F_WHOLESALE_MULTI_PRICING, $f);
    }

    /** @test */
    public function starter_wholesale_opens_products_inventory_and_stock_alerts_only_at_that_depth(): void
    {
        $f = $this->featuresFor(A::PLAN_STARTER);

        $this->assertContains(A::F_PRODUCTS, $f);
        $this->assertContains(A::F_INVENTORY, $f);
        $this->assertContains(A::F_BARCODE, $f);
        $this->assertContains(A::F_INVENTORY_AUDIT, $f);
        $this->assertContains(A::F_LOW_STOCK_ALERTS, $f);

        $this->assertNotContains(A::F_CUSTOMERS, $f);
        $this->assertNotContains(A::F_ADVANCED_REPORTS, $f);
        $this->assertNotContains(A::F_EXCEL_EXPORT, $f);
        $this->assertNotContains(A::F_WHOLESALE_MULTI_PRICING, $f);
    }

    /** @test */
    public function business_wholesale_opens_customers_suppliers_purchases_and_advanced_reporting(): void
    {
        $f = $this->featuresFor(A::PLAN_BUSINESS);

        foreach ([
            A::F_CUSTOMERS,
            A::F_SUPPLIERS,
            A::F_PURCHASES,
            A::F_ADVANCED_REPORTS,
            A::F_EXCEL_EXPORT,
        ] as $feature) {
            $this->assertContains($feature, $f);
        }

        $this->assertNotContains(A::F_WHOLESALE_MULTI_PRICING, $f);
    }

    /** @test */
    public function merchant_pro_wholesale_opens_multi_pricing_without_changing_the_vertical(): void
    {
        $f = $this->featuresFor(A::PLAN_MERCHANT_PRO);

        $this->assertContains(A::F_WHOLESALE_MULTI_PRICING, $f);
        $this->assertContains(A::F_BRANCHES, $f);
        $this->assertContains(A::F_MULTI_CURRENCY, $f);
        $this->assertContains(A::F_AUDIT_LOG, $f);
    }

    /** @test */
    public function enterprise_inherits_wholesale_depth_and_adds_enterprise_capabilities(): void
    {
        $f = $this->featuresFor(A::PLAN_ENTERPRISE);

        $this->assertContains(A::F_WHOLESALE_INVOICES, $f);
        $this->assertContains(A::F_PRODUCTS, $f);
        $this->assertContains(A::F_CUSTOMERS, $f);
        $this->assertContains(A::F_WHOLESALE_MULTI_PRICING, $f);
        $this->assertContains(A::F_API_ACCESS, $f);
        $this->assertContains(A::F_CORPORATE_ACCOUNTS, $f);
    }

    private function featuresFor(string $plan): array
    {
        return array_values(array_unique([
            ...AccessPresets::roleBase(A::ROLE_MERCHANT),
            ...AccessPresets::businessTypeFeatures(A::BIZ_WHOLESALE),
            ...AccessPresets::planFeatures($plan),
        ]));
    }
}
