<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\User;
use App\Services\Reporting\P1VerticalPerformanceReportService;
use App\Services\Reporting\ReportCatalogService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/** AMIAL-REPORTING-VERTICALS-001 — لا مقارنة نقدية بين عملات مجهولة. */
class ReportingVerticalPerformanceGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function six_verticals_are_reported_and_common_money_uses_frozen_base_amount_only(): void
    {
        $retail = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        $fuel = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);

        MerchantProfile::create(['user_id' => $retail->id, 'business_type' => A::BIZ_RETAIL]);
        MerchantProfile::create(['user_id' => $fuel->id, 'business_type' => A::BIZ_FUEL]);

        MerchantSale::create([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $retail->id,
            'total_amount' => '120.0000',
            'currency' => 'USD',
            'fx_rate_to_base' => '250.00000000',
            'base_amount' => '30000.0000',
            'payment_method' => 'cash',
            'status' => 'completed',
        ]);
        MerchantSale::create([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $fuel->id,
            'total_amount' => '5000.0000',
            'currency' => 'YER',
            'fx_rate_to_base' => '1.00000000',
            'base_amount' => '5000.0000',
            'payment_method' => 'amial_pay',
            'status' => 'completed',
        ]);

        $stationId = DB::table('fuel_stations')->insertGetId([
            'merchant_user_id' => $fuel->id,
            'station_name' => 'محطة الاختبار',
            'is_active' => 1,
            'zone_code' => 'SOUTH',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pumpId = DB::table('fuel_pumps')->insertGetId([
            'station_id' => $stationId,
            'pump_number' => 1,
            'pump_type' => 'mechanical',
            'current_meter_reading' => '100.000',
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::table('fuel_products')->insertGetId([
            'station_id' => $stationId,
            'name' => 'بنزين',
            'price_per_liter' => '100.0000',
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fuel_sales')->insert([
            'sale_ulid' => (string) Str::ulid(),
            'merchant_user_id' => $fuel->id,
            'station_id' => $stationId,
            'pump_id' => $pumpId,
            'fuel_product_id' => $productId,
            'sale_type' => 'by_liters',
            'liters' => '12.5000',
            'price_per_liter' => '100.0000',
            'total_amount' => '1250.0000',
            'payment_method' => 'cash',
            'status' => 'completed',
            'zone_code' => 'SOUTH',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $report = app(P1VerticalPerformanceReportService::class)
            ->report(now()->toDateString(), now()->toDateString());
        $rows = collect($report['verticals'])->keyBy('vertical');

        $this->assertCount(count(A::ALL_BUSINESS_TYPES), $rows);
        $this->assertSame(1, $rows[A::BIZ_RETAIL]['merchants']);
        $this->assertSame('30000.0000', $rows[A::BIZ_RETAIL]['common_pos_sales']['base_amount']);
        $this->assertSame(1, $rows[A::BIZ_RETAIL]['common_pos_sales']['cash']);
        $this->assertSame(1, $rows[A::BIZ_FUEL]['merchants']);
        $this->assertSame('5000.0000', $rows[A::BIZ_FUEL]['common_pos_sales']['base_amount']);
        $this->assertSame(1, $rows[A::BIZ_FUEL]['domain']['sales_in_period']);
        $this->assertSame('12.5000', $rows[A::BIZ_FUEL]['domain']['liters_in_period']);

        $this->assertStringContainsString('base_amount', $report['monetary_comparison_policy']);
        $this->assertStringContainsString('not silently combined', $report['monetary_comparison_policy']);
    }

    /** @test */
    public function vertical_report_is_permission_guarded_and_catalog_marks_it_ready(): void
    {
        $route = Route::getRoutes()->getByName('admin.amial.reporting-center.vertical-performance');
        $this->assertNotNull($route);
        $this->assertContains('platform:platform.reports.view', $route->gatherMiddleware());

        $merchant = collect(app(ReportCatalogService::class)->catalog()['merchant']['reports'])
            ->keyBy('code');
        $this->assertSame('ready', $merchant['vertical_performance']['status']);
        $this->assertStringContainsString('لا دمج نقدي', $merchant['vertical_performance']['source']);
    }
}
