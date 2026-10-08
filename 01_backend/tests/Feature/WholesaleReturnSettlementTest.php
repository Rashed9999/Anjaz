<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\MerchantProfile;
use App\Models\Retail\ShiftCashMovement;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleInvoice;
use App\Models\WholesaleReturn;
use App\Models\WholesaleReturnSettlement;
use App\Services\CashierShiftService;
use App\Services\MerchantFinancialTruthReportService;
use App\Services\WholesaleReturnSettlementService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WholesaleReturnSettlementTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $merchant = User::factory()->create([
            'role' => A::ROLE_MERCHANT,
            'type' => MERCHANT_TYPE,
            'is_active' => 1,
            'zone_code' => 'SOUTH',
        ]);

        MerchantProfile::create([
            'user_id' => $merchant->id,
            'business_type' => A::BIZ_WHOLESALE,
            'subscription_plan' => A::PLAN_ENTERPRISE,
            'verification_status' => 'verified',
        ]);

        $business = WholesaleBusiness::create([
            'merchant_user_id' => $merchant->id,
            'business_name' => 'جملة اختبار',
            'invoice_prefix' => 'W',
            'next_invoice_number' => 1,
            'default_tax_rate' => 0,
            'default_payment_terms_days' => 30,
            'is_active' => true,
            'zone_code' => 'SOUTH',
        ]);

        $customer = WholesaleCustomer::create([
            'business_id' => $business->id,
            'full_name' => 'عميل مرتجع',
            'phone' => '967777999111',
            'credit_limit' => '0',
            'current_balance' => '0',
            'payment_terms_days' => 30,
            'total_purchases' => '0',
            'is_active' => true,
        ]);

        $invoice = WholesaleInvoice::create([
            'invoice_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'invoice_number' => 'W-0001',
            'business_id' => $business->id,
            'branch_id' => null,
            'customer_id' => $customer->id,
            'created_by_user_id' => $merchant->id,
            'invoice_date' => now()->toDateString(),
            'subtotal' => '1000',
            'discount_amount' => '0',
            'tax_rate' => '0',
            'tax_amount' => '0',
            'total_amount' => '1000',
            'paid_amount' => '1000',
            'balance_due' => '0',
            'status' => 'paid',
            'payment_type' => 'cash',
            'zone_code' => 'SOUTH',
        ]);

        $return = WholesaleReturn::create([
            'return_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'business_id' => $business->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'requested_by_user_id' => $merchant->id,
            'reviewed_by_user_id' => $merchant->id,
            'status' => 'approved',
            'settlement_type' => 'refund_pending',
            'subtotal_amount' => '1000',
            'discount_amount' => '0',
            'tax_amount' => '0',
            'total_amount' => '1000',
            'credited_amount' => '0',
            'refund_due_amount' => '1000',
            'reason' => 'اختبار مستحق مالي',
            'resolved_at' => now(),
        ]);

        return [$merchant, $business, $customer, $return];
    }

    /** @test */
    public function cash_refund_cannot_leave_a_different_branch_till(): void
    {
        [$merchant, , , $return] = $this->fixture();

        $saleBranch = Branch::create([
            'merchant_user_id' => $merchant->id,
            'name' => 'فرع البيع',
            'code' => 'SALE-BR',
            'is_active' => true,
            'is_default' => true,
        ]);
        $otherBranch = Branch::create([
            'merchant_user_id' => $merchant->id,
            'name' => 'فرع آخر',
            'code' => 'OTHER-BR',
            'is_active' => true,
            'is_default' => false,
        ]);

        WholesaleInvoice::whereKey($return->invoice_id)
            ->update(['branch_id' => $saleBranch->id]);

        $wrongTill = CashierShift::create([
            'merchant_user_id' => $merchant->id,
            'branch_id' => $otherBranch->id,
            'pos_user_id' => null,
            'opening_float' => '1500',
            'expected_cash' => '1500',
            'cash_sales' => '0',
            'sales_count' => 0,
            'status' => 'open',
            'opened_by' => $merchant->id,
            'opened_by_name' => 'مالك المنشأة',
            'opened_by_role' => 'merchant',
            'opened_at' => now(),
            'zone_code' => 'SOUTH',
        ]);

        try {
            app(WholesaleReturnSettlementService::class)->settle(
                merchant: $merchant,
                return: $return,
                actor: $merchant,
                amount: '1000',
                method: 'cash',
                idempotencyKey: 'wrong-branch-refund-001',
                cashierShiftId: $wrongTill->id,
            );
            $this->fail('كان يجب رفض إخراج النقد من درج فرع آخر');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('فرع آخر', $e->getMessage());
        }

        $this->assertSame(0, WholesaleReturnSettlement::where('return_id', $return->id)->count());
        $this->assertSame(0, ShiftCashMovement::where('shift_id', $wrongTill->id)->count());
    }

    /** @test */
    public function refund_due_is_not_cash_out_until_a_real_cash_settlement_occurs(): void
    {
        [$merchant, , , $return] = $this->fixture();

        $shift = app(CashierShiftService::class)->open(
            merchant: $merchant,
            posUserId: null,
            openingFloat: '1500',
        );

        $before = app(MerchantFinancialTruthReportService::class)->report($merchant);
        $returnRow = collect($before['movement']['rows'])->firstWhere('code', 'sale_return');

        $this->assertSame('0.0000', $returnRow['cash'],
            'مجرد refund_due_amount لا يجوز أن يظهر نقداً خارجاً قبل الصرف');
        $this->assertSame('1000.0000', app(WholesaleReturnSettlementService::class)->remaining($return));

        $settlement = app(WholesaleReturnSettlementService::class)->settle(
            merchant: $merchant,
            return: $return,
            actor: $merchant,
            amount: '1000',
            method: 'cash',
            idempotencyKey: 'wholesale-return-cash-001',
            cashierShiftId: $shift->id,
            note: 'تسليم للعميل',
        );

        $this->assertSame('cash', $settlement->method);
        $this->assertSame('1000.0000', (string) $settlement->amount);
        $this->assertSame('refund_paid', $return->fresh()->settlement_type);
        $this->assertSame('0.0000', app(WholesaleReturnSettlementService::class)->remaining($return->fresh()));

        $movement = ShiftCashMovement::where('reference', $settlement->settlement_ulid)->firstOrFail();
        $this->assertSame('refund', $movement->reason);
        $this->assertSame('out', $movement->direction);
        $this->assertSame('1000.0000', (string) $movement->amount);

        $snapshot = app(CashierShiftService::class)->snapshot($shift->fresh());
        $this->assertSame('500.0000', $snapshot['expected_cash']);

        $after = app(MerchantFinancialTruthReportService::class)->report($merchant);
        $returnRow = collect($after['movement']['rows'])->firstWhere('code', 'sale_return');
        $this->assertSame('1000.0000', $returnRow['cash']);
        $this->assertSame('0.0000', $returnRow['amial_pay']);

        $again = app(WholesaleReturnSettlementService::class)->settle(
            merchant: $merchant,
            return: $return->fresh(),
            actor: $merchant,
            amount: '1000',
            method: 'cash',
            idempotencyKey: 'wholesale-return-cash-001',
            cashierShiftId: $shift->id,
        );

        $this->assertSame($settlement->id, $again->id);
        $this->assertSame(1, WholesaleReturnSettlement::where('return_id', $return->id)->count());
        $this->assertSame(1, ShiftCashMovement::where('reference', $settlement->settlement_ulid)->count());
    }
}
