<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductStock;
use App\Models\Retail\SaleReturn;
use App\Models\User;
use App\Services\BranchService;
use App\Services\CashierService;
use App\Services\CashierShiftService;
use App\Services\MerchantSaleRefundService;
use App\Services\Retail\StockReservationService;
use App\Services\Retail\StockService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-RETAIL-BRANCH-STOCK-001
 *
 * الفرع والموقع والبيع والحجز والمرتجع حقيقة تشغيلية واحدة.
 * هذا الحارس يمنع رجوع العطل الذي يجعل فرعاً ثانياً يبيع من مخزون MAIN.
 */
class RetailBranchStockTruthTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private BranchService $branches;
    private StockService $stock;
    private CashierService $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = User::factory()->create([
            'type' => MERCHANT_TYPE,
            'role' => A::ROLE_MERCHANT,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'business_type' => A::BIZ_RETAIL,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        $this->branches = app(BranchService::class);
        $this->stock = app(StockService::class);
        $this->cashier = app(CashierService::class);
    }

    /** @test */
    public function branch_sale_reservation_payment_and_return_touch_only_that_branch_stock(): void
    {
        $main = $this->branches->create($this->merchant, [
            'name' => 'الفرع الرئيسي',
            'code' => 'MAIN-BRANCH',
        ]);
        $second = $this->branches->create($this->merchant, [
            'name' => 'فرع التحرير',
            'code' => 'TAHRIR',
        ]);

        $mainLocation = MerchantLocation::where('branch_id', $main->id)->firstOrFail();
        $secondLocation = MerchantLocation::where('branch_id', $second->id)->firstOrFail();

        $this->assertTrue((bool) $mainLocation->is_default);
        $this->assertFalse((bool) $secondLocation->is_default);

        $product = $this->cashier->addProduct($this->merchant, [
            'name' => 'منتج متعدد الفروع',
            'price' => '100',
            'cost_price' => '60',
            'quantity' => '10',
            'reorder_level' => '2',
        ]);

        // وزّع أربع وحدات فعلياً إلى الفرع الثاني بحركتين موثقتين.
        $this->stock->ensureLocationStock($product, $secondLocation);
        $this->stock->move(
            $product, $mainLocation, '-4', 'transfer_out', $this->merchant,
            sourceType: 'test_transfer', note: 'اختبار نقل إلى الفرع الثاني',
        );
        $this->stock->move(
            $product, $secondLocation, '4', 'transfer_in', $this->merchant,
            sourceType: 'test_transfer', note: 'اختبار استلام الفرع الثاني',
        );

        $this->assertSame('6.000', (string) ProductStock::where([
            'product_id' => $product->id,
            'location_id' => $mainLocation->id,
        ])->value('on_hand'));
        $this->assertSame('4.000', (string) ProductStock::where([
            'product_id' => $product->id,
            'location_id' => $secondLocation->id,
        ])->value('on_hand'));

        app(CashierShiftService::class)->open(
            $this->merchant, null, '0', null, $second->id,
        );

        // بيع نقدي من الفرع الثاني: MAIN لا يتحرك.
        $cashSale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '100',
            paymentMethod: 'cash',
            items: [[
                'product_id' => $product->id,
                'name' => 'منتج متعدد الفروع',
                'qty' => 1,
                'price' => '100',
            ]],
            branchId: $second->id,
        );

        $this->assertSame($second->id, (int) $cashSale->branch_id);
        $this->assertSame('6.000', (string) ProductStock::where([
            'product_id' => $product->id,
            'location_id' => $mainLocation->id,
        ])->value('on_hand'));
        $this->assertSame('3.000', (string) ProductStock::where([
            'product_id' => $product->id,
            'location_id' => $secondLocation->id,
        ])->value('on_hand'));

        // بيع أميال معلّق: يحجز في الفرع الثاني ولا يخصم الموجود بعد.
        $pending = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '100',
            paymentMethod: 'amial_pay',
            items: [[
                'product_id' => $product->id,
                'name' => 'منتج متعدد الفروع',
                'qty' => 1,
                'price' => '100',
            ]],
            branchId: $second->id,
        );

        $this->assertSame('pending_payment', $pending->status);
        $reservation = \App\Models\Retail\StockReservation::where('sale_id', $pending->id)
            ->firstOrFail();
        $this->assertSame($secondLocation->id, (int) $reservation->location_id);
        $this->assertSame('1.000', (string) ProductStock::where([
            'product_id' => $product->id,
            'location_id' => $secondLocation->id,
        ])->value('reserved'));
        $this->assertSame('3.000', (string) ProductStock::where([
            'product_id' => $product->id,
            'location_id' => $secondLocation->id,
        ])->value('on_hand'));

        // نجاح الدفع يستهلك الحجز في الموقع نفسه.
        $linked = $this->cashier->linkPayment(
            $pending->sale_ulid, 'BRANCH-STOCK-TX-1', $this->merchant->id,
        );
        $this->assertNotNull($linked);
        $this->assertSame('0.000', (string) ProductStock::where([
            'product_id' => $product->id,
            'location_id' => $secondLocation->id,
        ])->value('reserved'));
        $this->assertSame('2.000', (string) ProductStock::where([
            'product_id' => $product->id,
            'location_id' => $secondLocation->id,
        ])->value('on_hand'));
        $this->assertSame('6.000', (string) ProductStock::where([
            'product_id' => $product->id,
            'location_id' => $mainLocation->id,
        ])->value('on_hand'));

        // مرتجع البيعة النقدية يعيد الوحدة إلى فرعها الأصلي، لا MAIN.
        $line = $cashSale->lines()->firstOrFail();
        $refund = app(MerchantSaleRefundService::class)->refund(
            merchant: $this->merchant,
            originalSaleUlid: $cashSale->sale_ulid,
            refundAmount: '100',
            refundMethod: 'cash',
            items: [[
                'sale_item_id' => $line->id,
                'quantity' => '1',
                'condition' => 'good',
                'restock' => true,
            ]],
            reason: 'اختبار إرجاع إلى الفرع الأصلي',
        );

        $return = SaleReturn::where('refund_ulid', $refund->refund_ulid)->firstOrFail();
        $this->assertSame($secondLocation->id, (int) $return->location_id);
        $this->assertSame('3.000', (string) ProductStock::where([
            'product_id' => $product->id,
            'location_id' => $secondLocation->id,
        ])->value('on_hand'));
        $this->assertSame('6.000', (string) ProductStock::where([
            'product_id' => $product->id,
            'location_id' => $mainLocation->id,
        ])->value('on_hand'));
        $this->assertSame('9.000', (string) $product->fresh()->quantity);
        $this->assertSame(
            '0',
            bccomp(
                app(StockReservationService::class)->computedReserved(
                    $product->id, $secondLocation->id
                ),
                '0',
                3,
            )
        );
    }

    /** @test */
    public function changing_default_branch_changes_where_new_opening_stock_is_created_without_moving_old_stock(): void
    {
        $main = $this->branches->create($this->merchant, ['name' => 'الرئيسي']);
        $second = $this->branches->create($this->merchant, ['name' => 'فرع ثان']);

        $mainLocation = MerchantLocation::where('branch_id', $main->id)->firstOrFail();
        $secondLocation = MerchantLocation::where('branch_id', $second->id)->firstOrFail();

        $this->branches->setAsDefault($second);

        $this->assertFalse((bool) $mainLocation->fresh()->is_default);
        $this->assertTrue((bool) $secondLocation->fresh()->is_default);

        $product = $this->cashier->addProduct($this->merchant, [
            'name' => 'صنف بعد تغيير الافتراضي',
            'price' => '250',
            'quantity' => '5',
        ]);

        $stock = ProductStock::where('product_id', $product->id)->firstOrFail();
        $this->assertSame($secondLocation->id, (int) $stock->location_id);
        $this->assertSame('5.000', (string) $stock->on_hand);
        $this->assertDatabaseMissing('product_stocks', [
            'product_id' => $product->id,
            'location_id' => $mainLocation->id,
        ]);
    }
}
