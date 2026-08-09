<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\FeeScheme;
use App\Models\FuelCompanyAccount;
use App\Models\FuelProduct;
use App\Models\FuelPump;
use App\Models\FuelSale;
use App\Models\FuelStation;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\FuelStationService;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-FUEL-001 — اختبارات قطاع محطات الوقود.
 */
class FuelStationTest extends TestCase
{
    use RefreshDatabase;

    private FuelStationService $svc;
    private User $merchant;
    private FuelStation $station;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(FuelStationService::class);
        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'verification_status' => 'verified']);
        $this->station = $this->svc->getOrCreateStation($this->merchant, [
            'station_name' => 'محطة العولقي',
        ]);

        // **البيعُ يشترط ورديّةً مفتوحة** — AMIAL-FUEL-VERTICAL-001 المرحلة ٠.
        //
        // وكانت هذه الاختباراتُ تبيع بلا وردية، فتوثّق سلوكاً كان يترك
        // نقداً في الدرج بلا صاحب. فتُفتح الورديّةُ هنا كما يفعل الصرّاف
        // أوّلَ يومه، لا يُخفَّف الشرط.
        app(\App\Services\FuelShiftService::class)
            ->openShift($this->station, $this->merchant, '0');
    }

    /** @test */
    public function station_is_unique_per_merchant(): void
    {
        $s2 = $this->svc->getOrCreateStation($this->merchant);
        $this->assertSame($this->station->id, $s2->id);

        $count = FuelStation::where('merchant_user_id', $this->merchant->id)->count();
        $this->assertSame(1, $count);
    }

    /** @test */
    public function add_pump_with_unique_number(): void
    {
        $p1 = $this->svc->addPump($this->station, ['pump_number' => 1, 'pump_type' => 'mechanical']);
        $this->assertSame(1, $p1->pump_number);
        $this->assertSame('mechanical', $p1->pump_type);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->addPump($this->station, ['pump_number' => 1]);
    }

    /** @test */
    public function add_product_with_valid_price(): void
    {
        $product = $this->svc->addProduct($this->station, [
            'name' => 'بنزين 91',
            'price_per_liter' => '400',
        ]);
        $this->assertSame(MoneyService::normalize('400'), (string)$product->price_per_liter);
    }

    /** @test */
    public function update_product_price(): void
    {
        $p = $this->svc->addProduct($this->station, ['name' => 'ديزل', 'price_per_liter' => '350']);
        $updated = $this->svc->updateProductPrice($p, '375');
        $this->assertSame(MoneyService::normalize('375'), (string)$updated->price_per_liter);
    }

    /** @test AMIAL-FUEL-PRICE-HISTORY-001 — كل تغيير سعر يُسجَّل بالفرق ومن غيّره. */
    public function price_change_is_recorded_in_history(): void
    {
        $p = $this->svc->addProduct($this->station, ['name' => 'بنزين 95', 'price_per_liter' => '1200']);

        $this->svc->updateProductPrice($p, '1150', $this->merchant, 'خفض رسمي');

        $this->assertDatabaseHas('fuel_price_history', [
            'fuel_product_id' => $p->id,
            'station_id' => $this->station->id,
            'changed_by_user_id' => $this->merchant->id,
            'note' => 'خفض رسمي',
        ]);

        $history = $this->svc->priceHistory($this->station);
        $this->assertCount(1, $history);
        $this->assertSame('down', $history[0]['direction']);
        $this->assertSame(MoneyService::normalize('-50'), MoneyService::normalize($history[0]['delta']));
        $this->assertSame('بنزين 95', $history[0]['product']);
    }

    /** @test AMIAL-FUEL-PRICE-HISTORY-001 — لا سجل عند «تغيير» لنفس السعر. */
    public function unchanged_price_is_not_recorded(): void
    {
        $p = $this->svc->addProduct($this->station, ['name' => 'سوبر', 'price_per_liter' => '1450']);
        $this->svc->updateProductPrice($p->fresh(), '1450', $this->merchant);
        $this->assertCount(0, $this->svc->priceHistory($this->station));
    }

    /** @test AMIAL-FUEL-PRICE-HISTORY-001 — نقطة API لسجل الأسعار تعمل للتاجر. */
    public function price_history_endpoint_returns_records(): void
    {
        $p = $this->svc->addProduct($this->station, ['name' => 'ديزل', 'price_per_liter' => '900']);
        $this->svc->updateProductPrice($p, '950', $this->merchant);

        \Laravel\Passport\Passport::actingAs($this->merchant, [], 'api');
        $this->getJson('/api/v1/amial/merchant/fuel/price-history')
            ->assertOk()
            ->assertJsonPath('meta.history.0.product', 'ديزل')
            ->assertJsonPath('meta.history.0.direction', 'up');
    }

    /**
     * @test AMIAL-FUEL-PAY-001 — بيع أميال باي يشحن العميل مباشرةً في خطوة واحدة:
     * المال يتحرّك فعلاً (خصم العميل + إضافة التاجر) ويُربط مرجع المعاملة بالبيع.
     */
    public function amial_pay_sale_charges_customer_and_moves_money(): void
    {
        // محافظ التاجر والعميل + رسوم دفع نقطة بيع
        \App\Models\EMoney::create([
            'user_id' => $this->merchant->id, 'current_balance' => '0.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
        $admin = User::factory()->create(['type' => 0, 'zone_code' => 'SOUTH']);
        \App\Models\EMoney::create([
            'user_id' => $admin->id, 'current_balance' => '0.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
        $customer = User::factory()->create(['type' => 2, 'phone' => '+967771700001', 'zone_code' => 'SOUTH']);
        \App\Models\EMoney::create([
            'user_id' => $customer->id, 'current_balance' => '50000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
        \App\Models\FeeScheme::create([
            'code' => 'MERCHANT_POS', 'zone_code' => 'SOUTH', 'applies_to' => 'merchant',
            'fee_type' => 'percent', 'percent_rate' => '1.0000', 'fixed_amount' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'receiver', 'version' => 1, 'is_active' => true, 'effective_from' => now(),
        ]);

        $pump = $this->svc->addPump($this->station, ['pump_number' => 1]);
        $product = $this->svc->addProduct($this->station, ['name' => 'بنزين', 'price_per_liter' => '500']);

        $sale = $this->svc->recordSale($this->merchant, null, [
            'pump_id' => $pump->id,
            'fuel_product_id' => $product->id,
            'sale_type' => 'by_amount',
            'amount' => '10000',
            'payment_method' => 'amial_pay',
            'customer_phone' => '967771700001',
        ]);

        // المرجع ارتبط بالبيع
        $this->assertNotNull($sale->paid_transaction_id);
        // العميل خُصم منه 10000 (الرسم على التاجر — bearer=receiver)
        $this->assertSame('40000.0000',
            (string) \App\Models\EMoney::where('user_id', $customer->id)->value('current_balance'));
        // التاجر استلم الصافي (10000 - 1% = 9900)
        $this->assertSame('9900.0000',
            (string) \App\Models\EMoney::where('user_id', $this->merchant->id)->value('current_balance'));
    }

    /** @test AMIAL-FUEL-PAY-001 — بيع أميال باي بلا هاتف ولا مرجع يُرفض. */
    public function amial_pay_sale_requires_phone_or_reference(): void
    {
        $pump = $this->svc->addPump($this->station, ['pump_number' => 1]);
        $product = $this->svc->addProduct($this->station, ['name' => 'ديزل', 'price_per_liter' => '400']);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->recordSale($this->merchant, null, [
            'pump_id' => $pump->id, 'fuel_product_id' => $product->id,
            'sale_type' => 'by_amount', 'amount' => '4000', 'payment_method' => 'amial_pay',
        ]);
    }

    /** @test */
    public function sale_by_liters_computes_total(): void
    {
        $pump = $this->svc->addPump($this->station, ['pump_number' => 1]);
        $product = $this->svc->addProduct($this->station, ['name' => 'بنزين', 'price_per_liter' => '500']);

        $sale = $this->svc->recordSale($this->merchant, null, [
            'pump_id' => $pump->id,
            'fuel_product_id' => $product->id,
            'sale_type' => 'by_liters',
            'liters' => 20,
            'payment_method' => 'cash',
        ]);

        $this->assertSame(MoneyService::normalize('20'), (string)$sale->liters);
        $this->assertSame(MoneyService::normalize('10000'), (string)$sale->total_amount);
    }

    /** @test */
    public function sale_by_amount_computes_liters(): void
    {
        $pump = $this->svc->addPump($this->station, ['pump_number' => 2]);
        $product = $this->svc->addProduct($this->station, ['name' => 'بنزين', 'price_per_liter' => '500']);

        $sale = $this->svc->recordSale($this->merchant, null, [
            'pump_id' => $pump->id,
            'fuel_product_id' => $product->id,
            'sale_type' => 'by_amount',
            'amount' => 5000,
            'payment_method' => 'cash',
        ]);

        $this->assertSame(MoneyService::normalize('10'), (string)$sale->liters);
        $this->assertSame(MoneyService::normalize('5000'), (string)$sale->total_amount);
    }

    /** @test */
    public function mechanical_pump_updates_meter_reading(): void
    {
        $pump = $this->svc->addPump($this->station, [
            'pump_number' => 3,
            'pump_type' => 'mechanical',
            'initial_meter_reading' => 1000,
        ]);
        $product = $this->svc->addProduct($this->station, ['name' => 'بنزين', 'price_per_liter' => '500']);

        $sale = $this->svc->recordSale($this->merchant, null, [
            'pump_id' => $pump->id,
            'fuel_product_id' => $product->id,
            'sale_type' => 'by_liters',
            'liters' => 15,
            'payment_method' => 'cash',
        ]);

        $this->assertSame('1000.000', (string)$sale->meter_reading_before);
        $this->assertSame('1015.000', (string)$sale->meter_reading_after);

        $pump->refresh();
        $this->assertSame('1015.000', (string)$pump->current_meter_reading);
    }

    /** @test */
    public function meter_reading_after_cannot_be_less_than_before(): void
    {
        $pump = $this->svc->addPump($this->station, [
            'pump_number' => 4,
            'pump_type' => 'mechanical',
            'initial_meter_reading' => 5000,
        ]);
        $product = $this->svc->addProduct($this->station, ['name' => 'بنزين', 'price_per_liter' => '500']);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->recordSale($this->merchant, null, [
            'pump_id' => $pump->id,
            'fuel_product_id' => $product->id,
            'sale_type' => 'by_liters',
            'liters' => 10,
            'payment_method' => 'cash',
            'meter_reading_after' => 4000, // أقل من 5000 → خطأ
        ]);
    }

    /** @test */
    public function company_card_sale_increases_debt(): void
    {
        $pump = $this->svc->addPump($this->station, ['pump_number' => 5]);
        $product = $this->svc->addProduct($this->station, ['name' => 'ديزل', 'price_per_liter' => '300']);

        $company = $this->svc->addCompanyAccount($this->merchant, [
            'company_name' => 'شركة النقل',
            'credit_limit' => '100000',
        ]);

        $sale = $this->svc->recordSale($this->merchant, null, [
            'pump_id' => $pump->id,
            'fuel_product_id' => $product->id,
            'sale_type' => 'by_liters',
            'liters' => 50,
            'payment_method' => 'company_card',
            'company_account_id' => $company->id,
            'vehicle_plate' => 'أ ب ج 1234',
            'driver_name' => 'محمد علي',
        ]);

        $this->assertSame('company_card', $sale->payment_method);
        $this->assertSame($company->id, $sale->company_account_id);

        $company->refresh();
        $this->assertSame(MoneyService::normalize('15000'), (string)$company->current_balance);
    }

    /** @test */
    public function company_card_rejects_over_credit_limit(): void
    {
        $pump = $this->svc->addPump($this->station, ['pump_number' => 6]);
        $product = $this->svc->addProduct($this->station, ['name' => 'بنزين', 'price_per_liter' => '500']);
        $company = $this->svc->addCompanyAccount($this->merchant, [
            'company_name' => 'شركة صغيرة',
            'credit_limit' => '5000',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('الحد الائتماني');
        $this->svc->recordSale($this->merchant, null, [
            'pump_id' => $pump->id,
            'fuel_product_id' => $product->id,
            'sale_type' => 'by_liters',
            'liters' => 20, // 20 × 500 = 10000 > 5000
            'payment_method' => 'company_card',
            'company_account_id' => $company->id,
        ]);
    }

    /** @test */
    public function company_payment_reduces_debt(): void
    {
        $pump = $this->svc->addPump($this->station, ['pump_number' => 7]);
        $product = $this->svc->addProduct($this->station, ['name' => 'بنزين', 'price_per_liter' => '500']);
        $company = $this->svc->addCompanyAccount($this->merchant, [
            'company_name' => 'شركة',
            'credit_limit' => '50000',
        ]);

        // بيع 30000
        $this->svc->recordSale($this->merchant, null, [
            'pump_id' => $pump->id,
            'fuel_product_id' => $product->id,
            'sale_type' => 'by_amount',
            'amount' => 30000,
            'payment_method' => 'company_card',
            'company_account_id' => $company->id,
        ]);

        $company->refresh();
        $this->assertSame(MoneyService::normalize('30000'), (string)$company->current_balance);

        // سداد 10000
        $this->svc->recordCompanyPayment($company, '10000', 'سداد جزئي');

        $company->refresh();
        $this->assertSame(MoneyService::normalize('20000'), (string)$company->current_balance);
        $this->assertNotNull($company->last_payment_at);
    }

    /** @test */
    public function payment_cannot_exceed_debt(): void
    {
        $company = $this->svc->addCompanyAccount($this->merchant, [
            'company_name' => 'شركة',
        ]);
        $this->expectException(\RuntimeException::class);
        $this->svc->recordCompanyPayment($company, '5000');
    }

    /** @test */
    public function pump_belonging_to_other_merchant_is_rejected(): void
    {
        $other = User::factory()->create(['type' => 3]);
        $otherStation = $this->svc->getOrCreateStation($other);
        $otherPump = $this->svc->addPump($otherStation, ['pump_number' => 1]);
        $product = $this->svc->addProduct($this->station, ['name' => 'بنزين', 'price_per_liter' => '500']);

        $this->expectException(\RuntimeException::class);
        $this->svc->recordSale($this->merchant, null, [
            'pump_id' => $otherPump->id,
            'fuel_product_id' => $product->id,
            'sale_type' => 'by_liters',
            'liters' => 5,
            'payment_method' => 'cash',
        ]);
    }
}
