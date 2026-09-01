<?php

namespace Tests\Feature;

use App\Models\MerchantProfile;
use App\Models\CustomerCreditAccount;
use App\Models\CustomerCreditMovement;
use App\Models\Pharmacy;
use App\Models\PharmacyBatch;
use App\Models\PharmacyCustomer;
use App\Models\PharmacyProduct;
use App\Models\PharmacyStockAlert;
use App\Models\PaymentRequest;
use App\Models\User;
use App\Services\MoneyService;
use App\Services\PharmacyAlertService;
use App\Services\PharmacySaleService;
use App\Services\PharmacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-PHARMACY-001 — اختبارات شاملة لقطاع الصيدليات.
 */
class PharmacyTest extends TestCase
{
    use RefreshDatabase;

    private PharmacyService $svc;
    private PharmacySaleService $saleSvc;
    private PharmacyAlertService $alertSvc;
    private User $merchant;
    private Pharmacy $pharmacy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(PharmacyService::class);
        $this->saleSvc = app(PharmacySaleService::class);
        $this->alertSvc = app(PharmacyAlertService::class);

        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'verification_status' => 'verified']);
        $this->pharmacy = $this->svc->getOrCreatePharmacy($this->merchant);
    }

    // ============ Pharmacy + Products ============

    /** @test */
    public function pharmacy_is_unique_per_merchant(): void
    {
        $p2 = $this->svc->getOrCreatePharmacy($this->merchant);
        $this->assertSame($this->pharmacy->id, $p2->id);
        $this->assertSame(1, Pharmacy::where('merchant_user_id', $this->merchant->id)->count());
    }

    /** @test */
    public function add_product_starts_with_zero_stock(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'Panadol',
            'generic_name' => 'Paracetamol',
            'sale_price' => '500',
        ]);
        $this->assertSame('Panadol', $product->trade_name);
        $this->assertSame('0.0000', (string)$product->current_stock);
    }

    /** @test */
    public function add_batch_increases_product_stock(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'Brufen', 'sale_price' => '300',
        ]);

        $batch = $this->svc->addBatch($product, [
            'batch_number' => 'BR-001',
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 100,
        ]);

        $this->assertSame(MoneyService::normalize('100'), (string)$batch->quantity_remaining);

        $product->refresh();
        $this->assertSame(MoneyService::normalize('100'), (string)$product->current_stock);
    }

    /** @test */
    public function recalled_batch_is_removed_from_saleable_stock_without_erasing_its_audit_record(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, ['trade_name' => 'دواء مسحوب', 'sale_price' => '100']);
        $batch = $this->svc->addBatch($product, [
            'batch_number' => 'RECALL-001', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => '20',
        ]);
        $recalled = $this->svc->recallBatch($batch, $this->merchant, 'تعميم سحب من المورد');

        $this->assertSame('recalled', $recalled->status);
        $this->assertSame('تعميم سحب من المورد', $recalled->recall_reason);
        $this->assertSame($this->merchant->id, $recalled->recalled_by_user_id);
        $product->refresh();
        $this->assertSame('0.0000', (string) $product->current_stock);
    }

    /** @test */
    public function past_expiry_batch_is_rejected(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'X', 'sale_price' => '100',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('في الماضي');
        $this->svc->addBatch($product, [
            'batch_number' => 'OLD',
            'expiry_date' => '2020-01-01',
            'quantity_received' => 10,
        ]);
    }

    // ============ Sales — FIFO ============

    /** @test */
    public function sale_deducts_from_oldest_batch_first(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'Augmentin', 'sale_price' => '1500',
        ]);

        // Batch قديم (ينتهي خلال شهر)
        $oldBatch = $this->svc->addBatch($product, [
            'batch_number' => 'OLD',
            'expiry_date' => now()->addMonth()->toDateString(),
            'quantity_received' => 5,
        ]);

        // Batch جديد (ينتهي خلال سنة)
        $newBatch = $this->svc->addBatch($product, [
            'batch_number' => 'NEW',
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 10,
        ]);

        // بِع 3 → يجب أن يخصم من OLD
        $sale = $this->saleSvc->recordSale(
            $this->merchant, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 3]],
            ['payment_method' => 'cash'],
        );

        $oldBatch->refresh();
        $newBatch->refresh();
        $this->assertSame('2.0000', (string)$oldBatch->quantity_remaining);
        $this->assertSame('10.0000', (string)$newBatch->quantity_remaining);

        // عنصر البيع مرتبط بـ OLD batch
        $this->assertSame($oldBatch->id, $sale->items->first()->batch_id);
    }

    /** @test */
    public function sale_splits_across_multiple_batches_when_needed(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'Med', 'sale_price' => '100',
        ]);
        $b1 = $this->svc->addBatch($product, [
            'batch_number' => 'B1',
            'expiry_date' => now()->addMonth()->toDateString(),
            'quantity_received' => 5,
        ]);
        $b2 = $this->svc->addBatch($product, [
            'batch_number' => 'B2',
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 10,
        ]);

        // بِع 8 → يخصم 5 من B1 + 3 من B2
        $sale = $this->saleSvc->recordSale(
            $this->merchant, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 8]],
            ['payment_method' => 'cash'],
        );

        $b1->refresh();
        $b2->refresh();
        $this->assertSame('0.0000', (string)$b1->quantity_remaining);
        $this->assertSame('exhausted', $b1->status);
        $this->assertSame('7.0000', (string)$b2->quantity_remaining);
        $this->assertCount(2, $sale->items);
    }

    /** @test */
    public function amial_pay_pharmacy_sale_requires_a_paid_qr_for_the_merchant_wallet(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'Panadol QR', 'sale_price' => '500',
        ]);
        $this->svc->addBatch($product, [
            'batch_number' => 'QR-1', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 5,
        ]);
        PaymentRequest::create([
            'request_ulid' => (string) Str::ulid(),
            'short_code' => 'PHARMQR1',
            'requester_user_id' => $this->merchant->id,
            'amount' => '500.0000',
            'share_method' => 'qr',
            'status' => 'paid',
            'paid_transaction_id' => 'TX-PHARMACY-001',
            'paid_at' => now(),
            'expires_at' => now()->addMinutes(5),
            'zone_code' => 'SOUTH',
        ]);

        $sale = $this->saleSvc->recordSale(
            $this->merchant, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 1]],
            ['payment_method' => 'amial_pay', 'paid_transaction_id' => 'TX-PHARMACY-001'],
        );

        $this->assertSame('TX-PHARMACY-001', $sale->paid_transaction_id);
    }

    /** @test */
    public function pharmacy_sale_rejects_an_unpaid_or_forged_qr_reference_before_stock_is_deducted(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'Panadol غير مدفوع', 'sale_price' => '500',
        ]);
        $this->svc->addBatch($product, [
            'batch_number' => 'QR-UNPAID-1', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 5,
        ]);

        try {
            $this->saleSvc->recordSale(
                $this->merchant, $this->pharmacy, null,
                [['product_id' => $product->id, 'quantity' => 1]],
                ['payment_method' => 'amial_pay', 'paid_transaction_id' => 'FORGED-PHARMACY-QR'],
            );
            $this->fail('لا يجوز إنشاء بيع أميال باي بمرجع QR غير مدفوع');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('مرجع أميال باي غير صالح', $e->getMessage());
        }

        $this->assertSame('5.0000', (string) $product->fresh()->current_stock,
            'فشل QR يجب ألا يخصم المخزون ولا ينشئ بيعاً');
        $this->assertSame(0, \App\Models\PharmacySale::count());
    }

    /** @test */
    public function pharmacy_credit_sale_creates_a_unified_debt_visible_in_the_customers_deferred_invoices(): void
    {
        $customerUser = User::factory()->create([
            'type' => 2, 'zone_code' => 'SOUTH', 'phone' => '+967771700088',
        ]);
        $customer = $this->svc->addCustomer($this->pharmacy, [
            'full_name' => 'عميل الفاتورة الآجلة', 'phone' => '771700088',
        ]);
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'دواء آجل', 'sale_price' => '1200',
        ]);
        $this->svc->addBatch($product, [
            'batch_number' => 'CREDIT-1', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 5,
        ]);

        $sale = $this->saleSvc->recordSale(
            $this->merchant, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 1]],
            ['payment_method' => 'credit', 'customer_id' => $customer->id],
        );

        $account = CustomerCreditAccount::where('merchant_user_id', $this->merchant->id)->sole();
        $this->assertSame($customerUser->id, (int) $account->customer_user_id);
        $this->assertSame('1200.0000', (string) $account->current_balance);
        $movement = CustomerCreditMovement::where('account_id', $account->id)->sole();
        $this->assertSame('sale', $movement->type);
        $this->assertSame('pharmacy_sale', $movement->reference_type);
        $this->assertSame($sale->sale_ulid, $movement->reference_id);

        Passport::actingAs($customerUser->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/customer/credits')
            ->assertOk()
            ->assertJsonPath('meta.accounts_count', 1)
            ->assertJsonPath('meta.accounts.0.current_balance', '1200.0000');
        $this->getJson("/api/v1/amial/customer/credits/{$account->id}/statement")
            ->assertOk()
            ->assertJsonPath('meta.movements.0.reference_number', '#' . substr($sale->sale_ulid, -8));
    }

    /** @test */
    public function pharmacy_credit_sale_without_a_customer_identity_is_rejected_before_sale_or_debt_creation(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'دواء بلا عميل', 'sale_price' => '100',
        ]);
        $this->svc->addBatch($product, [
            'batch_number' => 'CREDIT-NO-CUSTOMER', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 2,
        ]);

        try {
            $this->saleSvc->recordSale(
                $this->merchant, $this->pharmacy, null,
                [['product_id' => $product->id, 'quantity' => 1]],
                ['payment_method' => 'credit'],
            );
            $this->fail('لا يجوز تسجيل بيع آجل بلا هوية العميل');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('رقم العميل مطلوب للبيع الآجل', $e->getMessage());
        }

        $this->assertSame(0, \App\Models\PharmacySale::count());
        $this->assertSame(0, CustomerCreditAccount::count());
    }

    /** @test */
    public function sale_rejects_insufficient_stock(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'X', 'sale_price' => '100',
        ]);
        $this->svc->addBatch($product, [
            'batch_number' => 'B',
            'expiry_date' => now()->addMonth()->toDateString(),
            'quantity_received' => 3,
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('غير كاف');
        $this->saleSvc->recordSale(
            $this->merchant, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 5]],
            ['payment_method' => 'cash'],
        );
    }

    /** @test */
    public function product_requiring_prescription_needs_prescription_number(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'Antibiotic',
            'sale_price' => '2000',
            'requires_prescription' => true,
        ]);
        $this->svc->addBatch($product, [
            'batch_number' => 'B',
            'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 10,
        ]);

        // بدون وصفة → رفض
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('وصفة');
        $this->saleSvc->recordSale(
            $this->merchant, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 1]],
            ['payment_method' => 'cash'],
        );
    }

    /** @test */
    public function prescription_drug_succeeds_with_prescription_number(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'Antibiotic', 'sale_price' => '2000', 'requires_prescription' => true,
        ]);
        $this->svc->addBatch($product, [
            'batch_number' => 'B', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 10,
        ]);

        $sale = $this->saleSvc->recordSale(
            $this->merchant, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 1]],
            [
                'payment_method' => 'cash',
                'prescription_number' => 'RX-2026-001',
                'prescribing_doctor' => 'د. أحمد',
            ],
        );
        $this->assertSame('RX-2026-001', $sale->prescription_number);
    }

    // ============ Allergy Warnings ============

    /** @test */
    public function customer_with_penicillin_allergy_blocks_augmentin_sale(): void
    {
        $customer = $this->svc->addCustomer($this->pharmacy, [
            'full_name' => 'محمد علي',
            'phone' => '+967700111',
            'allergies' => ['Penicillin'],
        ]);
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'Augmentin (Penicillin)',
            'generic_name' => 'Amoxicillin Penicillin',
            'sale_price' => '1500',
        ]);
        $this->svc->addBatch($product, [
            'batch_number' => 'B', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 10,
        ]);

        // بدون warnings_acknowledged → رفض
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('حساسية');
        $this->saleSvc->recordSale(
            $this->merchant, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 1]],
            [
                'payment_method' => 'cash',
                'customer_id' => $customer->id,
            ],
        );
    }

    /** @test */
    public function allergy_warning_can_be_overridden_with_acknowledgment(): void
    {
        $customer = $this->svc->addCustomer($this->pharmacy, [
            'full_name' => 'محمد',
            'allergies' => ['Penicillin'],
        ]);
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'Augmentin (Penicillin)', 'sale_price' => '1500',
        ]);
        $this->svc->addBatch($product, [
            'batch_number' => 'B', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 10,
        ]);

        // مع acknowledged → نجاح
        $sale = $this->saleSvc->recordSale(
            $this->merchant, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 1]],
            [
                'payment_method' => 'cash',
                'customer_id' => $customer->id,
                'warnings_acknowledged' => ['Augmentin (Penicillin)'],
            ],
        );
        $this->assertNotNull($sale->warnings_acknowledged);
    }

    // ============ Alerts ============

    /** @test */
    public function low_stock_alert_is_created_after_sale(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'Med', 'sale_price' => '100',
            'low_stock_threshold' => 5,
        ]);
        $this->svc->addBatch($product, [
            'batch_number' => 'B', 'expiry_date' => now()->addYear()->toDateString(),
            'quantity_received' => 7,
        ]);

        // بِع 5 → المتبقّي 2 → low_stock
        $this->saleSvc->recordSale(
            $this->merchant, $this->pharmacy, null,
            [['product_id' => $product->id, 'quantity' => 5]],
            ['payment_method' => 'cash'],
        );

        $alert = PharmacyStockAlert::where('product_id', $product->id)
            ->where('alert_type', 'low_stock')->first();
        $this->assertNotNull($alert);
        $this->assertSame('warning', $alert->severity);
    }

    /** @test */
    public function expired_batches_are_detected_by_scan(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, [
            'trade_name' => 'X', 'sale_price' => '100',
        ]);
        // أنشئ batch ثم اجعله منتهياً مباشرة بتعديل DB
        $batch = $this->svc->addBatch($product, [
            'batch_number' => 'EXPIRED',
            'expiry_date' => now()->addDay()->toDateString(),
            'quantity_received' => 10,
        ]);
        // ادفع التاريخ للماضي يدوياً (لتجاوز validation)
        PharmacyBatch::where('id', $batch->id)
            ->update(['expiry_date' => now()->subDays(5)->toDateString()]);

        $results = $this->alertSvc->scanExpiringBatches($this->pharmacy);
        $this->assertSame(1, $results['expired']);

        $batch->refresh();
        $this->assertSame('expired', $batch->status);

        // تنبيه أُنشئ
        $alert = PharmacyStockAlert::where('batch_id', $batch->id)
            ->where('alert_type', 'expired')->first();
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->severity);
    }

    /** @test */
    public function near_expiry_batches_are_flagged(): void
    {
        $product = $this->svc->addProduct($this->pharmacy, ['trade_name' => 'X', 'sale_price' => '100']);
        $this->svc->addBatch($product, [
            'batch_number' => 'NEAR',
            'expiry_date' => now()->addDays(15)->toDateString(), // 15 يوم
            'quantity_received' => 5,
        ]);

        $results = $this->alertSvc->scanExpiringBatches($this->pharmacy);
        $this->assertSame(1, $results['near_expiry']);
    }
}
