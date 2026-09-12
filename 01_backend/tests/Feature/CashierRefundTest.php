<?php

namespace Tests\Feature;

use App\Models\CustomerCreditAccount;
use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\MerchantRefund;
use App\Models\MerchantSale;
use App\Models\AmialNotification;
use App\Models\User;
use App\Services\CashierService;
use App\Services\MerchantSaleRefundService;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-CASHIER-REFUND-001 — اختبارات المرتجعات.
 *
 * تغطّي الحالات الثلاث:
 *   1. نقدي (cash) — لا حركة مالية، فقط سجل.
 *   2. محفظة (wallet) — خصم من التاجر + ائتمان للعميل.
 *   3. حساب دَيْن (credit_account) — تخفيض الدَّيْن.
 */
class CashierRefundTest extends TestCase
{
    use RefreshDatabase;

    private MerchantSaleRefundService $svc;
    private CashierService $cashier;
    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(MerchantSaleRefundService::class);
        $this->cashier = app(CashierService::class);

        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'verification_status' => 'verified']);
        EMoney::create([
            'user_id' => $this->merchant->id,
            'current_balance' => '500000.0000',
            'held_balance' => '0.0000',
            'pending_balance' => '0.0000',
            'charge_earned' => '0.0000',
            'zone_code' => 'SOUTH',
        ]);
    }

    // ==================== استرداد نقدي ====================

    /** @test */
    public function cash_refund_creates_record_without_wallet_movement(): void
    {
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '5000',
            paymentMethod: 'cash',
            items: [['name' => 'منتج', 'qty' => 1, 'price' => '5000']],
        );

        $balanceBefore = (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance');

        $refund = $this->svc->refund(
            merchant: $this->merchant,
            originalSaleUlid: $sale->sale_ulid,
            refundAmount: '2000',
            refundMethod: 'cash',
            reason: 'منتج تالف',
        );

        $this->assertSame('completed', $refund->status);
        $this->assertSame('cash', $refund->refund_method);
        $this->assertSame(MoneyService::normalize('2000'), (string)$refund->refund_amount);

        // لا حركة على محفظة التاجر (النقد خارج النظام)
        $balanceAfter = (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance');
        $this->assertSame($balanceBefore, $balanceAfter);
    }

    // ==================== استرداد للمحفظة ====================

    /** @test */
    public function wallet_refund_debits_merchant_credits_customer(): void
    {
        // عميل مسجّل
        $customer = User::factory()->create(['type' => 2, 'phone' => '+967700111']);
        EMoney::create([
            'user_id' => $customer->id, 'current_balance' => '0.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        // مرجعُ الدفع طلبُ QR مدفوعٌ حقيقيّ، لا نصٌّ يدّعي الدفع.
        $this->paidQrRequest($this->merchant, 'TX12345', '3000');
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '3000',
            paymentMethod: 'amial_pay',
            customer: ['name' => 'محمد', 'phone' => '+967700111'],
            paidTransactionId: 'TX12345',
        );

        $merchantBefore = (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance');
        $customerBefore = (string) EMoney::where('user_id', $customer->id)->value('current_balance');

        $refund = $this->svc->refund(
            merchant: $this->merchant,
            originalSaleUlid: $sale->sale_ulid,
            refundAmount: '1000',
            refundMethod: 'wallet',
        );

        $this->assertSame('completed', $refund->status);
        $this->assertSame($customer->id, $refund->customer_user_id);

        // التاجر -1000، العميل +1000
        $merchantAfter = (string) EMoney::where('user_id', $this->merchant->id)->value('current_balance');
        $customerAfter = (string) EMoney::where('user_id', $customer->id)->value('current_balance');
        $this->assertSame(MoneyService::sub($merchantBefore, '1000'), MoneyService::normalize($merchantAfter));
        $this->assertSame(MoneyService::add($customerBefore, '1000'), MoneyService::normalize($customerAfter));
    }

    /** @test */
    public function wallet_refund_rejects_unregistered_customer(): void
    {
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '1000',
            paymentMethod: 'cash',
            items: [['name' => 'x', 'qty' => 1, 'price' => '1000']],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('عميلاً مسجّلاً');
        $this->svc->refund(
            merchant: $this->merchant,
            originalSaleUlid: $sale->sale_ulid,
            refundAmount: '500',
            refundMethod: 'wallet', // العميل غير مسجّل، يجب رفض
        );
    }

    // ==================== استرداد لحساب الدَّيْن ====================

    /** @test */
    public function credit_account_refund_reduces_debt(): void
    {
        // بيع آجل → ينشئ حساب ديون
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '10000',
            paymentMethod: 'credit',
            customer: ['name' => 'سالم', 'phone' => '+967700222'],
            creditDueDate: '2026-07-01',
        );

        $account = CustomerCreditAccount::where('merchant_user_id', $this->merchant->id)
            ->where('customer_phone', '+967700222')->first();
        $this->assertNotNull($account);
        $this->assertSame(MoneyService::normalize('10000'), (string)$account->current_balance);

        // مرتجع 3000 على الدَّيْن
        $refund = $this->svc->refund(
            merchant: $this->merchant,
            originalSaleUlid: $sale->sale_ulid,
            refundAmount: '3000',
            refundMethod: 'credit_account',
            reason: 'إرجاع منتج',
        );

        $this->assertSame('completed', $refund->status);
        $this->assertSame($account->id, $refund->credit_account_id);

        // الدَّيْن نزل لـ 7000
        $account->refresh();
        $this->assertSame(MoneyService::normalize('7000'), (string)$account->current_balance);
    }

    /** @test */
    public function credit_account_refund_rejects_non_credit_sale(): void
    {
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '1000',
            paymentMethod: 'cash',
            items: [['name' => 'x', 'qty' => 1, 'price' => '1000']],
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->refund(
            merchant: $this->merchant,
            originalSaleUlid: $sale->sale_ulid,
            refundAmount: '500',
            refundMethod: 'credit_account', // البيع نقدي → يجب رفض
        );
    }

    // ==================== الحدود والقواعد ====================

    /** @test */
    public function refund_cannot_exceed_original_amount(): void
    {
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '1000',
            paymentMethod: 'cash',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('أكبر من المتبقّي');
        $this->svc->refund(
            merchant: $this->merchant,
            originalSaleUlid: $sale->sale_ulid,
            refundAmount: '2000',
            refundMethod: 'cash',
        );
    }

    /** @test */
    public function multiple_partial_refunds_cannot_exceed_total(): void
    {
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '1000',
            paymentMethod: 'cash',
        );

        // أوّل: 400 ✓
        $this->svc->refund($this->merchant, $sale->sale_ulid, '400', 'cash');
        // ثاني: 500 ✓ (مجموع 900)
        $this->svc->refund($this->merchant, $sale->sale_ulid, '500', 'cash');

        // ثالث: 200 → يتجاوز (مجموع سيكون 1100)
        $this->expectException(\RuntimeException::class);
        $this->svc->refund($this->merchant, $sale->sale_ulid, '200', 'cash');
    }

    /** @test */
    public function refund_above_threshold_needs_approval(): void
    {
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '10000',
            paymentMethod: 'cash',
        );

        $refund = $this->svc->refund(
            merchant: $this->merchant,
            originalSaleUlid: $sale->sale_ulid,
            refundAmount: '7000', // > 5000
            refundMethod: 'cash',
        );

        $this->assertSame('pending_approval', $refund->status);
    }

    /** @test */
    public function admin_can_approve_pending_refund(): void
    {
        $customer = User::factory()->create(['type' => 2, 'phone' => '+967700333']);
        EMoney::create([
            'user_id' => $customer->id, 'current_balance' => '0.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        // مرجعُ الدفع طلبُ QR مدفوعٌ حقيقيّ، لا نصٌّ يدّعي الدفع.
        $this->paidQrRequest($this->merchant, 'TX99', '10000');
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '10000',
            paymentMethod: 'amial_pay',
            customer: ['name' => 'علي', 'phone' => '+967700333'],
            paidTransactionId: 'TX99',
        );

        $refund = $this->svc->refund($this->merchant, $sale->sale_ulid, '8000', 'wallet');
        $this->assertSame('pending_approval', $refund->status);

        // قبل الموافقة: لا حركة على محافظ
        $customerBalance = (string) EMoney::where('user_id', $customer->id)->value('current_balance');
        $this->assertSame('0.0000', $customerBalance);

        // الإدارة توافق
        $admin = User::factory()->create(['type' => 1]);
        $approved = $this->svc->approve($refund, $admin->id);

        $this->assertSame('completed', $approved->status);
        $this->assertSame($admin->id, $approved->approved_by_admin_id);

        // الآن المحفظة استلمت
        $customerBalance = (string) EMoney::where('user_id', $customer->id)->value('current_balance');
        $this->assertSame(MoneyService::normalize('8000'), $customerBalance);
    }

    /** @test */
    public function refund_dispatches_notification_to_customer(): void
    {
        $customer = User::factory()->create(['type' => 2, 'phone' => '+967700444']);
        EMoney::create([
            'user_id' => $customer->id, 'current_balance' => '0.0000',
            'held_balance' => '0.0000', 'pending_balance' => '0.0000',
            'charge_earned' => '0.0000', 'zone_code' => 'SOUTH',
        ]);

        // مرجعُ الدفع طلبُ QR مدفوعٌ حقيقيّ، لا نصٌّ يدّعي الدفع.
        $this->paidQrRequest($this->merchant, 'TX44', '2000');
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '2000',
            paymentMethod: 'amial_pay',
            customer: ['name' => 'هدى', 'phone' => '+967700444'],
            paidTransactionId: 'TX44',
        );

        $this->svc->refund($this->merchant, $sale->sale_ulid, '500', 'wallet');

        // إشعار للعميل
        $n = AmialNotification::where('user_id', $customer->id)
            ->where('type', 'refund_received')
            ->first();
        $this->assertNotNull($n);
    }
}
