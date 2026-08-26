<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleProduct;
use App\Services\MoneyService;
use App\Services\WholesaleCollectionService;
use App\Services\WholesaleInvoiceService;
use App\Services\WholesaleReportsService;
use App\Services\WholesaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AMIAL-WHOLESALE-001 — اختبارات شاملة.
 */
class WholesaleTest extends TestCase
{
    use RefreshDatabase;

    private WholesaleService $svc;
    private WholesaleInvoiceService $invSvc;
    private WholesaleCollectionService $colSvc;
    private WholesaleReportsService $repSvc;
    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(WholesaleService::class);
        $this->invSvc = app(WholesaleInvoiceService::class);
        $this->colSvc = app(WholesaleCollectionService::class);
        $this->repSvc = app(WholesaleReportsService::class);

        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create([
            'user_id' => $this->merchant->id,
            'verification_status' => 'verified',
            'business_type' => 'wholesale',
        ]);
    }

    /** @test */
    public function business_is_created_with_default_tiers(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $this->assertCount(3, $biz->priceTiers);
        $codes = $biz->priceTiers->pluck('code')->all();
        $this->assertContains('retail', $codes);
        $this->assertContains('wholesale', $codes);
        $this->assertContains('super_wholesale', $codes);
    }

    /** @test */
    public function multi_pricing_selects_best_price_for_quantity(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();

        $product = $this->svc->addProduct($biz, [
            'name' => 'أرز 25 كجم',
            'base_price' => '8000',
            'initial_stock' => 100,
        ]);

        // 3 شرائح سعر
        $this->svc->setProductPrice($product, $tier->id, 7500, 10);
        $this->svc->setProductPrice($product, $tier->id, 7000, 50);

        // كمية 5 → base_price (لا توجد شريحة min ≤ 5 لأقل من 10)
        $this->assertEquals(8000, $product->priceFor($tier->id, 5));
        // كمية 30 → 7500 (min 10)
        $this->assertEquals(7500, $product->priceFor($tier->id, 30));
        // كمية 50 → 7000
        $this->assertEquals(7000, $product->priceFor($tier->id, 50));
        // كمية 100 → 7000
        $this->assertEquals(7000, $product->priceFor($tier->id, 100));
    }

    /** @test */
    public function invoice_creation_deducts_stock_and_updates_balance(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();

        $product = $this->svc->addProduct($biz, [
            'name' => 'سكر 50 كجم', 'base_price' => '5000', 'initial_stock' => 100,
        ]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'محل سعيد', 'credit_limit' => 100000,
            'default_tier_id' => $tier->id,
        ]);

        $invoice = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 10]],
            ['customer_id' => $customer->id, 'payment_type' => 'credit'],
        );

        $this->assertEquals('50000.0000', $invoice->total_amount);
        $this->assertEquals('credit', $invoice->payment_type);
        $this->assertEquals('issued', $invoice->status);

        // المخزون قلّ
        $product->refresh();
        $this->assertEquals('90.0000', (string)$product->current_stock);

        // رصيد العميل ارتفع
        $customer->refresh();
        $this->assertEquals('50000.0000', (string)$customer->current_balance);
    }

    /** @test */
    public function credit_limit_blocks_invoice_when_exceeded(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();

        $product = $this->svc->addProduct($biz, [
            'name' => 'X', 'base_price' => '5000', 'initial_stock' => 100,
        ]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'X', 'credit_limit' => 20000, // حدّ منخفض
            'default_tier_id' => $tier->id,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('تجاوز حدّ الائتمان');
        $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 10]], // 50,000 > 20,000
            ['customer_id' => $customer->id, 'payment_type' => 'credit'],
        );
    }

    /** @test */
    public function cash_invoice_doesnt_affect_credit_balance(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, ['name' => 'X', 'base_price' => '5000', 'initial_stock' => 100]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'X', 'credit_limit' => 0,
            'default_tier_id' => $tier->id,
        ]);

        // نقد → ينجح حتى لو credit_limit=0
        $invoice = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 5]],
            ['customer_id' => $customer->id, 'payment_type' => 'cash'],
        );

        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals('25000.0000', (string)$invoice->paid_amount);
        $this->assertEquals('0.0000', (string)$invoice->balance_due);

        $customer->refresh();
        $this->assertEquals('0.0000', (string)$customer->current_balance);
    }

    /** @test */
    public function invoice_applies_line_and_invoice_discounts_without_accepting_a_client_price(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, [
            'name' => 'صنف الخصم', 'base_price' => '1000', 'initial_stock' => 20,
        ]);
        // يجب أن يختار الخادم سعر الشريحة (900) لا سعراً يرسله التطبيق.
        $this->svc->setProductPrice($product, $tier->id, 900, 1);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'عميل الخصم', 'credit_limit' => 100000, 'default_tier_id' => $tier->id,
        ]);

        $invoice = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 5, 'discount_per_unit' => 100]],
            ['customer_id' => $customer->id, 'payment_type' => 'credit', 'discount_amount' => 200],
        );

        // (900 - 100) × 5 - 200 = 3,800
        $this->assertEquals('4000.0000', (string)$invoice->subtotal);
        $this->assertEquals('200.0000', (string)$invoice->discount_amount);
        $this->assertEquals('3800.0000', (string)$invoice->total_amount);
        $this->assertEquals('900.0000', (string)$invoice->items()->first()->unit_price);
        $this->assertEquals('100.0000', (string)$invoice->items()->first()->discount_per_unit);
    }

    /** @test */
    public function collection_reduces_invoice_balance_and_customer_debt(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, ['name' => 'X', 'base_price' => '10000', 'initial_stock' => 100]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'X', 'credit_limit' => 200000,
            'default_tier_id' => $tier->id,
        ]);

        $invoice = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 10]],
            ['customer_id' => $customer->id, 'payment_type' => 'credit'],
        );
        // 100,000 ر.ي

        // دفع 40,000
        $this->colSvc->recordCollection($this->merchant, $invoice, [
            'amount' => 40000, 'payment_method' => 'cash',
        ]);

        $invoice->refresh();
        $this->assertEquals('partial_paid', $invoice->status);
        $this->assertEquals('40000.0000', (string)$invoice->paid_amount);
        $this->assertEquals('60000.0000', (string)$invoice->balance_due);

        $customer->refresh();
        $this->assertEquals('60000.0000', (string)$customer->current_balance);

        // دفع الباقي
        $this->colSvc->recordCollection($this->merchant, $invoice, [
            'amount' => 60000, 'payment_method' => 'bank_transfer',
        ]);

        $invoice->refresh();
        $this->assertEquals('paid', $invoice->status);
        $this->assertEquals('0.0000', (string)$invoice->balance_due);

        $customer->refresh();
        $this->assertEquals('0.0000', (string)$customer->current_balance);
    }

    /** @test */
    public function collection_cannot_exceed_balance(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, ['name' => 'X', 'base_price' => '1000', 'initial_stock' => 100]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'X', 'credit_limit' => 100000, 'default_tier_id' => $tier->id,
        ]);
        $invoice = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 5]],
            ['customer_id' => $customer->id, 'payment_type' => 'credit'],
        );
        // 5,000

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('يتجاوز المتبقّي');
        $this->colSvc->recordCollection($this->merchant, $invoice, [
            'amount' => 10000, 'payment_method' => 'cash',
        ]);
    }

    /** @test */
    public function void_invoice_restores_stock_and_credit(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, ['name' => 'X', 'base_price' => '1000', 'initial_stock' => 100]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'X', 'credit_limit' => 50000, 'default_tier_id' => $tier->id,
        ]);
        $invoice = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 20]],
            ['customer_id' => $customer->id, 'payment_type' => 'credit'],
        );
        // 20,000

        $product->refresh();
        $this->assertEquals('80.0000', (string)$product->current_stock);
        $customer->refresh();
        $this->assertEquals('20000.0000', (string)$customer->current_balance);

        // إبطال
        $this->invSvc->voidInvoice($invoice, 'خطأ إدخال');

        $product->refresh();
        $this->assertEquals('100.0000', (string)$product->current_stock);
        $customer->refresh();
        $this->assertEquals('0.0000', (string)$customer->current_balance);

        $invoice->refresh();
        $this->assertEquals('voided', $invoice->status);
    }

    /** @test */
    public function invoice_number_is_unique_and_increments(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, ['name' => 'X', 'base_price' => '100', 'initial_stock' => 100]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'X', 'credit_limit' => 10000, 'default_tier_id' => $tier->id,
        ]);

        $inv1 = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 1]],
            ['customer_id' => $customer->id, 'payment_type' => 'cash'],
        );
        $inv2 = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 1]],
            ['customer_id' => $customer->id, 'payment_type' => 'cash'],
        );

        $this->assertNotEquals($inv1->invoice_number, $inv2->invoice_number);
        $this->assertStringContainsString('00001', $inv1->invoice_number);
        $this->assertStringContainsString('00002', $inv2->invoice_number);
    }

    /** @test */
    public function aging_report_buckets_by_overdue_days(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, ['name' => 'X', 'base_price' => '1000', 'initial_stock' => 1000]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'X', 'credit_limit' => 1000000, 'default_tier_id' => $tier->id,
        ]);

        // فاتورة current (مستحقّة بعد 10 يوم)
        $inv = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 5]],
            ['customer_id' => $customer->id, 'payment_type' => 'credit',
             'due_date' => now()->addDays(10)->toDateString()],
        );

        // فاتورة 30-60: حدّث due_date يدوياً
        $inv2 = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 3]],
            ['customer_id' => $customer->id, 'payment_type' => 'credit'],
        );
        \DB::table('wholesale_invoices')->where('id', $inv2->id)
            ->update(['due_date' => now()->subDays(45)->toDateString()]);

        $report = $this->repSvc->agingReport($biz);

        $this->assertGreaterThan(0, $report['total_receivable']);
        $this->assertGreaterThan(0, $report['buckets']['current']);
        $this->assertGreaterThan(0, $report['buckets']['30_60']);
    }

    /** @test */
    public function customer_statement_tracks_running_balance(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, ['name' => 'X', 'base_price' => '1000', 'initial_stock' => 100]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'Test', 'credit_limit' => 100000, 'default_tier_id' => $tier->id,
        ]);

        $inv = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 10]],
            ['customer_id' => $customer->id, 'payment_type' => 'credit'],
        );
        $this->colSvc->recordCollection($this->merchant, $inv, [
            'amount' => 4000, 'payment_method' => 'cash',
        ]);

        $statement = $this->repSvc->customerStatement($customer);

        $this->assertCount(2, $statement['events']);
        $this->assertEquals(10000, $statement['summary']['total_invoiced']);
        $this->assertEquals(4000, $statement['summary']['total_paid']);
        $this->assertEquals(6000, $statement['summary']['closing_balance']);
    }
}
