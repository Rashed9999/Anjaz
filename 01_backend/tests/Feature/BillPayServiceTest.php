<?php

namespace Tests\Feature;

use App\Models\BillPaymentOrder;
use App\Models\BillProvider;
use App\Models\BillProviderRequest;
use App\Models\BillService;
use App\Models\BillServiceProduct;
use App\Models\EMoney;
use App\Models\User;
use App\Services\BillPay\BillProviderInterface;
use App\Services\BillPay\BillProviderResponse;
use App\Services\BillPayService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

        $this->user = User::factory()->create(['zone_code' => 'SOUTH']);
        EMoney::create(['user_id' => $this->user->id, 'current_balance' => '1000.0000']);

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

        $this->assertEquals('100.0000', (string)$order->amount);
        $this->assertEquals('2.0000', (string)$order->fee);
        $this->assertEquals('102.0000', (string)$order->total_debited);
        $this->assertNotNull($order->completed_at);

        // request مُسجَّل
        $this->assertEquals(1, BillProviderRequest::where('order_id', $order->id)->count());
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

        $this->assertNotNull($order->reversed_at);
        $this->assertNotEmpty($order->reverse_reason);
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

        // المحفظة لا تزال مخصومة
        $wallet = EMoney::where('user_id', $this->user->id)->first();
        $this->assertEquals('898.0000', (string)$wallet->current_balance);
    }

    /** @test */
    public function it_rejects_north_zone_user()
    {
        $north = User::factory()->create(['zone_code' => 'NORTH']);
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
        $this->expectExceptionMessage('Service is not active');

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
            ])
            ->onlyMethods(['resolveProvider'])
            ->getMock();
        $serviceMock->method('resolveProvider')->willReturn($providerMock);

        $order = $serviceMock->createAndExecute(
            $this->user, $this->provider, $this->service_, $this->product,
            '+967700000123', '100.0000',
        );

        $this->assertEquals('failed', $order->status);

        // المحفظة كاملة
        $wallet = EMoney::where('user_id', $this->user->id)->first();
        $this->assertEquals('1000.0000', (string)$wallet->current_balance);

        $this->assertStringContainsString('Network timeout', $order->reverse_reason);
    }
}
