<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CashierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-CASHIER-BARCODE-001 — مسح باركود منتج الكاشير.
 *
 * يثبت: بحث المنتج بالباركود (HTTP) يعيد المنتج، الباركود المجهول يعيد 404
 * (ليُنشئه التطبيق عند المسح)، عزل التجار (لا يرى تاجر باركود تاجر آخر)،
 * والبيع بـ product_id يخصم المخزون فعلاً.
 */
class CashierBarcodeTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private CashierService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CashierService::class);
        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'business_name' => 'بقالة تجريبية']);
    }

    private function product(array $o = []): MerchantProduct
    {
        return $this->svc->addProduct($this->merchant, array_merge([
            'name' => 'أرز', 'price' => '750', 'quantity' => '10', 'barcode' => '6291000001234',
        ], $o));
    }

    public function test_lookup_returns_product_by_barcode(): void
    {
        $p = $this->product();
        Passport::actingAs($this->merchant);

        $this->getJson('/api/v1/amial/merchant/cashier/products/lookup?barcode=6291000001234')
            ->assertOk()
            ->assertJsonPath('meta.product.id', $p->id)
            ->assertJsonPath('meta.product.name', 'أرز')
            ->assertJsonPath('meta.product.barcode', '6291000001234');
    }

    public function test_unknown_barcode_returns_404(): void
    {
        $this->product();
        Passport::actingAs($this->merchant);

        $this->getJson('/api/v1/amial/merchant/cashier/products/lookup?barcode=0000000000000')
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_barcode_is_isolated_per_merchant(): void
    {
        $this->product(); // باركود التاجر الأول
        $other = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $other->id, 'business_name' => 'متجر آخر']);
        Passport::actingAs($other);

        // التاجر الآخر لا يجد باركود التاجر الأول
        $this->getJson('/api/v1/amial/merchant/cashier/products/lookup?barcode=6291000001234')
            ->assertStatus(404);
    }

    public function test_service_find_by_barcode(): void
    {
        $p = $this->product();
        $this->assertSame($p->id, $this->svc->findByBarcode($this->merchant, '6291000001234')?->id);
        $this->assertNull($this->svc->findByBarcode($this->merchant, 'nope'));
        $this->assertNull($this->svc->findByBarcode($this->merchant, '  '));
    }

    public function test_sale_with_product_id_decrements_stock(): void
    {
        $p = $this->product(['quantity' => '10']);

        $this->svc->recordSale(
            merchant: $this->merchant,
            total: '1500',
            paymentMethod: 'cash',
            items: [['product_id' => $p->id, 'name' => 'أرز', 'qty' => 3, 'price' => '750']],
        );

        $this->assertSame('7', (string) (int) $p->fresh()->quantity); // 10 - 3
    }
}
