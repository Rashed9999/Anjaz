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
 * AMIAL-SAFE-PAYMENT-001 (v1.1) — Append-only events.
 *
 * تأكد من أن:
 *   1. كل state transition يُسجَّل في events
 *   2. الـ events ممنوع تعديلها/حذفها
 *   3. الـ event يحتوي actor + from/to states
 */
class SafePaymentAppendOnlyEventsTest extends TestCase
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

        $this->buyer = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700004001']);
        EMoney::create(['user_id' => $this->buyer->id, 'current_balance' => '1000.0000']);

        $this->seller = User::factory()->create(['zone_code' => 'SOUTH', 'phone' => '+967700004002']);
        EMoney::create(['user_id' => $this->seller->id, 'current_balance' => '0.0000']);
    }

    /** @test */
    public function every_state_transition_creates_an_event()
    {
        $payment = $this->service->createAndFund($this->buyer, $this->seller, 'X', 'Y', '100.0000');
        $this->assertEvents($payment, ['created']);

        $payment = $this->service->sellerAccept($payment, $this->seller);
        $this->assertEvents($payment, ['created', 'seller_accepted']);

        $payment = $this->service->sellerMarkInDelivery($payment, $this->seller);
        $this->assertEvents($payment, ['created', 'seller_accepted', 'in_delivery_marked']);

        $payment = $this->service->sellerMarkDelivered($payment, $this->seller);
        $this->assertEvents($payment, ['created', 'seller_accepted', 'in_delivery_marked', 'delivered_marked']);

        $payment = $this->service->buyerConfirm($payment, $this->buyer);
        $this->assertEvents($payment, [
            'created', 'seller_accepted', 'in_delivery_marked',
            'delivered_marked', 'buyer_confirmed', 'released_to_seller',
        ]);
    }

    /** @test */
    public function event_records_from_and_to_status()
    {
        $payment = $this->service->createAndFund($this->buyer, $this->seller, 'X', 'Y', '50.0000');
        $payment = $this->service->sellerAccept($payment, $this->seller);

        $event = SafePaymentEvent::where('safe_payment_id', $payment->id)
            ->where('event_type', 'seller_accepted')
            ->first();

        $this->assertEquals('pending_seller_acceptance', $event->from_status);
        $this->assertEquals('funded', $event->to_status);
        $this->assertEquals('seller', $event->actor_type);
        $this->assertEquals($this->seller->id, $event->actor_user_id);
    }

    /** @test */
    public function events_cannot_be_updated()
    {
        $payment = $this->service->createAndFund($this->buyer, $this->seller, 'X', 'Y', '50.0000');
        $event = SafePaymentEvent::where('safe_payment_id', $payment->id)->first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $event->update(['note' => 'tampered']);
    }

    /** @test */
    public function events_cannot_be_deleted()
    {
        $payment = $this->service->createAndFund($this->buyer, $this->seller, 'X', 'Y', '50.0000');
        $event = SafePaymentEvent::where('safe_payment_id', $payment->id)->first();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('append-only');

        $event->delete();
    }

    /** @test */
    public function events_for_dispute_include_buyer_attachments_metadata()
    {
        $payment = $this->service->createAndFund($this->buyer, $this->seller, 'X', 'Y', '100.0000');
        $payment = $this->service->sellerAccept($payment, $this->seller);

        $payment = $this->service->buyerDispute(
            $payment, $this->buyer,
            'Detailed dispute reason with proof attached for admin review',
            ['photo1.jpg', 'chat_log.pdf'],
        );

        $event = SafePaymentEvent::where('safe_payment_id', $payment->id)
            ->where('event_type', 'buyer_disputed')
            ->first();

        $this->assertNotNull($event);
        $this->assertEquals(['photo1.jpg', 'chat_log.pdf'], $event->attachments);
        $this->assertStringContainsString('Detailed dispute', $event->note);
    }

    private function assertEvents(SafePayment $payment, array $expectedTypes): void
    {
        $actualTypes = SafePaymentEvent::where('safe_payment_id', $payment->id)
            ->orderBy('id')
            ->pluck('event_type')
            ->toArray();

        $this->assertEquals($expectedTypes, $actualTypes);
    }
}
