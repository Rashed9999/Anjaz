<?php

namespace Tests\Feature;

use App\Models\BillPaymentOrder;
use App\Models\BillProvider;
use App\Models\BillProviderRequest;
use App\Models\BillService;
use App\Models\BillServiceProduct;
use App\Models\EMoney;
use App\Models\Receipt;
use App\Models\AmialNotification;
use App\Models\FeeScheme;
use App\Models\User;
use App\Services\BillPay\BillProviderInterface;
use App\Services\BillPay\BillProviderResponse;
use App\Services\BillPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AMIAL-BILL-PAY-001 (v0.9-C) — اختبارات.
 *
 * نستخدم mock provider بدل StubProvider للتحكم بالنتيجة (deterministic).
 */
class BillPayServiceTest extends TestCase
{
    use RefreshDatabase;

    private BillPayService $service;
    private User $user;
    private BillProvider $provider;
    private BillService $service_;
    private BillServiceProduct $product;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->service = app(BillPayService::class);

        $this->user = User::factory()->create([
            'zone_code' => 'SOUTH',
            'kyc_tier' => 2,
            'is_kyc_verified' => 1,
        ]);
        DB::table('residence_verifications')->insert([
            'user_id' => $this->user->id,
            'kyc_document_id' => null,
            'declared_governorate' => 'YE-AD',
            'evidence_type' => 'government_residence_document',
            'evidence_strength' => 'strong',
            'status' => 'verified',
            'submitted_at' => now()->subMinute(),
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        EMoney::create(['user_id' => $this->user->id, 'current_balance' => '1000.0000']);
        FeeScheme::create([
            'code' => 'BILL_PAY', 'label' => 'Bill pay', 'zone_code' => 'SOUTH',
            'applies_to' => 'customer', 'scope_key' => 'all', 'fee_type' => 'fixed',
            'fixed_amount' => '2.0000', 'percent_rate' => '0',
            'agent_commission_percent' => '0', 'agent_commission_fixed' => '0',
            'bearer' => 'sender', 'version' => 1, 'is_active' => true,
        ]);

        $this->provider = BillProvider::create([
            'code' => 'test_provider',
            'name' => 'Test',
            'display_name_ar' => 'تجريبي',
            'integration_type' => 'stub',
            'is_active' => true,
            'zone_code' => 'SOUTH',
        ]);

        $this->service_ = BillService::create([
            'provider_id' => $this->provider->id,
            'code' => 'test_recharge',
            'name' => 'Recharge',
            'display_name_ar' => 'شحن',
            'service_type' => 'recharge',
            'is_active' => true,
            'requires_account_number' => true,
        ]);

        $this->product = BillServiceProduct::create([
            'service_id' => $this->service_->id,
            'product_code' => 'r100',
            'name' => 'شحن 100',
            'amount_type' => 'fixed',
            'fixed_amount' => '100.0000',
            'fee_amount' => '2.0000',
            'fee_percent' => 0,
            'is_active' => true,
        ]);
    }

    /**
     * Helper: Mock الـ BillPayService لاستخدام provider يُعطي نتيجة محددة.
     */
    private function mockProvider(string $resultStatus, array $rawResponse = []): BillPayService
    {
        $mock = $this->createMock(BillProviderInterface::class);
        $response = match ($resultStatus) {
            'success' => BillProviderResponse::success('REF-SUCCESS-001', 'OK', $rawResponse),
            'failed' => BillProviderResponse::failure('Insufficient credit at provider', $rawResponse),
            'pending' => BillProviderResponse::pending('REF-PENDING-001', 'Awaiting', $rawResponse),
            default => throw new \InvalidArgumentException(),
        };
        $mock->method('pay')->willReturn($response);
        $mock->method('checkStatus')->willReturn($response);
        $mock->method('name')->willReturn('mock');

        $serviceMock = $this->getMockBuilder(BillPayService::class)
            ->setConstructorArgs([
                app(\App\Services\FinancialGuardService::class),
                app(\App\Services\AuditService::class),
                app(\App\Services\ReceiptService::class),
                app(\App\Services\FeeService::class),
                app(\App\Services\KycTierService::class),
            ])
            ->onlyMethods(['resolveProvider'])
            ->getMock();
        $serviceMock->method('resolveProvider')->willReturn($mock);

        return $serviceMock;
    }

    /** @test */
    public function successful_payment_debits_user_and_marks_success()
    {
        $service = $this->mockProvider('success');

        $order = $service->createAndExecute(
            $this->user, $this->provider, $this->service_, $this->product,
            '+967700000123', '100.0000',
        );

        $this->assertEquals('success', $order->status);
        $this->assertEquals('REF-SUCCESS-001', $order->provider_reference);

        // المحفظة خُصمت بـ amount + fee
        $wallet = EMoney::where('user_id', $this->user->id)->first();
        $this->assertEquals('898.0000', (string)$wallet->current_balance); // 1000 - 102
        $this->assertEquals('0.0000', (string)$wallet->held_balance);

        $this->assertEquals('100.0000', (string)$order->amount);
        $this->assertEquals('2.0000', (string)$order->fee);
        $this->assertEquals('102.0000', (string)$order->total_debited);
        $this->assertNotNull($order->completed_at);

        // request مُسجَّل
        $this->assertEquals(1, BillProviderRequest::where('order_id', $order->id)->count());

        $receipt = Receipt::where('reference_transaction_id', $order->order_ulid)->first();
        $this->assertNotNull($receipt);
        $this->assertSame('bill_payment', $receipt->receipt_type);
        $this->assertSame('bill_payment_order', $receipt->reference_type);
        $this->assertSame($order->id, $receipt->reference_id);

        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $this->user->id,
            'type' => 'bill_payment_success',
        ]);
    }

    /** @test */
    public function failed_payment_refunds_the_user_completely()
    {
        $service = $this->mockProvider('failed');

        $order = $service->createAndExecute(
            $this->user, $this->provider, $this->service_, $this->product,
            '+967700000123', '100.0000',
        );

        $this->assertEquals('failed', $order->status);

        // المحفظة عادت لقيمتها الأصلية (تماماً)
        $wallet = EMoney::where('user_id', $this->user->id)->first();
        $this->assertEquals('1000.0000', (string)$wallet->current_balance);
        $this->assertEquals('0.0000', (string)$wallet->held_balance);

        $this->assertNotNull($order->reversed_at);
        $this->assertNotEmpty($order->reverse_reason);
        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $this->user->id,
            'type' => 'bill_payment_failed',
        ]);
    }

    /** @test */
    public function pending_payment_keeps_money_debited_until_reconcile()
    {
        $service = $this->mockProvider('pending');

        $order = $service->createAndExecute(
            $this->user, $this->provider, $this->service_, $this->product,
            '+967700000123', '100.0000',
        );

        $this->assertEquals('pending_provider_confirmation', $order->status);
        $this->assertEquals('REF-PENDING-001', $order->provider_reference);

        // المبلغ محجوز لا مفقود: current ينخفض وheld يرتفع.
        $wallet = EMoney::where('user_id', $this->user->id)->first();
        $this->assertEquals('898.0000', (string)$wallet->current_balance);
        $this->assertEquals('102.0000', (string)$wallet->held_balance);
        $this->assertDatabaseHas('amial_notifications', [
            'user_id' => $this->user->id,
            'type' => 'bill_payment_pending',
        ]);
    }

    /** @test */
    public function it_rejects_north_zone_user()
    {
        // اختبر النطاق بعد اجتياز KYC، لا أن يسقط الاختبار في باب KYC أولاً.
        $north = User::factory()->create([
            'type' => 2,
            'zone_code' => 'NORTH',
            'kyc_tier' => 2,
            'is_phone_verified' => 1,
            'is_kyc_verified' => 1,
            'residence_governorate' => 'YE-AD',
            'verified_residence_governorate' => 'YE-AD',
            'residence_verified_at' => now(),
        ]);
        DB::table('residence_verifications')->insert([
            'user_id' => $north->id,
            'kyc_document_id' => null,
            'declared_governorate' => 'YE-AD',
            'evidence_type' => 'government_residence_document',
            'evidence_strength' => 'strong',
            'status' => 'verified',
            'submitted_at' => now()->subMinute(),
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        EMoney::create(['user_id' => $north->id, 'current_balance' => '1000.0000']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SOUTH');

        $this->service->createAndExecute(
            $north, $this->provider, $this->service_, $this->product,
            '+967700000123', '100.0000',
        );
    }

    /** @test */
    public function it_rejects_inactive_service()
    {
        $this->service_->update(['is_active' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('خدمة دفع الفواتير غير متاحة حالياً');

        $this->service->createAndExecute(
            $this->user, $this->provider, $this->service_, $this->product,
            '+967700000123', '100.0000',
        );
    }

    /** @test */
    public function reconcile_pending_resolves_to_success()
    {
        $service = $this->mockProvider('pending');

        $order = $service->createAndExecute(
            $this->user, $this->provider, $this->service_, $this->product,
            '+967700000123', '100.0000',
        );

        $this->assertEquals('pending_provider_confirmation', $order->status);

        // الآن نُغير الـ mock ليرد success عند الـ status check
        $reconcileService = $this->mockProvider('success');

        $reconcileService->reconcilePendingOrder($order->fresh());

        $order->refresh();
        $this->assertEquals('success', $order->status);

        // request_type جديد مُسجَّل
        $this->assertEquals(2, BillProviderRequest::where('order_id', $order->id)->count());
    }

    /** @test */
    public function provider_exception_triggers_refund()
    {
        $providerMock = $this->createMock(BillProviderInterface::class);
        $providerMock->method('pay')->willThrowException(new \RuntimeException('Network timeout'));
        $providerMock->method('name')->willReturn('mock');

        $serviceMock = $this->getMockBuilder(BillPayService::class)
            ->setConstructorArgs([
                app(\App\Services\FinancialGuardService::class),
                app(\App\Services\AuditService::class),
                app(\App\Services\ReceiptService::class),
                app(\App\Services\FeeService::class),
                app(\App\Services\KycTierService::class),
            ])
            ->onlyMethods(['resolveProvider'])
            ->getMock();
        $serviceMock->method('resolveProvider')->willReturn($providerMock);

        $order = $serviceMock->createAndExecute(
            $this->user, $this->provider, $this->service_, $this->product,
            '+967700000123', '100.0000',
        );

        $this->assertEquals('pending_provider_confirmation', $order->status);

        // المحفظة كاملة
        $wallet = EMoney::where('user_id', $this->user->id)->first();
        $this->assertEquals('898.0000', (string)$wallet->current_balance);
        $this->assertEquals('102.0000', (string)$wallet->held_balance);
        $this->assertStringContainsString('تعذّر تأكيد نتيجة المزود', (string) $order->provider_message);
        $this->assertStringNotContainsString('Network timeout', (string) $order->provider_message);
    }

    /** @test */
    public function repeated_idempotency_key_does_not_create_or_submit_a_second_order()
    {
        $service = $this->mockProvider('pending');
        $key = 'bill-replay-key-001';

        $first = $service->createAndExecute(
            $this->user, $this->provider, $this->service_, $this->product,
            '+967700000123', '100.0000', [], $key,
        );
        $second = $service->createAndExecute(
            $this->user, $this->provider, $this->service_, $this->product,
            '+967700000123', '100.0000', [], $key,
        );

        $this->assertSame($first->order_ulid, $second->order_ulid);
        $this->assertSame(1, BillPaymentOrder::where('user_id', $this->user->id)->count());
        $this->assertSame(1, BillProviderRequest::where('order_id', $first->id)->where('request_type', 'pay')->count());
        $this->assertSame('102.0000', (string) EMoney::where('user_id', $this->user->id)->first()->held_balance);
    }
}
