<?php

namespace Tests\Feature;

use App\Models\EMoney;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\CashierService;
use App\Services\MerchantSaleRefundService;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-CASHIER-REFUND-001 — اختبارات قائمة المبيعات (مدخل شاشة الاسترجاع).
 *
 * GET /cashier/sales يعيد مبيعات اليوم بمعرّفاتها (sale_ulid) مع
 * إجمالي المسترجَع من كل بيع، لتفتح منها شاشة الاسترجاع في التطبيق.
 */
class CashierSalesListTest extends TestCase
{
    use RefreshDatabase;

    private CashierService $cashier;
    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
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

    /** @test */
    public function lists_todays_sales_with_ulids_newest_first(): void
    {
        $first = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '1000',
            paymentMethod: 'cash',
            items: [['name' => 'أ', 'qty' => 1, 'price' => '1000']],
        );
        $second = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '2500',
            paymentMethod: 'cash',
        );

        $out = $this->cashier->listSales($this->merchant);

        $this->assertCount(2, $out['sales']);
        $this->assertSame($second->sale_ulid, $out['sales'][0]['sale_ulid']);
        $this->assertSame($first->sale_ulid, $out['sales'][1]['sale_ulid']);
        $this->assertSame(MoneyService::normalize('2500'), $out['sales'][0]['total_amount']);
        $this->assertSame(1, $out['sales'][1]['items_count']);
        $this->assertFalse($out['sales'][0]['fully_refunded']);
        $this->assertSame(MoneyService::normalize('0'), $out['sales'][0]['refunded_total']);
    }

    /** @test */
    public function excludes_other_merchants_and_other_days(): void
    {
        $other = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $other->id, 'verification_status' => 'verified']);
        $this->cashier->recordSale(merchant: $other, total: '9000', paymentMethod: 'cash');

        $old = $this->cashier->recordSale(merchant: $this->merchant, total: '700', paymentMethod: 'cash');
        \App\Models\MerchantSale::where('id', $old->id)->update(['created_at' => now()->subDays(2)]);

        $today = $this->cashier->recordSale(merchant: $this->merchant, total: '1500', paymentMethod: 'cash');

        $out = $this->cashier->listSales($this->merchant);
        $this->assertCount(1, $out['sales']);
        $this->assertSame($today->sale_ulid, $out['sales'][0]['sale_ulid']);

        // بيوع يوم سابق تظهر عند طلب تاريخها
        $outOld = $this->cashier->listSales($this->merchant, now()->subDays(2)->format('Y-m-d'));
        $this->assertCount(1, $outOld['sales']);
        $this->assertSame($old->sale_ulid, $outOld['sales'][0]['sale_ulid']);
    }

    /** @test */
    public function reflects_partial_and_full_refunds(): void
    {
        $sale = $this->cashier->recordSale(
            merchant: $this->merchant,
            total: '5000',
            paymentMethod: 'cash',
            items: [['name' => 'منتج', 'qty' => 1, 'price' => '5000']],
        );

        $svc = app(MerchantSaleRefundService::class);
        $svc->refund(
            merchant: $this->merchant,
            originalSaleUlid: $sale->sale_ulid,
            refundAmount: '2000',
            refundMethod: 'cash',
            reason: 'جزئي',
        );

        $out = $this->cashier->listSales($this->merchant);
        $this->assertSame(MoneyService::normalize('2000'), $out['sales'][0]['refunded_total']);
        $this->assertFalse($out['sales'][0]['fully_refunded']);

        $svc->refund(
            merchant: $this->merchant,
            originalSaleUlid: $sale->sale_ulid,
            refundAmount: '3000',
            refundMethod: 'cash',
            reason: 'الباقي',
        );

        $out = $this->cashier->listSales($this->merchant);
        $this->assertSame(MoneyService::normalize('5000'), $out['sales'][0]['refunded_total']);
        $this->assertTrue($out['sales'][0]['fully_refunded']);
    }
}
