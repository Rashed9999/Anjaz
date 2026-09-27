<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\MerchantProduct;
use App\Models\User;
use App\Models\Retail\ProductBarcode;
use App\Models\Retail\StockMovement;
use App\Services\CashierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/** Owner web, phone scanner and POS must resolve the SAME merchant barcode row. */
class BarcodeProductIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $u = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $u->id, 'business_type' => 'retail',
            'verification_status' => 'verified', 'subscription_plan' => 'enterprise']);
        return $u;
    }

    public function test_new_product_primary_barcode_is_indexed_and_scans_in_both_cashier_endpoints(): void
    {
        $owner = $this->owner();
        $p = app(CashierService::class)->addProduct($owner, [
            'name' => 'حليب', 'price' => '120', 'quantity' => '100', 'barcode' => '6291000019911',
        ]);
        $this->assertDatabaseHas('product_barcodes', [
            'merchant_user_id' => $owner->id, 'product_id' => $p->id,
            'barcode' => '6291000019911', 'is_primary' => true,
        ]);
        Passport::actingAs($owner);
        $this->getJson('/api/v1/amial/merchant/cashier/products/lookup?barcode=6291000019911')
            ->assertOk()->assertJsonPath('meta.product.id', $p->id)
            ->assertJsonPath('meta.pack_size', '1.000');
        $this->getJson('/api/v1/amial/barcode/lookup?barcode=6291000019911&context=auto')
            ->assertOk()->assertJsonPath('meta.product.id', $p->id)
            ->assertJsonPath('meta.pack_size', '1.000');
    }

    public function test_two_products_of_one_merchant_cannot_claim_same_barcode_or_alias(): void
    {
        $owner = $this->owner();
        $svc = app(CashierService::class);
        $first = $svc->addProduct($owner, [
            'name' => 'كرتون', 'price' => '100', 'barcode' => '6251980009991',
        ]);
        $other = $svc->addProduct($owner, ['name' => 'آخر', 'price' => '70']);
        $this->expectException(\DomainException::class);
        $svc->updateProduct($owner, $other->id, ['barcode' => $first->barcode]);
    }

    public function test_pack_barcode_finds_its_actual_product_and_quantity_without_cross_merchant_leak(): void
    {
        $owner = $this->owner();
        $p = app(CashierService::class)->addProduct($owner, [
            'name' => 'مياه', 'price' => '100', 'quantity' => '90', 'barcode' => '6291000011011',
        ]);
        $row = app(\App\Services\Retail\ProductCatalogService::class)->addBarcode(
            $owner, $p->id, ['barcode' => '6291000011028', 'pack_size' => '24']
        );
        $this->assertSame($p->id, $row->product_id);
        Passport::actingAs($owner);
        $this->getJson('/api/v1/amial/merchant/cashier/products/lookup?barcode=6291000011028')
            ->assertOk()->assertJsonPath('meta.product.id', $p->id)
            ->assertJsonPath('meta.pack_size', '24.000');
        $this->getJson('/api/v1/amial/barcode/lookup?barcode=6291000011028')
            ->assertOk()->assertJsonPath('meta.pack_size', '24.000');
        $other = $this->owner();
        Passport::actingAs($other);
        $this->getJson('/api/v1/amial/barcode/lookup?barcode=6291000011028')
            ->assertNotFound();
    }

    public function test_inactive_product_and_variant_parent_never_scan_via_alias(): void
    {
        $owner = $this->owner();
        $svc = app(CashierService::class);
        $p = $svc->addProduct($owner, ['name' => 'علبة', 'price' => '10', 'barcode' => '1234999911']);
        $svc->updateProduct($owner, $p->id, ['is_active' => false]);
        $this->assertNull($svc->findByBarcode($owner, '1234999911'));
        $p->refresh()->update(['is_active' => true, 'is_variant_parent' => true]);
        $this->assertNull($svc->findByBarcode($owner, '1234999911'));
    }

    public function test_editing_stock_records_a_count_adjustment_and_disabling_stock_tracking_skips_deductions(): void
    {
        $owner = $this->owner();
        $svc = app(CashierService::class);
        $p = $svc->addProduct($owner, ['name' => 'خدمة تركيب',
            'price' => '100', 'quantity' => '8']);
        $svc->updateProduct($owner, $p->id, ['quantity' => '13']);
        $this->assertSame('13.000', (string) $p->fresh()->quantity);
        $this->assertDatabaseHas('stock_movements', [
            'merchant_user_id' => $owner->id, 'product_id' => $p->id,
            'reason' => 'count_adjustment', 'quantity_delta' => '5.000',
        ]);
        $svc->updateProduct($owner, $p->id, ['track_stock' => false]);
        $svc->recordSale($owner, '200', 'cash', [[
            'product_id' => $p->id, 'name' => 'خدمة تركيب', 'qty' => 2, 'price' => 100,
        ]]);
        $this->assertSame('13.000', (string) $p->fresh()->quantity);
    }
}
