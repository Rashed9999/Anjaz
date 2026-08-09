<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\FuelStationService;
use App\Services\PaymentRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-FUEL-QR-001 — مسار الدفع بـ QR (طلب دفع فوري) كاملاً:
 *   التاجر ينشئ طلب دفع بمبلغ ثابت → العميل يمسح ويدفع → يُسجَّل بيع الوقود
 *   مربوطاً بمرجع الدفع. دفع حقيقي يحرّك المال (قيود مزدوجة).
 */
class FuelQrPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private FuelStationService $fuel;
    private PaymentRequestService $pr;
    private User $merchant;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fuel = app(FuelStationService::class);
        $this->pr = app(PaymentRequestService::class);

        $this->merchant = User::factory()->create([
            'type' => 3, 'zone_code' => 'SOUTH', 'phone' => '+967777200004',
        ]);
        MerchantProfile::create([
            'user_id' => $this->merchant->id, 'verification_status' => 'verified',
        ]);
        EMoney::create([
            'user_id' => $this->merchant->id, 'current_balance' => '0.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        $this->customer = User::factory()->create([
            'type' => 2, 'zone_code' => 'SOUTH', 'phone' => '+967771700001',
        ]);
        EMoney::create([
            'user_id' => $this->customer->id, 'current_balance' => '50000.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);
    }

    /** @test التاجر ينشئ طلباً → العميل يدفعه → البيع يُسجَّل بمرجع الدفع. */
    public function customer_scans_and_pays_then_fuel_sale_is_recorded(): void
    {
        $station = $this->fuel->getOrCreateStation($this->merchant, ['station_name' => 'محطة الأمل']);
        $pump = $this->fuel->addPump($station, ['pump_number' => 1]);
        $product = $this->fuel->addProduct($station, ['name' => 'بنزين', 'price_per_liter' => '500']);

        // البيعُ يشترط ورديّةً مفتوحة — AMIAL-FUEL-VERTICAL-001 المرحلة ٠.
        app(\App\Services\FuelShiftService::class)
            ->openShift($station, $this->merchant, '0');

        // 1) التاجر ينشئ طلب دفع بمبلغ ثابت (كما يفعل كاشير الوقود لعرض QR)
        $request = $this->pr->create(
            requester: $this->merchant,
            amount: '10000',
            note: 'دفع وقود — بنزين',
            shareMethod: 'qr',
        );
        $this->assertSame('pending', $request->status);
        $this->assertNotEmpty($request->short_code);

        // 2) العميل يمسح الرمز ويدفع (يُخصم منه ويُضاف للتاجر)
        $result = $this->pr->pay($this->customer, $request);
        $paidTxId = $result['transaction_id'];
        $this->assertNotEmpty($paidTxId);

        $this->assertSame('40000.0000',
            (string) EMoney::where('user_id', $this->customer->id)->value('current_balance'));
        $this->assertSame('10000.0000',
            (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance'));

        // 3) الكاشير يسجّل البيع مربوطاً بمرجع الدفع الذي نفّذه العميل
        $sale = $this->fuel->recordSale($this->merchant, null, [
            'pump_id' => $pump->id,
            'fuel_product_id' => $product->id,
            'sale_type' => 'by_amount',
            'amount' => '10000',
            'payment_method' => 'amial_pay',
            'paid_transaction_id' => $paidTxId,
        ]);

        $this->assertSame('completed', $sale->status);
        $this->assertSame($paidTxId, $sale->paid_transaction_id);
        $this->assertSame('amial_pay', $sale->payment_method);
        $this->assertEquals(20.0, (float) $sale->liters); // 10000 / 500
    }

    /** @test الطلب المدفوع لا يُدفع مرّتين (حماية من الازدواج). */
    public function a_paid_request_cannot_be_paid_twice(): void
    {
        $request = $this->pr->create(requester: $this->merchant, amount: '5000', shareMethod: 'qr');
        $this->pr->pay($this->customer, $request);

        $this->expectException(\RuntimeException::class);
        $this->pr->pay($this->customer, $request->fresh());
    }

    /** @test لا يستطيع التاجر دفع طلبه بنفسه. */
    public function requester_cannot_pay_own_request(): void
    {
        $request = $this->pr->create(requester: $this->merchant, amount: '5000', shareMethod: 'qr');
        $this->expectException(\InvalidArgumentException::class);
        $this->pr->pay($this->merchant, $request);
    }
}
