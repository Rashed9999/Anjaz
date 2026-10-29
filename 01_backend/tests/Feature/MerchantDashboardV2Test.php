<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\MerchantProduct;
use App\Models\MerchantSale;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductStock;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MerchantDashboardV2Test extends TestCase
{
    use RefreshDatabase;

    private function owner(string $vertical = A::BIZ_RETAIL): User
    {
        $owner = User::factory()->create([
            'role' => A::ROLE_MERCHANT,
            'type' => MERCHANT_TYPE,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $owner->id,
            'business_type' => $vertical,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        $merchant = new Merchant();
        $merchant->user_id = $owner->id;
        $merchant->merchant_number = 'DASH-V2-001';
        $merchant->store_name = 'متجر لوحة V2';
        $merchant->save();

        return $owner;
    }

    /** @test */
    public function merchant_dashboard_v2_uses_the_vertical_sales_source_and_keeps_zero_days(): void
    {
        $owner = $this->owner();

        $yesterday = MerchantSale::create([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $owner->id,
            'total_amount' => '1500.0000',
            'payment_method' => 'cash',
            'status' => 'completed',
            'items' => [],
            'zone_code' => 'SOUTH',
        ]);
        $yesterday->forceFill([
            'created_at' => now()->subDay()->setTime(10, 0),
            'updated_at' => now()->subDay()->setTime(10, 0),
        ])->saveQuietly();

        MerchantSale::create([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $owner->id,
            'total_amount' => '3000.0000',
            'payment_method' => 'amial_pay',
            'status' => 'completed',
            'items' => [],
            'zone_code' => 'SOUTH',
        ]);

        // بيع معلّق لا يدخل لوحة الحقيقة.
        MerchantSale::create([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $owner->id,
            'total_amount' => '9000.0000',
            'payment_method' => 'amial_pay',
            'status' => 'pending_payment',
            'items' => [],
            'zone_code' => 'SOUTH',
        ]);

        $this->actingAs($owner, 'merchant_web');

        $response = $this->getJson('/merchant/data/dashboard-v2?days=7')
            ->assertOk()
            ->assertJsonPath('meta.dashboard.contract_version', 'merchant-dashboard/v2')
            ->assertJsonPath('meta.dashboard.source', 'merchant_sales')
            ->assertJsonPath('meta.dashboard.period_count', 2)
            ->assertJsonPath('meta.dashboard.period_total', '4500.0000')
            ->assertJsonPath('meta.dashboard.today_total', '3000.0000')
            ->assertJsonPath('meta.dashboard.yesterday_total', '1500.0000')
            ->assertJsonPath('meta.dashboard.today_change_percent', '100.00')
            ->assertJsonPath('meta.financial.sales.gross', '3000.0000')
            ->assertJsonCount(7, 'meta.dashboard.series')
            ->assertJsonCount(2, 'meta.dashboard.recent_sales');

        $series = collect($response->json('meta.dashboard.series'));
        $this->assertSame('3000.0000', $series->firstWhere('date', now()->toDateString())['total']);
        $this->assertSame(
            '1500.0000',
            $series->firstWhere('date', now()->subDay()->toDateString())['total']
        );
    }

    /** @test */
    public function retail_dashboard_reads_sellable_stock_per_location_not_legacy_global_quantity(): void
    {
        $owner = $this->owner();

        $branchStore = MerchantLocation::create([
            'merchant_user_id' => $owner->id,
            'kind' => 'store',
            'name' => 'فرع التحرير',
            'code' => 'TAHRIR',
            'is_active' => true,
            'is_default' => true,
            'created_by' => $owner->id,
        ]);
        $warehouse = MerchantLocation::create([
            'merchant_user_id' => $owner->id,
            'kind' => 'warehouse',
            'name' => 'المستودع المركزي',
            'code' => 'WH-01',
            'is_active' => true,
            'is_default' => false,
            'created_by' => $owner->id,
        ]);

        $lowProduct = MerchantProduct::create([
            'merchant_user_id' => $owner->id,
            'name' => 'مياه اختبار المواقع',
            'price' => '500',
            // المرآة القديمة تبدو سليمة عمداً؛ لا يجوز أن تتحكم باللوحة.
            'quantity' => '500',
            'track_stock' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $lowProduct->id,
            'location_id' => $branchStore->id,
            'on_hand' => '4',
            'reserved' => '1',
            'reorder_level' => '5',
            'max_level' => '20',
        ]);
        ProductStock::create([
            'product_id' => $lowProduct->id,
            'location_id' => $warehouse->id,
            'on_hand' => '100',
            'reserved' => '0',
            'reorder_level' => '5',
            'max_level' => '150',
        ]);

        $reservedProduct = MerchantProduct::create([
            'merchant_user_id' => $owner->id,
            'name' => 'صنف محجوز بالكامل',
            'price' => '750',
            'quantity' => '999',
            'track_stock' => true,
            'is_active' => true,
        ]);
        ProductStock::create([
            'product_id' => $reservedProduct->id,
            'location_id' => $branchStore->id,
            'on_hand' => '3',
            'reserved' => '3',
            'reorder_level' => '0',
            'max_level' => '0',
        ]);

        $this->actingAs($owner, 'merchant_web');
        $response = $this->getJson('/merchant/data/dashboard-v2?days=7')
            ->assertOk()
            ->assertJsonPath(
                'meta.sector.meta.source',
                'product_stocks + stock_movements + merchant_sale_items'
            );

        $cards = collect($response->json('meta.sector.cards'))->keyBy('code');
        $this->assertSame(1, $cards['low_stock']['value']);
        $this->assertSame(1, $cards['out_of_stock']['value']);
        $this->assertSame(0, $cards['negative_stock']['value']);

        $attention = collect($response->json('meta.sector.lists.stock_attention'));
        $low = $attention->firstWhere('product', 'مياه اختبار المواقع');
        $this->assertSame('فرع التحرير', $low['location']);
        $this->assertSame('3.000', $low['available']);
        $this->assertSame('low', $low['state']);

        $out = $attention->firstWhere('product', 'صنف محجوز بالكامل');
        $this->assertSame('3.000', $out['on_hand']);
        $this->assertSame('3.000', $out['reserved']);
        $this->assertSame('0.000', $out['available']);
        $this->assertSame('out', $out['state']);
    }

    /** @test */
    public function merchant_portal_renders_v2_shell_and_starts_on_the_home_dashboard(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner, 'merchant_web')
            ->get('/merchant')
            ->assertOk()
            ->assertSee('AMIAL MERCHANT PORTAL V2', false)
            ->assertSee('التشغيل والمبيعات')
            ->assertSee('الفريق ونقاط البيع')
            ->assertSee('المالية والتقارير')
            ->assertSee("api('dashboardV2')", false)
            ->assertSee('اتجاه المبيعات')
            ->assertSee('طرق الدفع');

        $navigation = app(\App\Services\Merchant\MerchantPortalNavigationService::class)
            ->forOwner(
                A::BIZ_RETAIL,
                app(\App\Services\Access\EntitlementService::class)->manifestFor($owner),
            );

        $this->assertSame('overview', $navigation[0]['tab']);
        $this->assertSame('الرئيسية', $navigation[0]['label']);
    }
}
