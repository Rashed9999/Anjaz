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
 * AMIAL-SAFE-PAYMENT-001 (v1.1) — Disputes & admin resolutions.
 *
 * 3 paths للحل:
 *   - admin_release: المال للبائع
 *   - admin_refund: استرداد كامل للمشتري
 *   - admin_partial: تقسيم بين الطرفين
 */
class SafePaymentDisputeTest extends TestCase
{
    use RefreshDatabase;

    private SafePaymentService $service;
    private User $buyer;
    private User $seller;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->service = app(SafePaymentService::class);
        config()->set('amial.safe_payment.fee_percent', '1.0');

        $this->buyer = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700003001']);
        EMoney::create(['user_id' => $this->buyer->id, 'current_balance' => '1000.0000']);

        $this->seller = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700003002']);
        EMoney::create(['user_id' => $this->seller->id, 'current_balance' => '0.0000']);

        $this->admin = User::factory()->create(['zone_code' => 'SOUTH']);
    }

    /** @test */
    public function buyer_can_dispute_at_funded_state()
    {
        $payment = $this->createAndAccept('400.0000');
        $payment = $this->service->buyerDispute($payment, $this->buyer, 'Item never shipped despite seller agreement');

        $this->assertEquals('disputed', $payment->status);
        $this->assertTrue($payment->is_disputed);
        $this->assertNotNull($payment->disputed_at);

        // المال ما زال محجوز
        $this->assertEquals('400.0000', (string)$payment->held_amount);

        // البائع لم يستلم
        $this->assertBalance($this->seller, '0.0000');
    }

    /** @test */
    public function buyer_can_dispute_at_delivered_state()
    {
        $payment = $this->createAndAccept('300.0000');
        $payment = $this->service->sellerMarkInDelivery($payment, $this->seller);
        $payment = $this->service->sellerMarkDelivered($payment, $this->seller);

        // delivered, لكن المشتري يقول لم يستلم
        $payment = $this->service->buyerDispute($payment, $this->buyer, 'Tracking shows delivered but I have not received anything');

        $this->assertEquals('disputed', $payment->status);
        $this->assertTrue($payment->is_disputed);
    }

    /** @test */
    public function dispute_reason_must_be_minimum_10_chars()
    {
        $payment = $this->createAndAccept('100.0000');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least 10');

        $this->service->buyerDispute($payment, $this->buyer, 'short');
    }

    /** @test */
    public function admin_release_transfers_money_to_seller()
    {
        $payment = $this->createAndAccept('500.0000');
        $payment = $this->service->buyerDispute($payment, $this->buyer, 'Item was not as described initially');

        $payment = $this->service->adminResolveRelease($payment, $this->admin, 'After review, seller delivered correctly');

        $this->assertEquals('released_to_seller', $payment->status);
        $this->assertNotNull($payment->admin_resolved_at);
        $this->assertEquals($this->admin->id, $payment->admin_resolved_by);

        // البائع استلم amount - 1% fee
        $this->assertBalance($this->seller, '495.0000'); // 500 - 5

        // المشتري لا يستعيد
        $this->assertBalance($this->buyer, '500.0000'); // 1000 - 500
    }

    /** @test */
    public function admin_refund_returns_all_to_buyer()
    {
        $payment = $this->createAndAccept('600.0000');
        $payment = $this->service->buyerDispute($payment, $this->buyer, 'Item never shipped at all');

        $payment = $this->service->adminResolveRefund($payment, $this->admin, 'Seller did not deliver');

        $this->assertEquals('refunded_to_buyer', $payment->status);

        // المشتري استعاد كل ماله
        $this->assertBalance($this->buyer, '1000.0000');

        // البائع لم يستلم شيء
        $this->assertBalance($this->seller, '0.0000');

        $this->assertEquals('600.0000', (string)$payment->refunded_to_buyer_amount);
    }

    /** @test */
    public function admin_partial_splits_between_buyer_and_seller()
    {
        $payment = $this->createAndAccept('1000.0000');
        EMoney::where('user_id', $this->buyer->id)->update(['current_balance' => '0.0000']); // adjust for clarity

        $payment = $this->service->buyerDispute($payment, $this->buyer, 'Item partially defective');

        // الإدارة تقرر: 400 للمشتري، 600 للبائع (يطبق fee 1% على الـ 600)
        $payment = $this->service->adminResolvePartial(
            $payment, $this->admin, '400.0000', 'Item had minor issues',
        );

        $this->assertEquals('partially_refunded', $payment->status);

        // المشتري: استلم 400
        $this->assertBalance($this->buyer, '400.0000');

        // البائع: استلم 594 (600 - 6 fee)
        $this->assertBalance($this->seller, '594.0000');

        // ledger fields صحيحة
        $this->assertEquals('400.0000', (string)$payment->refunded_to_buyer_amount);
        $this->assertEquals('594.0000', (string)$payment->released_to_seller_amount);
        $this->assertEquals('6.0000', (string)$payment->platform_fee);
        $this->assertEquals('0.0000', (string)$payment->held_amount);
    }

    /** @test */
    public function admin_partial_can_be_all_to_buyer()
    {
        $payment = $this->createAndAccept('500.0000');
        $payment = $this->service->buyerDispute($payment, $this->buyer, 'Refund all please');

        $payment = $this->service->adminResolvePartial(
            $payment, $this->admin, '500.0000', '100% buyer favor',
        );

        $this->assertEquals('partially_refunded', $payment->status);
        $this->assertBalance($this->buyer, '1000.0000'); // full
        $this->assertBalance($this->seller, '0.0000');
    }

    /** @test */
    public function admin_partial_rejects_amount_exceeding_held()
    {
        $payment = $this->createAndAccept('200.0000');
        $payment = $this->service->buyerDispute($payment, $this->buyer, 'Some reason here');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot exceed');

        $this->service->adminResolvePartial($payment, $this->admin, '300.0000', 'Too much');
    }

    /** @test */
    public function cannot_dispute_already_disputed()
    {
        $payment = $this->createAndAccept('100.0000');
        $this->service->buyerDispute($payment, $this->buyer, 'First dispute reason here');

        $this->expectException(\RuntimeException::class);
        $this->service->buyerDispute($payment->fresh(), $this->buyer, 'Second attempt should fail');
    }

    /** @test */
    public function admin_cannot_resolve_non_disputed()
    {
        $payment = $this->createAndAccept('100.0000');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disputed');

        $this->service->adminResolveRelease($payment, $this->admin, 'should not work');
    }

    // ============================================================
    private function createAndAccept(string $amount): SafePayment
    {
        $payment = $this->service->createAndFund(
            $this->buyer, $this->seller, 'Test Item', 'Description', $amount,
        );
        return $this->service->sellerAccept($payment, $this->seller);
    }

    private function assertBalance(User $user, string $expected): void
    {
        $balance = (string)EMoney::where('user_id', $user->id)->value('current_balance');
        $this->assertEquals($expected, $balance);
    }
}
