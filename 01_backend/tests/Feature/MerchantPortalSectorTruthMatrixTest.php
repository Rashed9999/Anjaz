<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Merchant\MerchantProductDirectoryService;
use App\Services\Merchant\MerchantSalesDirectoryService;
use App\Services\Merchant\MerchantSectorDashboardService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantPortalSectorTruthMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @dataProvider verticalTruthMatrix
     */
    public function test_portal_uses_the_correct_source_contract_for_every_vertical(
        string $vertical,
        string $dashboardKind,
        string $salesSource,
        string $productSource,
    ): void {
        $merchant = User::factory()->create([
            'role' => A::ROLE_MERCHANT,
            'type' => MERCHANT_TYPE,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => $vertical,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        $dashboard = app(MerchantSectorDashboardService::class)->build($merchant, 14);
        $sales = app(MerchantSalesDirectoryService::class)->search($merchant, []);
        $products = app(MerchantProductDirectoryService::class)->search($merchant, [
            'status' => 'all',
        ]);

        $this->assertSame($vertical, $dashboard['vertical']);
        $this->assertSame($dashboardKind, $dashboard['kind']);
        $this->assertSame($salesSource, $sales['source']);
        $this->assertSame($productSource, $products['source']);

        // العقد القطاعي يجب أن يبقى متسقاً حتى عند عدم وجود بيانات بعد.
        $this->assertSame($vertical, $sales['vertical']);
        $this->assertSame($vertical, $products['vertical']);
        $this->assertIsArray($dashboard['cards']);
        $this->assertIsArray($dashboard['lists']);
    }

    public static function verticalTruthMatrix(): array
    {
        return [
            'بيع سريع' => [
                A::BIZ_QUICK_SALE, 'retail', 'merchant_sales', 'merchant_products',
            ],
            'تجزئة' => [
                A::BIZ_RETAIL, 'retail', 'merchant_sales', 'merchant_products',
            ],
            'مطعم' => [
                A::BIZ_RESTAURANT, 'restaurant', 'merchant_sales', 'merchant_products',
            ],
            'صيدلية' => [
                A::BIZ_PHARMACY, 'pharmacy', 'pharmacy_sales', 'pharmacy_products',
            ],
            'وقود' => [
                A::BIZ_FUEL, 'fuel', 'fuel_sales', 'fuel_products',
            ],
            'جملة' => [
                A::BIZ_WHOLESALE, 'wholesale', 'wholesale_invoices', 'wholesale_products',
            ],
        ];
    }
}
