<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\SafePayment;
use App\Models\SafePaymentEvent;
use App\Models\User;
use App\Services\SafePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-SAFE-PAYMENT-001 (v1.1) — Happy path tests.
 *
 * Tests التدفق الكامل:
 *   create → seller_accept → in_delivery → delivered → buyer_confirm → released
 */
class SafePaymentLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private SafePaymentService $service;
    private User $buyer;
    private User $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->service = app(SafePaymentService::class);

        $this->buyer = User::factory()->create([
            'zone_code' => 'SOUTH',
            'phone' => '+967700001001',
        ]);
        EMoney::create(['user_id' => $this->buyer->id, 'current_balance' => '1000.0000']);

        $this->seller = User::factory()->create([
            'zone_code' => 'SOUTH',
            'phone' => '+967700001002',
        ]);
        EMoney::create(['user_id' => $this->seller->id, 'current_balance' => '0.0000']);

        // الـ config للـ fees
        config()->set('amial.safe_payment.fee_percent', '1.0'); // 1%
        config()->set('amial.safe_payment.max_amount', '100000.0000');
    }

    /** @test */
    public function create_and_fund_debits_buyer_and_creates_pending_payment()
    {
        $payment = $this->service->createAndFund(
            buyer: $this->buyer,
            seller: $this->seller,
            title: 'iPhone 14 Pro',
            description: 'New, sealed, 256GB',
            amount: '500.0000',
        );

        $this->assertInstanceOf(SafePayment::class, $payment);
        $this->assertEquals('pending_seller_acceptance', $payment->status);
        $this->assertEquals('500.0000', (string)$payment->amount);
        $this->assertEquals('500.0000', (string)$payment->held_amount);
        $this->assertEquals('0.0000', (string)$payment->platform_fee); // لا fee قبل release

        // المشتري خُصم
        $buyerWallet = EMoney::where('user_id', $this->buyer->id)->first();
        $this->assertEquals('500.0000', (string)$buyerWallet->current_balance); // 1000 - 500

        // البائع لم يستلم بعد
        $sellerWallet = EMoney::where('user_id', $this->seller->id)->first();
        $this->assertEquals('0.0000', (string)$sellerWallet->current_balance);

        // event created
        $this->assertEquals(1, SafePaymentEvent::where('safe_payment_id', $payment->id)->count());
        $this->assertEquals('created', SafePaymentEvent::where('safe_payment_id', $payment->id)->first()->event_type);

        // seller_response_deadline set
        $this->assertNotNull($payment->seller_response_deadline);
        $this->assertTrue($payment->seller_response_deadline->isFuture());
    }

    /** @test */
    public function full_happy_path_create_to_release()
    {
        // Step 1: create
        $payment = $this->service->createAndFund(
            $this->buyer, $this->seller, 'TV', 'Samsung 55"', '300.0000',
        );
        $this->assertEquals('pending_seller_acceptance', $payment->status);

        // Step 2: seller accepts
        $payment = $this->service->sellerAccept($payment, $this->seller, 'Available, will deliver tomorrow');
        $this->assertEquals('funded', $payment->status);
        $this->assertNotNull($payment->seller_accepted_at);

        // Step 3: seller marks in delivery
        $payment = $this->service->sellerMarkInDelivery($payment, $this->seller, 'Shipped via courier');
        $this->assertEquals('in_delivery', $payment->status);

        // Step 4: seller marks delivered
        $payment = $this->service->sellerMarkDelivered($payment, $this->seller);
        $this->assertEquals('delivered', $payment->status);

        // Step 5: buyer confirms → released_to_seller
        $payment = $this->service->buyerConfirm($payment, $this->buyer);
        $this->assertEquals('released_to_seller', $payment->status);
        $this->assertEquals('0.0000', (string)$payment->held_amount);

        // البائع استلم (amount - fee 1%)
        $sellerWallet = EMoney::where('user_id', $this->seller->id)->first();
        $this->assertEquals('297.0000', (string)$sellerWallet->current_balance); // 300 - 3 (1%)

        // Fee مُسجَّل
        $this->assertEquals('3.0000', (string)$payment->platform_fee);
        $this->assertEquals('297.0000', (string)$payment->released_to_seller_amount);

        // المشتري لم يُستعد له شيء
        $buyerWallet = EMoney::where('user_id', $this->buyer->id)->first();
        $this->assertEquals('700.0000', (string)$buyerWallet->current_balance); // 1000 - 300

        // Events log: 5 transitions
        $events = SafePaymentEvent::where('safe_payment_id', $payment->id)->orderBy('id')->get();
        $this->assertCount(6, $events); // created, accepted, in_delivery, delivered, buyer_confirmed, released
    }

    /** @test */
    public function insufficient_balance_prevents_creation()
    {
        $this->expectException(\App\Exceptions\InsufficientBalanceException::class);

        $this->service->createAndFund(
            $this->buyer, $this->seller, 'Car', 'Toyota', '5000.0000', // > 1000
        );

        // لا safe_payment أُنشئ
        $this->assertEquals(0, SafePayment::count());

        // الرصيد سليم
        $buyerWallet = EMoney::where('user_id', $this->buyer->id)->first();
        $this->assertEquals('1000.0000', (string)$buyerWallet->current_balance);
    }

    /** @test */
    public function buyer_and_seller_cannot_be_same()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot be the same');

        $this->service->createAndFund(
            $this->buyer, $this->buyer, 'Test', 'Test', '50.0000',
        );
    }

    /** @test */
    public function non_south_user_blocked()
    {
        $northSeller = User::factory()->create(['zone_code' => 'NORTH']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SOUTH');

        $this->service->createAndFund(
            $this->buyer, $northSeller, 'Test', 'Test', '50.0000',
        );
    }

    /** @test */
    public function max_amount_enforced()
    {
        EMoney::where('user_id', $this->buyer->id)->update(['current_balance' => '200000.0000']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('exceeds maximum');

        $this->service->createAndFund(
            $this->buyer, $this->seller, 'Test', 'Test', '150000.0000',
        );
    }
}
