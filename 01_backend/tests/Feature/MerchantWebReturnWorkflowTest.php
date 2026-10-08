<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\MerchantRefund;
use App\Models\Retail\ProductStock;
use App\Models\Retail\SaleReturn;
use App\Models\User;
use App\Services\CashierService;
use App\Services\CashierShiftService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantWebReturnWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $owner = User::factory()->create([
            'role' => A::ROLE_MERCHANT,
            'type' => MERCHANT_TYPE,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $owner->id,
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        $merchant = new Merchant();
        $merchant->user_id = $owner->id;
        $merchant->merchant_number = 'RETURN-WEB-001';
        $merchant->store_name = 'متجر مرتجعات الويب';
        $merchant->save();

        return $owner;
    }

    /** @test */
    public function owner_web_return_uses_existing_money_and_stock_engine_atomically(): void
    {
        $owner = $this->owner();

        $product = MerchantProduct::create([
            'merchant_user_id' => $owner->id,
            'name' => 'منتج مرتجع',
            'price' => '1000',
            'cost_price' => '600',
            'quantity' => '5',
            'reorder_level' => '1',
            'track_stock' => true,
            'is_active' => true,
        ]);

        app(CashierShiftService::class)->open($owner, null, '0');

        $sale = app(CashierService::class)->recordSale(
            merchant: $owner,
            total: '1000',
            paymentMethod: 'cash',
            items: [[
                'product_id' => $product->id,
                'name' => 'منتج مرتجع',
                'quantity' => 1,
                'price' => '1000',
            ]],
        );

        $this->actingAs($owner, 'merchant_web');

        $info = $this->getJson('/merchant/data/sector/sales/'.$sale->sale_ulid.'/return-info')
            ->assertOk()
            ->assertJsonPath('meta.remaining', '1000.0000')
            ->assertJsonPath('meta.fully_refunded', false)
            ->json('meta');

        $lineId = $info['lines'][0]['id'];

        $this->withHeader('Idempotency-Key', 'merchant-web-return-001')
            ->postJson('/merchant/data/sector/sales/'.$sale->sale_ulid.'/returns', [
                'amount' => '1000',
                'refund_method' => 'cash',
                'reason' => 'عاد المنتج سليماً',
                'items' => [[
                    'sale_item_id' => $lineId,
                    'quantity' => '1',
                    'condition' => 'good',
                    'restock' => true,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('code', 'REFUNDED')
            ->assertJsonPath('meta.refund.status', 'completed');

        $refund = MerchantRefund::where('original_sale_ulid', $sale->sale_ulid)->firstOrFail();
        $this->assertSame('completed', $refund->status);

        $goods = SaleReturn::where('refund_ulid', $refund->refund_ulid)->firstOrFail();
        $this->assertSame('approved', $goods->status);

        $stock = ProductStock::where('product_id', $product->id)->value('on_hand');
        $this->assertSame(0, bccomp((string) $stock, '5', 3),
            'المرتجع لم يُعد الصنف السليم إلى المخزون');

        $this->getJson('/merchant/data/sector/returns')
            ->assertOk()
            ->assertJsonPath('meta.refunds.0.original_sale_ulid', $sale->sale_ulid);

        $this->getJson('/merchant/data/sales-v2?search='.$sale->sale_ulid)
            ->assertOk()
            ->assertJsonPath('meta.rows.0.refunded_total', '1000.0000');
    }
}
