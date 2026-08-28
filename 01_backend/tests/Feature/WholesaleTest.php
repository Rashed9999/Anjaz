<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleProduct;
use App\Services\MoneyService;
use App\Services\WholesaleCollectionService;
use App\Services\WholesaleInvoiceService;
use App\Services\WholesaleReportsService;
use App\Services\WholesaleReturnService;
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
    private WholesaleReturnService $returnSvc;
    private User $merchant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(WholesaleService::class);
        $this->invSvc = app(WholesaleInvoiceService::class);
        $this->colSvc = app(WholesaleCollectionService::class);
        $this->repSvc = app(WholesaleReportsService::class);
        $this->returnSvc = app(WholesaleReturnService::class);

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
    public function unit_conversion_and_lot_fifo_are_resolved_server_side_in_the_invoice(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, [
            'name' => 'ماء معبأ', 'unit' => 'قطعة', 'base_price' => '10', 'initial_stock' => 0,
        ]);
        $carton = $this->svc->saveUnit($product, [
            'code' => 'carton', 'name' => 'كرتون', 'factor_to_base' => '12',
        ]);
        $lot = $this->svc->receiveLot($product, [
            'lot_number' => 'W-LOT-01', 'quantity' => '2', 'unit_id' => $carton->id,
            'expiry_date' => now()->addYear()->toDateString(),
        ]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'عميل الكراتين', 'credit_limit' => '10000', 'default_tier_id' => $tier->id,
        ]);

        $invoice = $this->invSvc->createInvoice($this->merchant, $biz, [[
            'product_id' => $product->id, 'unit_id' => $carton->id, 'quantity' => '1',
        ]], ['customer_id' => $customer->id, 'payment_type' => 'credit']);

        $line = $invoice->items->first();
        $this->assertSame('12.0000', (string) $line->base_quantity);
        $this->assertSame('120.0000', (string) $line->unit_price);
        $this->assertSame('120.0000', (string) $invoice->total_amount);
        $product->refresh(); $lot->refresh();
        $this->assertSame('12.0000', (string) $product->current_stock);
        $this->assertSame('12.0000', (string) $lot->quantity_available);
        $this->assertCount(1, $line->lotAllocations);
    }

    /** @test */
    public function amial_pay_invoice_requires_a_paid_request_for_the_merchant_wallet_and_links_it_once(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, ['name' => 'تحصيل محفظة', 'base_price' => '2500', 'initial_stock' => 10]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'عميل المحفظة', 'credit_limit' => 0, 'default_tier_id' => $tier->id,
        ]);
        PaymentRequest::create([
            'request_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'short_code' => 'WHQR-1001',
            'requester_user_id' => $this->merchant->id,
            'amount' => '5000.0000',
            'share_method' => 'qr',
            'status' => 'paid',
            'paid_transaction_id' => 'TX-WHOLESALE-1001',
            'paid_at' => now(),
            'expires_at' => now()->addMinutes(5),
            'zone_code' => 'SOUTH',
        ]);

        $invoice = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 2]],
            [
                'customer_id' => $customer->id,
                'payment_type' => 'amial_pay',
                'paid_transaction_id' => 'TX-WHOLESALE-1001',
            ],
        );

        $this->assertSame('amial_pay', $invoice->payment_type);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('5000.0000', (string) $invoice->paid_amount);
        $this->assertSame('0.0000', (string) $invoice->balance_due);
        $this->assertSame('TX-WHOLESALE-1001', $invoice->paid_transaction_id);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('تم ربط حركة أميال باي');
        $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 1]],
            [
                'customer_id' => $customer->id,
                'payment_type' => 'amial_pay',
                'paid_transaction_id' => 'TX-WHOLESALE-1001',
            ],
        );
    }

    /** @test */
    public function amial_pay_invoice_rejects_a_paid_request_with_a_different_amount(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, ['name' => 'مبلغ غير مطابق', 'base_price' => '1000', 'initial_stock' => 10]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'عميل', 'credit_limit' => 0, 'default_tier_id' => $tier->id,
        ]);
        PaymentRequest::create([
            'request_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'short_code' => 'WHQR-1002',
            'requester_user_id' => $this->merchant->id,
            'amount' => '999.0000',
            'share_method' => 'qr',
            'status' => 'paid',
            'paid_transaction_id' => 'TX-WHOLESALE-1002',
            'paid_at' => now(),
            'expires_at' => now()->addMinutes(5),
            'zone_code' => 'SOUTH',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يطابق إجمالي الفاتورة');
        $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 1]],
            [
                'customer_id' => $customer->id,
                'payment_type' => 'amial_pay',
                'paid_transaction_id' => 'TX-WHOLESALE-1002',
            ],
        );
    }

    /** @test */
    public function amial_pay_collection_requires_a_paid_owner_wallet_request_and_reduces_the_existing_debt(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, ['name' => 'دين أميال', 'base_price' => '1000', 'initial_stock' => 10]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'عميل دين', 'credit_limit' => 10000, 'default_tier_id' => $tier->id,
        ]);
        $invoice = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 5]],
            ['customer_id' => $customer->id, 'payment_type' => 'credit'],
        );
        PaymentRequest::create([
            'request_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'short_code' => 'WHQR-2001',
            'requester_user_id' => $this->merchant->id,
            'amount' => '2000.0000',
            'share_method' => 'qr',
            'status' => 'paid',
            'paid_transaction_id' => 'TX-WHOLESALE-2001',
            'paid_at' => now(),
            'expires_at' => now()->addMinutes(5),
            'zone_code' => 'SOUTH',
        ]);

        $collection = $this->colSvc->recordCollection($this->merchant, $invoice, [
            'amount' => '2000',
            'payment_method' => 'amial_pay',
            'paid_transaction_id' => 'TX-WHOLESALE-2001',
        ]);

        $this->assertSame('amial_pay', $collection->payment_method);
        $this->assertSame('TX-WHOLESALE-2001', $collection->paid_transaction_id);
        $invoice->refresh();
        $customer->refresh();
        $this->assertSame('3000.0000', (string) $invoice->balance_due);
        $this->assertSame('3000.0000', (string) $customer->current_balance);
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
    public function wholesale_return_requires_review_then_restores_stock_and_credits_only_the_outstanding_debt(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, ['name' => 'صنف مرتجع', 'base_price' => '1000', 'initial_stock' => 10]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'عميل مرتجع', 'credit_limit' => 50000, 'default_tier_id' => $tier->id,
        ]);
        $invoice = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 5]],
            ['customer_id' => $customer->id, 'payment_type' => 'credit'],
        );
        $line = $invoice->items()->first();

        $return = $this->returnSvc->request($this->merchant, $invoice,
            [['invoice_item_id' => $line->id, 'quantity' => 2]], 'تالف عند الاستلام');
        $this->assertSame('requested', $return->status);
        $this->assertEquals('2000.0000', (string) $return->total_amount);
        $product->refresh();
        $this->assertEquals('5.0000', (string) $product->current_stock); // لا تغيير قبل الاعتماد

        $this->returnSvc->resolve($this->merchant, $return, true, 'تم الفحص');
        $product->refresh(); $customer->refresh(); $invoice->refresh();
        $this->assertEquals('7.0000', (string) $product->current_stock);
        $this->assertEquals('3000.0000', (string) $customer->current_balance);
        $this->assertEquals('3000.0000', (string) $invoice->balance_due);
        $this->assertEquals('3000.0000', (string) $invoice->total_amount);
    }

    /** @test */
    public function paid_wholesale_return_is_marked_as_refund_due_instead_of_faking_a_cash_refund(): void
    {
        $biz = $this->svc->getOrCreateBusiness($this->merchant);
        $tier = $biz->priceTiers->where('code', 'wholesale')->first();
        $product = $this->svc->addProduct($biz, ['name' => 'مرتجع نقدي', 'base_price' => '1000', 'initial_stock' => 4]);
        $customer = $this->svc->addCustomer($biz, [
            'full_name' => 'عميل نقدي', 'credit_limit' => 0, 'default_tier_id' => $tier->id,
        ]);
        $invoice = $this->invSvc->createInvoice($this->merchant, $biz,
            [['product_id' => $product->id, 'quantity' => 2]],
            ['customer_id' => $customer->id, 'payment_type' => 'cash'],
        );
        $return = $this->returnSvc->request($this->merchant, $invoice,
            [['invoice_item_id' => $invoice->items()->first()->id, 'quantity' => 1]], 'العميل أعاد الصنف');
        $resolved = $this->returnSvc->resolve($this->merchant, $return, true);

        $this->assertSame('refund_pending', $resolved->settlement_type);
        $this->assertEquals('1000.0000', (string) $resolved->refund_due_amount);
        $this->assertEquals('0.0000', (string) $resolved->credited_amount);
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
