<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\SafePayment;
use App\Models\User;
use App\Services\SafePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-SAFE-PAYMENT-001 (v1.1) — Refund paths.
 *
 * كل refund path يضمن أن المشتري يستعيد ماله كاملاً.
 */
class SafePaymentRefundsTest extends TestCase
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
        config()->set('amial.safe_payment.fee_percent', '1.0');

        $this->buyer = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700002001']);
        EMoney::create(['user_id' => $this->buyer->id, 'current_balance' => '1000.0000']);

        $this->seller = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700002002']);
        EMoney::create(['user_id' => $this->seller->id, 'current_balance' => '0.0000']);
    }

    /** @test */
    public function seller_reject_refunds_buyer_fully()
    {
        $payment = $this->service->createAndFund($this->buyer, $this->seller, 'Item', 'Desc', '200.0000');

        $this->assertBalance($this->buyer, '800.0000'); // 1000-200

        $payment = $this->service->sellerReject($payment, $this->seller, 'Item not available');

        $this->assertEquals('seller_rejected', $payment->status);
        $this->assertEquals('0.0000', (string)$payment->held_amount);
        $this->assertEquals('200.0000', (string)$payment->refunded_to_buyer_amount);

        // المشتري استعاد كل ماله
        $this->assertBalance($this->buyer, '1000.0000');

        // البائع لم يستلم شيء
        $this->assertBalance($this->seller, '0.0000');
    }

    /** @test */
    public function buyer_cancel_before_in_delivery_refunds_fully()
    {
        $payment = $this->service->createAndFund($this->buyer, $this->seller, 'Item', 'Desc', '100.0000');
        $payment = $this->service->sellerAccept($payment, $this->seller);

        // funded → cancel
        $payment = $this->service->buyerCancel($payment, $this->buyer, 'Changed my mind');

        $this->assertEquals('cancelled', $payment->status);
        $this->assertBalance($this->buyer, '1000.0000'); // كامل
    }

    /** @test */
    public function buyer_cannot_cancel_after_in_delivery()
    {
        $payment = $this->service->createAndFund($this->buyer, $this->seller, 'Item', 'Desc', '100.0000');
        $payment = $this->service->sellerAccept($payment, $this->seller);
        $payment = $this->service->sellerMarkInDelivery($payment, $this->seller);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot cancel');
        $this->service->buyerCancel($payment, $this->buyer, 'Too late');
    }

    /** @test */
    public function seller_cannot_accept_already_funded()
    {
        $payment = $this->service->createAndFund($this->buyer, $this->seller, 'Item', 'Desc', '100.0000');
        $this->service->sellerAccept($payment, $this->seller);

        $this->expectException(\RuntimeException::class);
        $this->service->sellerAccept($payment->fresh(), $this->seller);
    }

    /** @test */
    public function expired_payment_refunds_buyer()
    {
        $payment = $this->service->createAndFund($this->buyer, $this->seller, 'Item', 'Desc', '150.0000');

        // simulate expiry
        $payment->update(['seller_response_deadline' => now()->subHour()]);

        $payment = $this->service->expireUnresponsive($payment->fresh());

        $this->assertEquals('expired', $payment->status);
        $this->assertBalance($this->buyer, '1000.0000'); // refunded
    }

    /** @test */
    public function expire_does_nothing_if_deadline_not_passed()
    {
        $payment = $this->service->createAndFund($this->buyer, $this->seller, 'Item', 'Desc', '50.0000');

        // deadline في المستقبل
        $payment = $this->service->expireUnresponsive($payment);

        $this->assertEquals('pending_seller_acceptance', $payment->status); // لم يتغير
        $this->assertBalance($this->buyer, '950.0000'); // ما زال مخصوم
    }

    /** @test */
    public function expire_only_works_for_pending_seller_acceptance()
    {
        $payment = $this->service->createAndFund($this->buyer, $this->seller, 'Item', 'Desc', '50.0000');
        $payment = $this->service->sellerAccept($payment, $this->seller);

        // الـ deadline في الماضي، لكن الحالة funded ⇒ لا expiration
        $payment->update(['seller_response_deadline' => now()->subDay()]);
        $payment = $this->service->expireUnresponsive($payment->fresh());

        $this->assertEquals('funded', $payment->status); // لم يتغير
    }

    private function assertBalance(User $user, string $expected): void
    {
        $balance = (string)EMoney::where('user_id', $user->id)->value('current_balance');
        $this->assertEquals($expected, $balance,
            "Expected {$user->phone} balance {$expected}, got {$balance}");
    }
}
