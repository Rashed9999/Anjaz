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
 * AMIAL-BARCODE-001 — نقطة المسح الموحّدة تخدم قطاع التجزئة العام.
 *
 * قبل الإصلاح: 'auto' كان يوجّه كل القطاعات غير wholesale/pharmacy إلى wholesale،
 * فلا يجد تاجر التجزئة (كتالوج الكاشير) منتجاته. الآن 'auto' يوجّه التجزئة إلى
 * كتالوج الكاشير (MerchantProduct) — نفس مصدر بحث الكاشير.
 */
class UnifiedBarcodeLookupTest extends TestCase
{
    use RefreshDatabase;

    private function retailMerchant(string $businessType = 'retail'): User
    {
        $m = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $m->id, 'business_type' => $businessType]);
        return $m;
    }

    private function product(User $m, array $o = []): MerchantProduct
    {
        return app(CashierService::class)->addProduct($m, array_merge([
            'name' => 'سكر', 'price' => '900', 'quantity' => '25', 'barcode' => '6291000009999',
        ], $o));
    }

    public function test_auto_context_routes_retail_merchant_to_cashier_catalog(): void
    {
        $m = $this->retailMerchant('retail');
        $p = $this->product($m);
        Passport::actingAs($m);

        $this->getJson('/api/v1/amial/barcode/lookup?barcode=6291000009999&context=auto')
            ->assertOk()
            ->assertJsonPath('meta.context', 'retail')
            ->assertJsonPath('meta.product.id', $p->id)
            ->assertJsonPath('meta.product._type', 'retail')
            ->assertJsonPath('meta.product.name', 'سكر');
    }

    public function test_general_business_type_also_routes_to_retail(): void
    {
        $m = $this->retailMerchant('general');
        $p = $this->product($m, ['barcode' => '6291000008888']);
        Passport::actingAs($m);

        $this->getJson('/api/v1/amial/barcode/lookup?barcode=6291000008888&context=auto')
            ->assertOk()
            ->assertJsonPath('meta.context', 'retail')
            ->assertJsonPath('meta.product.id', $p->id);
    }

    public function test_explicit_retail_context(): void
    {
        $m = $this->retailMerchant('retail');
        $p = $this->product($m, ['barcode' => '6291000007777']);
        Passport::actingAs($m);

        $this->getJson('/api/v1/amial/barcode/lookup?barcode=6291000007777&context=retail')
            ->assertOk()
            ->assertJsonPath('meta.product.id', $p->id)
            ->assertJsonPath('meta.product.available_stock', 25);
    }

    public function test_unknown_barcode_returns_404(): void
    {
        $m = $this->retailMerchant('retail');
        $this->product($m);
        Passport::actingAs($m);

        $this->getJson('/api/v1/amial/barcode/lookup?barcode=0000000000000&context=auto')
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_retail_barcode_isolated_per_merchant(): void
    {
        $m1 = $this->retailMerchant('retail');
        $this->product($m1, ['barcode' => '6291000006666']);
        $m2 = $this->retailMerchant('retail');
        Passport::actingAs($m2);

        $this->getJson('/api/v1/amial/barcode/lookup?barcode=6291000006666&context=auto')
            ->assertStatus(404);
    }
}
