<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\RestaurantOrder;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Services\RestaurantService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AMIAL-RESTAURANT-001 — طاولات + طلبات + مطبخ. */
class RestaurantTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private RestaurantService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'business_type' => A::BIZ_RESTAURANT,
            'verification_status' => 'verified', 'subscription_plan' => A::PLAN_FREE]);
        $this->svc = app(RestaurantService::class);
    }

    /** @test فتح طلب على طاولة يشغلها ويحسب المجموع. */
    public function opening_order_occupies_table(): void
    {
        $table = $this->svc->createTable($this->merchant, 'طاولة 1', 4);
        $this->assertSame('free', $table->status);

        $order = $this->svc->openOrder($this->merchant, $table->id, [
            ['name' => 'برجر', 'qty' => 2, 'price' => 1500],
            ['name' => 'عصير', 'qty' => 1, 'price' => 1000],
        ], 'بلا بصل', $this->merchant->id);

        $this->assertSame('open', $order->status);
        $this->assertSame('4000.00', (string) $order->total); // 2*1500 + 1000
        $this->assertSame('occupied', RestaurantTable::find($table->id)->status);
    }

    /** @test تدفّق المطبخ: تحضير ثم جاهز ثم يظهر في شاشة المطبخ. */
    public function kitchen_flow_advances_status(): void
    {
        $order = $this->svc->openOrder($this->merchant, null, [['name' => 'بيتزا', 'qty' => 1, 'price' => 3000]], null, null);
        $this->svc->setStatus($this->merchant, $order, 'preparing');
        $this->assertSame('preparing', RestaurantOrder::find($order->id)->status);

        $this->svc->setStatus($this->merchant, $order, 'ready');
        $kitchen = $this->svc->kitchenOrders($this->merchant);
        $this->assertCount(1, $kitchen);
    }

    /** @test إغلاق الطلب يسجّل بيعاً حقيقياً ويحرّر الطاولة. */
    public function closing_order_records_sale_and_frees_table(): void
    {
        $table = $this->svc->createTable($this->merchant, 'طاولة 2', 2);
        $order = $this->svc->openOrder($this->merchant, $table->id,
            [['name' => 'شاورما', 'qty' => 3, 'price' => 1200]], null, null);

        $res = $this->svc->closeOrder($this->merchant, $order, 'cash');

        $this->assertSame('closed', $res['order']->status);
        $this->assertNotNull($res['order']->sale_ulid);
        $this->assertSame('free', RestaurantTable::find($table->id)->status);

        // بيع حقيقي مُسجَّل بالإجمالي الصحيح
        $sale = MerchantSale::where('merchant_user_id', $this->merchant->id)->first();
        $this->assertNotNull($sale);
        $this->assertSame('3600.0000', (string) $sale->total_amount); // 3*1200
    }

    /** @test لا يمكن إغلاق طلب مرّتين. */
    public function cannot_close_twice(): void
    {
        $order = $this->svc->openOrder($this->merchant, null, [['name' => 'كبسة', 'qty' => 1, 'price' => 5000]], null, null);
        $this->svc->closeOrder($this->merchant, $order, 'cash');

        $this->expectException(\RuntimeException::class);
        $this->svc->closeOrder($this->merchant, $order->fresh(), 'cash');
    }
}
