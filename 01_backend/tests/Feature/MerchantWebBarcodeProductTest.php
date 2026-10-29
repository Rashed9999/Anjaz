<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CashierService;
use App\Services\Vertical\VerticalBootstrapService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Merchant web must manage real products used by the POS, not a duplicate table. */
class MerchantWebBarcodeProductTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $u = User::factory()->create(['role' => A::ROLE_MERCHANT, 'type' => MERCHANT_TYPE,
            'is_active' => 1, 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $u->id, 'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE, 'verification_status' => 'verified']);
        app(VerticalBootstrapService::class)->ensureFor($u);
        return $u;
    }

    public function test_owner_creates_catalogue_and_product_then_scanner_finds_exactly_the_same_row(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'merchant_web');
        $cat = $this->postJson('/merchant/data/sector/catalog/categories', ['name' => 'مشروبات'])
            ->assertOk()->json('meta.result.id');
        $this->assertNotNull($cat);
        $brand = $this->postJson('/merchant/data/sector/catalog/brands', ['name' => 'علامة تجريبية'])
            ->assertOk()->json('meta.result.id');
        $unit = $this->postJson('/merchant/data/sector/catalog/units', ['name' => 'حبة'])
            ->assertOk()->json('meta.result.id');
        $this->getJson('/merchant/data/sector/catalog/options')->assertOk()
            ->assertJsonCount(1, 'meta.categories')->assertJsonCount(1, 'meta.brands');
        $created = $this->postJson('/merchant/data/sector/products', [
            'name' => 'عصير', 'price' => '180', 'cost_price' => '120',
            'quantity' => 50, 'barcode' => '6291000011946',
            'category_id' => $cat, 'brand_id' => $brand, 'unit_id' => $unit,
        ])->assertOk()->json('meta.result.product.id');
        $this->assertNotNull($created);
        $this->getJson('/merchant/data/sector/products/lookup?barcode=6291000011946')
            ->assertOk()->assertJsonPath('meta.product.id', $created);
        $this->postJson('/merchant/data/sector/products/'.$created.'/barcodes', [
            'barcode' => '6291000011953', 'pack_size' => 24,
        ])->assertOk();
        $this->getJson('/merchant/data/sector/products/lookup?barcode=6291000011953')
            ->assertOk()->assertJsonPath('meta.pack_size', '24.000')
            ->assertJsonPath('meta.product.id', $created);
        $this->putJson('/merchant/data/sector/products/'.$created, ['price' => '190'])
            ->assertOk()->assertJsonPath('meta.result.product.price', '190.0000');
    }

    public function test_owner_cannot_assign_other_merchant_category_or_find_their_barcode(): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $otherProduct = app(CashierService::class)->addProduct($other, [
            'name' => 'خاص', 'price' => '100', 'barcode' => '6291000099193',
        ]);
        $cat = \App\Models\Retail\MerchantCategory::where('merchant_user_id', $other->id)->first();
        if (!$cat) $cat = app(\App\Services\Retail\ProductCatalogService::class)
            ->addCategory($other, ['name' => 'خاص']);
        $this->actingAs($owner, 'merchant_web')
            ->getJson('/merchant/data/sector/products/lookup?barcode=6291000099193')->assertNotFound();
        $this->postJson('/merchant/data/sector/products', [
            'name' => 'تلاعب', 'price' => 100, 'category_id' => $cat->id,
        ])->assertStatus(422);
        $this->assertDatabaseMissing('merchant_products', [
            'merchant_user_id' => $owner->id, 'name' => 'تلاعب',
        ]);
    }

    public function test_pos_cannot_open_web_catalogue_or_edit_products(): void
    {
        $this->owner();
        $user = User::factory()->create(['type' => 4, 'role' => 'pos']);
        $this->actingAs($user, 'merchant_web')
            ->getJson('/merchant/data/sector/catalog/options')->assertForbidden();
        $this->getJson('/merchant/data/sector/products/lookup?barcode=1234')->assertUnauthorized();
    }
}
