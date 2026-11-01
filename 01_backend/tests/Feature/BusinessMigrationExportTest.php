<?php

namespace Tests\Feature;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditMovement;
use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\Pharmacy;
use App\Models\PharmacyBatch;
use App\Models\PharmacyProduct;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductStock;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCustomer;
use App\Models\WholesalePriceTier;
use App\Models\WholesaleProduct;
use App\Models\WholesaleProductLot;
use App\Models\WholesaleProductPrice;
use App\Models\WholesaleProductUnit;
use App\Services\BusinessGateway\BusinessGatewaySignature;
use App\Services\BusinessGateway\BusinessLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * AMIAL-BUSINESS-MIGRATION-001 — التصدير يقرأ كل صنف تاجر من مصدره الحقيقي،
 * ويبلّغ كل فرقٍ ولا يخفيه، ولا يرى بيانات تاجرٍ آخر.
 */
class BusinessMigrationExportTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'migration-test-secret';

    private User $merchant;
    private BusinessLink $link;

    protected function setUp(): void
    {
        parent::setUp();
        config(['amial_business.inbound_secret' => self::SECRET]);
        $this->merchant = $this->makeMerchant('+967700300001');
        $this->link = app(BusinessLinkService::class)->link($this->merchant);
    }

    #[Test]
    public function products_come_from_their_true_stock_source_per_vertical(): void
    {
        $m = $this->merchant->id;
        // تجزئة: صفوف مواقع (3 + 4) — والعمود القديم 99 يُتجاهل
        $rice = MerchantProduct::create(['merchant_user_id' => $m, 'name' => 'أرز', 'price' => '2500', 'cost_price' => '2000', 'quantity' => '99', 'sku' => 'RICE', 'is_active' => true]);
        foreach ([3, 4] as $i => $q) {
            $loc = MerchantLocation::create(['merchant_user_id' => $m, 'code' => "L{$i}", 'name' => "L{$i}", 'kind' => 'store', 'is_active' => true, 'is_default' => $i === 0]);
            ProductStock::create(['product_id' => $rice->id, 'location_id' => $loc->id, 'on_hand' => (string) $q, 'reserved' => '0']);
        }
        // تجزئة بلا صفوف: العمود القديم
        MerchantProduct::create(['merchant_user_id' => $m, 'name' => 'سكر', 'price' => '900', 'quantity' => '12', 'is_active' => true]);
        // أصلُ متغيّرات لا يُصدَّر
        MerchantProduct::create(['merchant_user_id' => $m, 'name' => 'قميص (أصل)', 'price' => '1', 'quantity' => '0', 'is_variant_parent' => true, 'is_active' => true]);

        // جملة: دفعتان نشطتان = 30، والعمود المخزَّن 31 ⇒ تحذير
        $biz = WholesaleBusiness::create(['merchant_user_id' => $m, 'business_name' => 'جملة', 'is_active' => true]);
        $gold = WholesalePriceTier::create(['business_id' => $biz->id, 'code' => 'gold', 'name' => 'ذهبي', 'is_active' => true]);
        $water = WholesaleProduct::create(['business_id' => $biz->id, 'sku' => 'W1', 'name' => 'ماء', 'unit' => 'حبة', 'current_stock' => '31', 'cost_price' => '70', 'base_price' => '100', 'is_active' => true]);
        WholesaleProductUnit::create(['product_id' => $water->id, 'code' => 'CTN', 'name' => 'كرتون', 'factor_to_base' => '24', 'is_base' => false, 'is_active' => true]);
        WholesaleProductPrice::create(['product_id' => $water->id, 'tier_id' => $gold->id, 'price' => '90', 'min_quantity' => '10']);
        foreach (['10', '20'] as $q) {
            WholesaleProductLot::create(['lot_ulid' => (string) Str::ulid(), 'product_id' => $water->id, 'lot_number' => "L{$q}", 'quantity_received' => $q, 'quantity_available' => $q, 'cost_per_base_unit' => '70', 'status' => 'active', 'received_at' => today()]);
        }

        // صيدلية: دفعة صالحة 8 ودفعة منتهية 5 ⇒ تُصدَّر 8 وتُبلَّغ المنتهية
        $ph = Pharmacy::create(['merchant_user_id' => $m, 'pharmacy_name' => 'صيدلية', 'is_active' => true]);
        $pan = PharmacyProduct::create(['pharmacy_id' => $ph->id, 'sku' => 'PAN', 'trade_name' => 'Panadol', 'generic_name' => 'Paracetamol', 'sale_price' => '500', 'cost_price' => '300', 'current_stock' => '13', 'requires_prescription' => false, 'is_active' => true]);
        PharmacyBatch::create(['batch_ulid' => (string) Str::ulid(), 'product_id' => $pan->id, 'batch_number' => 'OK', 'expiry_date' => now()->addYear(), 'quantity_received' => '8', 'quantity_remaining' => '8', 'cost_per_unit' => '300', 'status' => 'active']);
        PharmacyBatch::create(['batch_ulid' => (string) Str::ulid(), 'product_id' => $pan->id, 'batch_number' => 'OLD', 'expiry_date' => now()->subDay(), 'quantity_received' => '5', 'quantity_remaining' => '5', 'cost_per_unit' => '300', 'status' => 'active']);

        $items = collect($this->signed('/api/v1/business/migration/products')->assertOk()->json('meta.items'))->keyBy(fn ($i) => $i['source'].':'.$i['name']);

        $this->assertCount(4, $items);
        $this->assertSame('7.0000', $items['retail:أرز']['quantity']);
        $this->assertSame('12.0000', $items['retail:سكر']['quantity']);
        $this->assertNull($items['retail:سكر']['unit_cost']);
        $this->assertSame('30.0000', $items['wholesale:ماء']['quantity']);
        $this->assertSame('24.0000', $items['wholesale:ماء']['units'][0]['factor']);
        $this->assertSame('gold', $items['wholesale:ماء']['prices'][0]['tier_code']);
        $this->assertSame('8.0000', $items['pharmacy:Panadol']['quantity']);
        $this->assertCount(1, $items['pharmacy:Panadol']['batches']);
        $this->assertSame('Paracetamol', $items['pharmacy:Panadol']['pharmacy']['generic_name']);

        $summary = $this->signed('/api/v1/business/migration/summary')->assertOk()->json('meta');
        $warnings = collect($summary['warnings'])->pluck('warning')->implode(' | ');
        $this->assertSame(4, $summary['totals']['products']);
        $this->assertStringContainsString('COST_UNKNOWN', $warnings);
        $this->assertStringContainsString('WHOLESALE_STOCK_MISMATCH', $warnings);
        $this->assertStringContainsString('EXPIRED_BATCH_NOT_EXPORTED: OLD', $warnings);
        // 7×2000 + 12×0(مجهول) + 30×70 + 8×300
        $this->assertSame('18500.0000', $summary['totals']['stock_value']);
    }

    #[Test]
    public function balances_are_exported_and_ledger_mismatches_are_reported(): void
    {
        $m = $this->merchant->id;
        $acc = CustomerCreditAccount::create(['merchant_user_id' => $m, 'customer_name' => 'محل النور', 'customer_phone' => '777000111', 'credit_limit' => '50000', 'current_balance' => '12000', 'is_active' => true]);
        CustomerCreditMovement::create(['movement_ulid' => (string) Str::ulid(), 'account_id' => $acc->id, 'type' => 'sale', 'amount' => '11000', 'balance_after' => '11000']);
        $biz = WholesaleBusiness::create(['merchant_user_id' => $m, 'business_name' => 'جملة', 'default_payment_terms_days' => 30, 'is_active' => true]);
        WholesaleCustomer::create(['business_id' => $biz->id, 'full_name' => 'سوبرماركت', 'current_balance' => '8000', 'credit_limit' => '100000', 'is_active' => true]);
        Supplier::create(['merchant_user_id' => $m, 'name' => 'مصنع', 'current_debt' => '5000', 'current_credit' => '0', 'is_active' => true]);

        $summary = $this->signed('/api/v1/business/migration/summary')->json('meta');

        $this->assertSame('20000.0000', $summary['totals']['receivable']);
        $this->assertSame('5000.0000', $summary['totals']['payable']);
        $this->assertStringContainsString('BALANCE_MISMATCH', collect($summary['warnings'])->pluck('warning')->implode(' '));
        $customers = $this->signed('/api/v1/business/migration/customers')->json('meta.items');
        $this->assertSame(30, collect($customers)->firstWhere('name', 'سوبرماركت')['payment_terms_days']);
    }

    #[Test]
    public function a_fuel_station_exports_tank_stock_nozzle_meters_and_fleet_accounts(): void
    {
        $m = $this->merchant->id;
        $station = \App\Models\FuelStation::create(['merchant_user_id' => $m, 'station_name' => 'محطة', 'is_active' => true]);
        $diesel = \App\Models\FuelProduct::create(['station_id' => $station->id, 'name' => 'ديزل', 'product_code' => 'DSL', 'price_per_liter' => '700', 'is_active' => true]);
        $t1 = \App\Models\Fuel\FuelTank::create(['station_id' => $station->id, 'tank_number' => 1, 'fuel_product_id' => $diesel->id, 'capacity_liters' => '20000', 'book_liters' => '8000', 'min_alert_liters' => '1000', 'is_active' => true]);
        \App\Models\Fuel\FuelTank::create(['station_id' => $station->id, 'tank_number' => 2, 'fuel_product_id' => $diesel->id, 'capacity_liters' => '10000', 'book_liters' => '1500', 'min_alert_liters' => '500', 'is_active' => true]);
        $pump = \App\Models\FuelPump::create(['station_id' => $station->id, 'pump_number' => 3, 'is_active' => true]);
        \App\Models\Fuel\FuelNozzle::create(['pump_id' => $pump->id, 'nozzle_number' => 1, 'fuel_product_id' => $diesel->id, 'tank_id' => $t1->id, 'current_meter_reading' => '123456.789', 'is_active' => true]);
        $company = \App\Models\FuelCompanyAccount::create(['merchant_user_id' => $m, 'company_name' => 'شركة النقل', 'credit_limit' => '0', 'current_balance' => '42000', 'is_active' => true]);
        \App\Models\FuelCompanyCard::create(['company_account_id' => $company->id, 'card_number' => 'TR-9', 'vehicle_plate' => 'عدن 7', 'daily_limit' => '50000', 'monthly_limit' => '0', 'is_active' => true]);

        $summary = $this->signed('/api/v1/business/migration/summary')->json('meta');
        $this->assertSame('9500.0000', $summary['totals']['stock_quantity']);
        $this->assertSame('42000.0000', $summary['totals']['receivable']);
        $this->assertStringContainsString('COST_UNKNOWN', collect($summary['warnings'])->pluck('warning')->implode(' '));

        $fuel = collect($this->signed('/api/v1/business/migration/products')->json('meta.items'))->firstWhere('source', 'fuel');
        $this->assertSame([1, 2], array_column($fuel['fuel']['tanks'], 'number'));
        $this->assertSame(['pump_number' => 3, 'nozzle_number' => 1, 'tank_number' => 1, 'meter' => '123456.789'], $fuel['fuel']['nozzles'][0]);

        $fleet = collect($this->signed('/api/v1/business/migration/customers')->json('meta.items'))->firstWhere('source', 'fuel');
        $this->assertNull($fleet['credit_limit']); // صفر = بلا حدّ في أميال باي
        $this->assertSame([], $summary['restaurant_tables']);
        $this->assertSame(['card_number' => 'TR-9', 'vehicle_plate' => 'عدن 7', 'driver_name' => null, 'daily_limit' => '50000.0000', 'monthly_limit' => null], $fleet['fuel_cards'][0]);
    }

    #[Test]
    public function one_merchant_never_sees_another_merchants_data(): void
    {
        $other = $this->makeMerchant('+967700300009');
        MerchantProduct::create(['merchant_user_id' => $other->id, 'name' => 'سري', 'price' => '1', 'quantity' => '1', 'is_active' => true]);

        $this->assertSame([], $this->signed('/api/v1/business/migration/products')->json('meta.items'));
        $this->assertSame(0, $this->signed('/api/v1/business/migration/summary')->json('meta.totals.products'));
    }

    #[Test]
    public function the_export_requires_a_valid_signature(): void
    {
        $this->getJson('/api/v1/business/migration/summary', ['X-Amial-Business-Link' => $this->link->link_ulid])->assertStatus(401);
    }

    private function signed(string $pathWithQuery): TestResponse
    {
        [$path, $query] = array_pad(explode('?', $pathWithQuery, 2), 2, '');
        $ts = (string) time();
        $nonce = Str::random(32);
        $sig = BusinessGatewaySignature::sign(BusinessGatewaySignature::inboundCanonical($ts, $nonce, 'GET', $path, $query, ''), self::SECRET);

        return $this->call('GET', $pathWithQuery, [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AMIAL_BUSINESS_TIMESTAMP' => $ts, 'HTTP_X_AMIAL_BUSINESS_NONCE' => $nonce,
            'HTTP_X_AMIAL_BUSINESS_SIGNATURE' => $sig, 'HTTP_X_AMIAL_BUSINESS_LINK' => $this->link->link_ulid,
        ]);
    }

    private function makeMerchant(string $phone): User
    {
        $u = User::factory()->create(['type' => 3, 'phone' => $phone, 'zone_code' => 'SOUTH', 'is_active' => 1, 'is_kyc_verified' => 1]);
        MerchantProfile::create(['user_id' => $u->id, 'verification_status' => 'verified']);

        return $u;
    }
}
