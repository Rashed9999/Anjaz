<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\User;
use App\Services\CashierService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** AMIAL-MIXED-PAYMENT-001 — الدفع المختلط (نقد + محفظة). */
class MixedPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchant = User::factory()->create(['type' => 3, 'role' => 'merchant', 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'business_type' => A::BIZ_RETAIL,
            'verification_status' => 'verified', 'subscription_plan' => A::PLAN_FREE]);
    }

    /** @test بيع مختلط صحيح: 400 نقد + 600 محفظة = 1000، يُخزَّن التقسيم. */
    public function valid_mixed_sale_stores_split(): void
    {
        $sale = app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '1000', paymentMethod: 'mixed', items: [],
            paidTransactionId: 'TXWALLET', cashAmount: '400', walletAmount: '600',
        );
        $this->assertSame('completed', $sale->status);
        $sale = $sale->fresh();
        $this->assertSame('400.00', (string) $sale->cash_amount);
        $this->assertSame('600.00', (string) $sale->wallet_amount);
        $this->assertNotNull($sale->settled_at);
    }

    /** @test مجموع خاطئ يُرفض. */
    public function mismatched_split_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '1000', paymentMethod: 'mixed', items: [],
            paidTransactionId: 'TX', cashAmount: '300', walletAmount: '600', // = 900 ≠ 1000
        );
    }

    /** @test جزء محفظي بلا تحصيل فعلي يُرفض. */
    public function wallet_part_without_payment_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '1000', paymentMethod: 'mixed', items: [],
            paidTransactionId: null, cashAmount: '400', walletAmount: '600',
        );
    }

    /** @test مختلط بلا جزء محفظي (كله نقد) لا يشترط تحصيلاً. */
    public function all_cash_mixed_needs_no_wallet_payment(): void
    {
        $sale = app(CashierService::class)->recordSale(
            merchant: $this->merchant, total: '1000', paymentMethod: 'mixed', items: [],
            paidTransactionId: null, cashAmount: '1000', walletAmount: '0',
        );
        $this->assertSame('completed', $sale->status);
        $this->assertSame('1000.00', (string) $sale->fresh()->cash_amount);
    }
}
