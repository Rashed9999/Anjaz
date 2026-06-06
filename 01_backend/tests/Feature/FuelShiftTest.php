<?php

namespace Tests\Feature;

use App\Models\FuelPump;
use App\Models\FuelShift;
use App\Models\FuelStation;
use App\Models\FuelVarianceRecord;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\FuelShiftService;
use App\Services\FuelStationService;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-FUEL-002 — اختبارات النوبات والعجز/الفائض.
 */
class FuelShiftTest extends TestCase
{
    use RefreshDatabase;

    private FuelStationService $stationSvc;
    private FuelShiftService $shiftSvc;
    private User $merchant;
    private FuelStation $station;
    private FuelPump $pump;
    private $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stationSvc = app(FuelStationService::class);
        $this->shiftSvc = app(FuelShiftService::class);

        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'verification_status' => 'verified']);
        $this->station = $this->stationSvc->getOrCreateStation($this->merchant);
        $this->pump = $this->stationSvc->addPump($this->station, [
            'pump_number' => 1,
            'pump_type' => 'mechanical',
            'initial_meter_reading' => 1000,
        ]);
        $this->product = $this->stationSvc->addProduct($this->station, [
            'name' => 'بنزين',
            'price_per_liter' => '500',
        ]);
    }

    /** @test */
    public function open_shift_creates_pump_summary_with_meter(): void
    {
        $shift = $this->shiftSvc->openShift($this->station, $this->merchant, '10000', 'بداية يوم');

        $this->assertSame('open', $shift->status);
        $this->assertSame(MoneyService::normalize('10000'), (string)$shift->opening_cash);

        $this->assertCount(1, $shift->pumpSummaries);
        $this->assertSame('1000.000', (string)$shift->pumpSummaries[0]->opening_meter);
    }

    /** @test */
    public function cannot_open_second_shift_when_one_is_open(): void
    {
        $this->shiftSvc->openShift($this->station, $this->merchant);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('نوبة مفتوحة');
        $this->shiftSvc->openShift($this->station, $this->merchant);
    }

    /** @test */
    public function close_shift_matched_cash_no_variance(): void
    {
        $shift = $this->shiftSvc->openShift($this->station, $this->merchant, '5000');

        // بيع نقدي 10000
        $this->stationSvc->recordSale($this->merchant, null, [
            'pump_id' => $this->pump->id,
            'fuel_product_id' => $this->product->id,
            'sale_type' => 'by_amount',
            'amount' => 10000,
            'payment_method' => 'cash',
        ]);

        // المتوقّع: opening 5000 + sales 10000 = 15000
        // إغلاق: نقد فعلي 15000 = لا variance
        $closed = $this->shiftSvc->closeShift(
            $shift, $this->merchant, '15000',
            [$this->pump->id => '1020'], // 20 لتر تباع تماماً
        );

        $this->assertSame('closed', $closed->status);
        $this->assertSame(MoneyService::normalize('15000'), (string)$closed->expected_cash);
        $this->assertSame(MoneyService::normalize('0'), (string)$closed->variance);
        $this->assertFalse($closed->requires_admin_review);

        // لا variance records
        $this->assertCount(0, $closed->varianceRecords);
    }

    /** @test */
    public function close_shift_with_cash_shortage_creates_variance_record(): void
    {
        $shift = $this->shiftSvc->openShift($this->station, $this->merchant, '0');

        $this->stationSvc->recordSale($this->merchant, null, [
            'pump_id' => $this->pump->id,
            'fuel_product_id' => $this->product->id,
            'sale_type' => 'by_amount',
            'amount' => 20000,
            'payment_method' => 'cash',
        ]);

        // المتوقّع 20000، الفعلي 19000 → عجز 1000
        $closed = $this->shiftSvc->closeShift(
            $shift, $this->merchant, '19000',
            [$this->pump->id => '1040'], // 40 لتر
            'لا أعرف السبب',
        );

        $this->assertSame('closed_with_variance', $closed->status);
        $this->assertSame('-1000.0000', (string)$closed->variance);
        $this->assertTrue($closed->requires_admin_review); // > 500 و 5% من المبيعات

        // سجل variance
        $varRecord = FuelVarianceRecord::where('shift_id', $closed->id)
            ->where('variance_type', 'cash_variance')->first();
        $this->assertNotNull($varRecord);
        $this->assertSame('shortage', $varRecord->direction);
        $this->assertSame(MoneyService::normalize('1000'), (string)$varRecord->amount);
    }

    /** @test */
    public function close_shift_with_cash_surplus_creates_variance_record(): void
    {
        $shift = $this->shiftSvc->openShift($this->station, $this->merchant, '0');

        $this->stationSvc->recordSale($this->merchant, null, [
            'pump_id' => $this->pump->id,
            'fuel_product_id' => $this->product->id,
            'sale_type' => 'by_amount',
            'amount' => 5000,
            'payment_method' => 'cash',
        ]);

        // المتوقّع 5000، الفعلي 5500 → فائض 500
        // الحد 500 لكن 500 = 10% من المبيعات → يحتاج مراجعة
        $closed = $this->shiftSvc->closeShift(
            $shift, $this->merchant, '5500',
            [$this->pump->id => '1010'],
            'وجدت 500 إضافية لا أعرف من أين',
        );

        $varRecord = FuelVarianceRecord::where('shift_id', $closed->id)->first();
        $this->assertNotNull($varRecord);
        $this->assertSame('surplus', $varRecord->direction);
        $this->assertSame(MoneyService::normalize('500'), (string)$varRecord->amount);
    }

    /** @test */
    public function small_cash_variance_does_not_require_review(): void
    {
        $shift = $this->shiftSvc->openShift($this->station, $this->merchant, '0');

        $this->stationSvc->recordSale($this->merchant, null, [
            'pump_id' => $this->pump->id,
            'fuel_product_id' => $this->product->id,
            'sale_type' => 'by_amount',
            'amount' => 100000,
            'payment_method' => 'cash',
        ]);

        // عجز 100 (< 500 الحد المطلق)
        $closed = $this->shiftSvc->closeShift(
            $shift, $this->merchant, '99900',
            [$this->pump->id => '1200'],
        );

        $this->assertFalse($closed->requires_admin_review);
    }

    /** @test */
    public function liters_variance_detects_unrecorded_consumption(): void
    {
        $shift = $this->shiftSvc->openShift($this->station, $this->merchant, '0');

        // بيع مُسجَّل: 10 لتر
        $this->stationSvc->recordSale($this->merchant, null, [
            'pump_id' => $this->pump->id,
            'fuel_product_id' => $this->product->id,
            'sale_type' => 'by_liters',
            'liters' => 10,
            'payment_method' => 'cash',
        ]);

        // الإغلاق: العدّاد ارتفع 20 لتر (من 1000 إلى 1020) لكن المسجَّل 10
        // → liters_variance = 10 - 20 = -10 (عجز كبير في التسجيل!)
        $closed = $this->shiftSvc->closeShift(
            $shift, $this->merchant, '5000',
            [$this->pump->id => '1020'],
            'فحص',
        );

        $litersVar = FuelVarianceRecord::where('shift_id', $closed->id)
            ->where('variance_type', 'liters_variance')->first();
        $this->assertNotNull($litersVar);
        $this->assertSame('shortage', $litersVar->direction);
        $this->assertSame(MoneyService::normalize('10'), (string)$litersVar->amount);
    }

    /** @test */
    public function close_meter_less_than_open_is_rejected(): void
    {
        $shift = $this->shiftSvc->openShift($this->station, $this->merchant, '0');

        $this->expectException(\InvalidArgumentException::class);
        $this->shiftSvc->closeShift(
            $shift, $this->merchant, '0',
            [$this->pump->id => '500'], // أقل من 1000 الافتتاحية → خطأ
        );
    }

    /** @test */
    public function variance_can_be_resolved_by_admin(): void
    {
        $shift = $this->shiftSvc->openShift($this->station, $this->merchant, '0');
        $this->stationSvc->recordSale($this->merchant, null, [
            'pump_id' => $this->pump->id,
            'fuel_product_id' => $this->product->id,
            'sale_type' => 'by_amount',
            'amount' => 5000,
            'payment_method' => 'cash',
        ]);
        $closed = $this->shiftSvc->closeShift($shift, $this->merchant, '4000', [$this->pump->id => '1010']);
        $var = $closed->varianceRecords->first();

        $admin = User::factory()->create(['type' => 1]);
        $resolved = $this->shiftSvc->resolveVariance($var, $admin->id, 'covered_by_employee', 'الموظف غطّى المبلغ');

        $this->assertSame('covered_by_employee', $resolved->resolution_status);
        $this->assertSame($admin->id, $resolved->resolved_by_admin_id);
    }

    /** @test */
    public function shift_includes_all_payment_methods_totals(): void
    {
        $shift = $this->shiftSvc->openShift($this->station, $this->merchant, '0');

        // 3 بيوع بطرق مختلفة
        $this->stationSvc->recordSale($this->merchant, null, [
            'pump_id' => $this->pump->id, 'fuel_product_id' => $this->product->id,
            'sale_type' => 'by_amount', 'amount' => 5000, 'payment_method' => 'cash',
        ]);
        $this->stationSvc->recordSale($this->merchant, null, [
            'pump_id' => $this->pump->id, 'fuel_product_id' => $this->product->id,
            'sale_type' => 'by_amount', 'amount' => 3000,
            'payment_method' => 'amial_pay', 'paid_transaction_id' => 'TX1',
        ]);
        $company = $this->stationSvc->addCompanyAccount($this->merchant, [
            'company_name' => 'شركة', 'credit_limit' => '100000',
        ]);
        $this->stationSvc->recordSale($this->merchant, null, [
            'pump_id' => $this->pump->id, 'fuel_product_id' => $this->product->id,
            'sale_type' => 'by_amount', 'amount' => 2000,
            'payment_method' => 'company_card', 'company_account_id' => $company->id,
        ]);

        $closed = $this->shiftSvc->closeShift($shift, $this->merchant, '5000', [$this->pump->id => '1020']);

        $this->assertSame(MoneyService::normalize('5000'), (string)$closed->total_cash_sales);
        $this->assertSame(MoneyService::normalize('3000'), (string)$closed->total_amial_pay_sales);
        $this->assertSame(MoneyService::normalize('2000'), (string)$closed->total_company_sales);
        $this->assertSame(3, $closed->total_sales_count);
    }
}
