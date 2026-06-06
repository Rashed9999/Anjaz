<?php

namespace Tests\Feature;

use App\Models\MerchantProduct;
use App\Models\MerchantSale;
use App\Models\User;
use App\Services\CashierService;
use App\Services\MoneyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-CASHIER-001 — كاشير التاجر.
 */
class CashierTest extends TestCase
{
    use RefreshDatabase;

    private CashierService $svc;
    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CashierService::class);
        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
    }

    /** @test */
    public function cash_sale_is_completed(): void
    {
        $sale = $this->svc->recordSale(
            merchant: $this->merchant,
            total: '1500',
            paymentMethod: 'cash',
            items: [['name' => 'أرز', 'qty' => 2, 'price' => '750']],
        );

        $this->assertSame('completed', $sale->status);
        $this->assertSame('cash', $sale->payment_method);
        $this->assertSame(MoneyService::normalize('1500'), (string)$sale->total_amount);
    }

    /** @test */
    public function credit_sale_requires_customer_and_is_unpaid(): void
    {
        $sale = $this->svc->recordSale(
            merchant: $this->merchant,
            total: '2000',
            paymentMethod: 'credit',
            customer: ['name' => 'أبو محمد', 'phone' => '+967700001234'],
        );
        $this->assertSame('credit_unpaid', $sale->status);
        $this->assertSame('أبو محمد', $sale->customer_name);

        $this->expectException(\InvalidArgumentException::class);
        $this->svc->recordSale(
            merchant: $this->merchant,
            total: '2000',
            paymentMethod: 'credit', // بلا عميل → يرمي
        );
    }

    /** @test */
    public function amial_pay_sale_requires_transaction_reference(): void
    {
        // amial_pay بلا paid_transaction_id → حالة pending_payment (ينتظر دفع العميل)
        $sale = $this->svc->recordSale(
            merchant: $this->merchant,
            total: '500',
            paymentMethod: 'amial_pay',
        );
        $this->assertSame('pending_payment', $sale->status);
    }

    /** @test */
    public function settle_credit_marks_paid(): void
    {
        $sale = $this->svc->recordSale(
            merchant: $this->merchant,
            total: '2000',
            paymentMethod: 'credit',
            customer: ['name' => 'عميل', 'phone' => '+967700100001'],
        );

        $settled = $this->svc->settleCredit($this->merchant, $sale->id, 'TX123');
        $this->assertSame('credit_paid', $settled->status);
        $this->assertNotNull($settled->settled_at);

        // لا تُسوّى مرتين
        $this->expectException(\RuntimeException::class);
        $this->svc->settleCredit($this->merchant, $sale->id);
    }

    /** @test */
    public function daily_report_aggregates_by_method_and_outstanding_credit(): void
    {
        $this->svc->recordSale(merchant: $this->merchant, total: '1000', paymentMethod: 'cash');
        $this->svc->recordSale(merchant: $this->merchant, total: '500', paymentMethod: 'amial_pay', paidTransactionId: 'TX1');
        $this->svc->recordSale(merchant: $this->merchant, total: '700', paymentMethod: 'credit', customer: ['name' => 'x', 'phone' => '+967700100002']);

        $report = $this->svc->dailyReport($this->merchant);

        $this->assertSame(3, $report['sales_count']);
        $this->assertSame(MoneyService::normalize('1000'), $report['by_method']['cash']);
        $this->assertSame(MoneyService::normalize('500'), $report['by_method']['amial_pay']);
        // الإيراد الفعلي = نقد + أميال باي = 1500 (الأجل لا يُحتسب)
        $this->assertSame(MoneyService::normalize('1500'), $report['realized_revenue']);
        // الأجل المستحق = 700
        $this->assertSame(MoneyService::normalize('700'), MoneyService::normalize($report['outstanding_credit_total']));
    }

    /** @test */
    public function products_can_be_added_and_listed(): void
    {
        $this->svc->addProduct($this->merchant, ['name' => 'بندورة', 'price' => '300', 'category' => 'خضار']);
        $this->svc->addProduct($this->merchant, ['name' => 'خبز', 'price' => '100']);

        $products = $this->svc->listProducts($this->merchant);
        $this->assertCount(2, $products);

        // لا تظهر منتجات تاجر آخر
        $other = User::factory()->create(['type' => 3]);
        \App\Models\MerchantProduct::create(['merchant_user_id' => $other->id, 'name' => 'سرّي', 'price' => '1', 'is_active' => true]);
        $this->assertCount(2, $this->svc->listProducts($this->merchant));
    }

    /** @test */
    public function credit_sale_creates_credit_account_and_movement(): void
    {
        // AMIAL-CUSTOMER-CREDIT-001 — التكامل: بيع أجل في الكاشير يُسجَّل في حساب العميل
        $sale = $this->svc->recordSale(
            merchant: $this->merchant,
            total: '15000',
            paymentMethod: 'credit',
            customer: ['name' => 'محمد العولقي', 'phone' => '+967700555111'],
            creditDueDate: '2026-07-15',
        );

        $this->assertSame('credit_unpaid', $sale->status);

        $account = \App\Models\CustomerCreditAccount::where('merchant_user_id', $this->merchant->id)
            ->where('customer_phone', '+967700555111')->first();
        $this->assertNotNull($account, 'حساب العميل أُنشئ تلقائياً');
        $this->assertSame(MoneyService::normalize('15000'), (string)$account->current_balance);

        $mv = \App\Models\CustomerCreditMovement::where('account_id', $account->id)->first();
        $this->assertNotNull($mv);
        $this->assertSame('sale', $mv->type);
        $this->assertSame($sale->sale_ulid, $mv->reference_id);
        $this->assertSame('2026-07-15', $mv->due_date->format('Y-m-d'));
    }

    /** @test */
    public function product_stores_inventory_fields_and_effective_price(): void
    {
        $p = $this->svc->addProduct($this->merchant, [
            'name' => 'دواء', 'price' => '1000', 'cost_price' => '600',
            'offer_price' => '850', 'quantity' => '40',
            'production_date' => '2026-01-01', 'expiry_date' => '2027-01-01',
            'category' => 'صيدلية',
        ]);

        $this->assertSame(MoneyService::normalize('600'), (string)$p->cost_price);
        $this->assertSame('40.000', (string)$p->quantity);
        // السعر الفعّال = سعر العرض لأنه موجود
        $this->assertSame(MoneyService::normalize('850'), MoneyService::normalize($p->effective_price));

        // بلا عرض → السعر الفعّال = سعر البيع
        $p2 = $this->svc->addProduct($this->merchant, ['name' => 'علبة', 'price' => '500']);
        $this->assertSame(MoneyService::normalize('500'), MoneyService::normalize($p2->effective_price));
    }

    /** @test */
    public function sale_decrements_stock_for_linked_products(): void
    {
        $p = $this->svc->addProduct($this->merchant, ['name' => 'ماء', 'price' => '50', 'quantity' => '10']);

        $this->svc->recordSale(
            merchant: $this->merchant,
            total: '150',
            paymentMethod: 'cash',
            items: [['name' => 'ماء', 'qty' => 3, 'price' => '50', 'product_id' => $p->id]],
        );

        $this->assertSame('7.000', (string)$p->fresh()->quantity); // 10 - 3
    }

    /** @test */
    public function amial_pay_creates_pending_then_links_on_payment(): void
    {
        // بلا مرجع دفع → بيع معلّق
        $sale = $this->svc->recordSale(
            merchant: $this->merchant,
            total: '500',
            paymentMethod: 'amial_pay',
        );
        $this->assertSame('pending_payment', $sale->status);
        $this->assertNull($sale->paid_transaction_id);

        // الربط بعد دفع العميل
        $linked = $this->svc->linkPayment($sale->sale_ulid, 'TX-999', $this->merchant->id);
        $this->assertNotNull($linked);
        $this->assertSame('completed', $linked->status);
        $this->assertSame('TX-999', $linked->paid_transaction_id);

        // ربط ثانٍ لا يجد بيعاً معلّقاً → null بأمان
        $this->assertNull($this->svc->linkPayment($sale->sale_ulid, 'TX-000', $this->merchant->id));
    }
}
