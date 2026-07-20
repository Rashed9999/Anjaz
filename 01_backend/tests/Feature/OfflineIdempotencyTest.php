<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\User;
use App\Services\CashierService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AMIAL-OFFLINE-POS-001 — idempotency: مزامنة بيع دون اتصال لا تُكرّره. */
class OfflineIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => A::PLAN_BUSINESS]);
    }

    /** @test إرسال البيع مرّتين بنفس client_uuid ينشئ صفّاً واحداً فقط. */
    public function same_client_uuid_creates_one_sale(): void
    {
        $product = MerchantProduct::create([
            'merchant_user_id' => $this->merchant->id, 'name' => 'سكر', 'price' => '1000',
            'quantity' => 10, 'is_active' => true, 'zone_code' => 'SOUTH',
        ]);
        $svc = app(CashierService::class);
        $uuid = 'offline-abc-123';
        $items = [['name' => 'سكر', 'quantity' => 2, 'price' => 1000, 'product_id' => $product->id]];

        $s1 = $svc->recordSale(merchant: $this->merchant, total: '2000', paymentMethod: 'cash',
            items: $items, clientUuid: $uuid);
        // إعادة المزامنة (نفس المفتاح)
        $s2 = $svc->recordSale(merchant: $this->merchant, total: '2000', paymentMethod: 'cash',
            items: $items, clientUuid: $uuid);

        $this->assertSame($s1->id, $s2->id);
        $this->assertSame(1, MerchantSale::where('merchant_user_id', $this->merchant->id)->count());
        // المخزون خُصم مرّة واحدة فقط: 10 - 2 = 8
        $this->assertEquals(8, (int) $product->fresh()->quantity);
    }

    /** @test مفاتيح مختلفة = عمليتان منفصلتان. */
    public function different_uuids_create_two_sales(): void
    {
        $svc = app(CashierService::class);
        $svc->recordSale(merchant: $this->merchant, total: '500', paymentMethod: 'cash', items: [], clientUuid: 'a');
        $svc->recordSale(merchant: $this->merchant, total: '700', paymentMethod: 'cash', items: [], clientUuid: 'b');
        $this->assertSame(2, MerchantSale::where('merchant_user_id', $this->merchant->id)->count());
    }
}
