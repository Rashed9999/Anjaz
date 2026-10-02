<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\PharmacySale;
use App\Models\User;
use App\Services\CashierSaleInvoicePdfService;
use App\Services\CashierService;
use App\Services\CustomerCreditService;
use App\Services\PharmacySaleInvoicePdfService;
use App\Services\PharmacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * AMIAL-CUSTOMER-CREDIT-VIEW-001 — العميل يرى فواتيره الآجلة لحظياً:
 * حين يسجّل التاجر بيعاً آجلاً على عميل مسجّل (طابق هاتفه)، يظهر في حساب العميل.
 */
class CustomerCreditViewTest extends TestCase
{
    use RefreshDatabase;

    private User $merchant;
    private User $customer;
    private CustomerCreditService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(CustomerCreditService::class);

        $this->merchant = User::factory()->create(['type' => 3, 'zone_code' => 'SOUTH']);
        MerchantProfile::create(['user_id' => $this->merchant->id, 'verification_status' => 'verified']);
        $mr = new Merchant();
        $mr->user_id = $this->merchant->id;
        $mr->store_name = 'بقالة النور';
        $mr->merchant_number = 'M-77001';
        $mr->address = '—';
        $mr->save();

        $this->customer = User::factory()->create([
            'type' => 2, 'zone_code' => 'SOUTH', 'phone' => '+967771700055',
        ]);
    }

    /** @test بيع آجل على عميل مسجّل → يظهر في «حساباتي الآجلة» ثم في كشف الحساب. */
    public function credit_sale_appears_in_customer_account_and_statement(): void
    {
        // التاجر ينشئ حساب العميل بهاتفه → يُربط تلقائياً بالمستخدم المسجّل
        $account = $this->svc->findOrCreateAccount(
            $this->merchant->id, '+967771700055', 'علي نونو',
        );
        $this->assertSame($this->customer->id, $account->customer_user_id);

        // التاجر يسجّل بيعاً آجلاً 1200
        $this->svc->recordSale($account, '1200', createdBy: $this->merchant->id, referenceNumber: 'INV-1');

        // العميل يرى حساباته الآجلة
        Passport::actingAs($this->customer->fresh(), [], 'api');
        $this->getJson('/api/v1/amial/customer/credits')
            ->assertOk()
            ->assertJsonPath('meta.accounts_count', 1)
            ->assertJsonPath('meta.total_owed', '1200.0000')
            ->assertJsonPath('meta.accounts.0.merchant_name', 'بقالة النور')
            ->assertJsonPath('meta.accounts.0.current_balance', '1200.0000');

        // وكشف الحساب يعرض الفاتورة
        $this->getJson("/api/v1/amial/customer/credits/{$account->id}/statement")
            ->assertOk()
            ->assertJsonPath('meta.movements.0.type', 'sale')
            ->assertJsonPath('meta.movements.0.amount', '1200.0000')
            ->assertJsonPath('meta.movements.0.reference_number', 'INV-1');
    }

    /**
     * @test
     *
     * الفاتورة التي أنشأت الآجل ليست حكراً على شاشة التاجر: صاحب الدَّين
     * يستطيع تنزيل PDF الأصلي، وأي مستخدم آخر يأخذ 404. كما أن مفتاح
     * الكاش يتبدّل بعد السداد حتى لا تبقى نسخة «غير مسددة» معلّقة.
     */
    public function customer_can_download_only_the_pdf_of_their_own_deferred_merchant_sale(): void
    {
        $sale = app(CashierService::class)->recordSale(
            merchant: $this->merchant,
            total: '1200',
            paymentMethod: 'credit',
            items: [['name' => 'سكر', 'qty' => 1, 'price' => '1200']],
            customer: ['name' => 'علي نونو', 'phone' => $this->customer->phone],
            creditDueDate: '2026-12-31',
        );

        $account = \App\Models\CustomerCreditAccount::where(
            'merchant_user_id', $this->merchant->id
        )->where('customer_user_id', $this->customer->id)->firstOrFail();

        $movement = $account->movements()
            ->where('type', 'sale')
            ->where('reference_type', 'merchant_sale')
            ->where('reference_id', $sale->sale_ulid)
            ->firstOrFail();

        Passport::actingAs($this->customer->fresh(), [], 'api');

        $response = $this->get(
            "/api/v1/amial/customer/credits/{$account->id}/invoices/{$movement->movement_ulid}/pdf"
        );
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $pdfSvc = app(CashierSaleInvoicePdfService::class);
        $before = $pdfSvc->cacheKey($sale->fresh());
        $sale->update(['status' => 'credit_paid', 'settled_at' => now()]);
        $after = $pdfSvc->cacheKey($sale->fresh());
        $this->assertNotSame($before, $after,
            'تغيّرت حالة بيع الآجل لكن مفتاح PDF بقي ثابتاً وسيخدم نسخة قديمة');

        $intruder = User::factory()->create([
            'type' => 2, 'zone_code' => 'SOUTH', 'phone' => '+967771700088',
        ]);
        Passport::actingAs($intruder, [], 'api');

        $this->get(
            "/api/v1/amial/customer/credits/{$account->id}/invoices/{$movement->movement_ulid}/pdf"
        )->assertStatus(404);
    }

    /** @test فاتورة الصيدلية الآجلة تفتح للعميل وتتبع المتبقي الحقيقي. */
    public function pharmacy_deferred_invoice_pdf_tracks_unified_credit_remaining(): void
    {
        $pharmacy = app(PharmacyService::class)->getOrCreatePharmacy($this->merchant);
        $sale = PharmacySale::create([
            'sale_ulid' => (string) \Illuminate\Support\Str::ulid(),
            'invoice_number' => 'PH-CR-001',
            'merchant_user_id' => $this->merchant->id,
            'pharmacy_id' => $pharmacy->id,
            'subtotal' => '1200.0000',
            'discount_amount' => '0.0000',
            'total_amount' => '1200.0000',
            'payment_method' => 'credit',
            'status' => 'completed',
            'zone_code' => 'SOUTH',
        ]);

        $account = $this->svc->findOrCreateAccount(
            $this->merchant->id,
            $this->customer->phone,
            'عميل فاتورة صيدلية',
        );
        $movement = $this->svc->recordSale(
            account: $account,
            amount: '1200',
            dueDate: '2026-12-31',
            referenceType: 'pharmacy_sale',
            referenceId: $sale->sale_ulid,
            referenceNumber: $sale->invoice_number,
        );

        $pdfSvc = app(PharmacySaleInvoicePdfService::class);
        $first = $pdfSvc->creditSnapshot($sale->fresh());
        $this->assertSame('unpaid', $first['state']);
        $this->assertSame('1200.0000', $first['remaining']);
        $firstKey = $pdfSvc->cacheKey($sale->fresh());

        Passport::actingAs($this->customer->fresh(), [], 'api');
        $pdf = $this->get(
            "/api/v1/amial/customer/credits/{$account->id}/invoices/{$movement->movement_ulid}/pdf"
        );
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $this->svc->recordPayment(
            account: $account->fresh(),
            amount: '400',
            referenceType: 'debt_payment',
            referenceId: 'PH-PAY-400',
            saleMovementUlid: $movement->movement_ulid,
        );

        $partial = $pdfSvc->creditSnapshot($sale->fresh());
        $this->assertSame('partial', $partial['state']);
        $this->assertSame('800.0000', $partial['remaining']);
        $this->assertNotSame($firstKey, $pdfSvc->cacheKey($sale->fresh()));

        $this->svc->recordPayment(
            account: $account->fresh(),
            amount: '800',
            referenceType: 'debt_payment',
            referenceId: 'PH-PAY-800',
            saleMovementUlid: $movement->movement_ulid,
        );

        $paid = $pdfSvc->creditSnapshot($sale->fresh());
        $this->assertSame('paid', $paid['state']);
        $this->assertSame('0.0000', $paid['remaining']);
        $this->assertSame('0.0000', (string) $account->fresh()->current_balance);
    }

    /** @test لا يرى العميل حساب عميل آخر (عزل). */
    public function customer_cannot_see_another_customers_statement(): void
    {
        $other = User::factory()->create(['type' => 2, 'zone_code' => 'SOUTH', 'phone' => '+967771700099']);
        $account = $this->svc->findOrCreateAccount($this->merchant->id, '+967771700099', 'شخص آخر');
        $this->svc->recordSale($account, '500', createdBy: $this->merchant->id);

        Passport::actingAs($this->customer->fresh(), [], 'api');
        $this->getJson("/api/v1/amial/customer/credits/{$account->id}/statement")
            ->assertStatus(404);
    }

    /**
     * AMIAL-CREDIT-LINK-001 — انحدار: التاجر يكتب الرقم الوطني «777100001»
     * بينما المستخدم مخزَّن «+967777100001». المطابقة الحرفية كانت تفشل فيبقى
     * الحساب غير مربوط، فلا يرى العميل ما عليه ولا يستطيع سداده.
     */
    public function test_credit_account_links_to_user_by_any_phone_form(): void
    {
        $national = ltrim(str_replace('+967', '', $this->customer->phone), '+');

        $account = app(\App\Services\CustomerCreditService::class)->findOrCreateAccount(
            merchantId: $this->merchant->id,
            customerPhone: $national,
            customerName: 'عميل اختبار',
        );

        $this->assertSame(
            $this->customer->id,
            $account->customer_user_id,
            'يجب أن يُربط الحساب بالمستخدم مهما اختلفت صيغة الرقم'
        );
    }

    /**
     * حساب أُنشئ قبل تسجيل العميل يبقى بلا ربط — تُطالب به الشاشة عند أول فتح.
     */
    public function test_unlinked_account_is_claimed_on_first_open(): void
    {
        $orphan = \App\Models\CustomerCreditAccount::create([
            'merchant_user_id' => $this->merchant->id,
            'customer_phone' => $this->customer->phone,
            'customer_user_id' => null,
            'customer_name' => 'عميل قديم',
            'credit_limit' => '0.0000',
            'current_balance' => '2500.0000',
            'classification' => 'bronze',
            'is_active' => true,
        ]);

        $this->actingAs($this->customer, 'api')
            ->getJson('/api/v1/amial/customer/credits')
            ->assertOk();

        $this->assertSame($this->customer->id, $orphan->fresh()->customer_user_id);
    }

    /** @test الفاتورة الآجلة تعرض متبقيها الحقيقي ويثبت السداد الجزئي عليها وحدها. */
    public function deferred_invoices_keep_their_own_remaining_balance_after_a_targeted_partial_payment(): void
    {
        $account = $this->svc->findOrCreateAccount(
            $this->merchant->id, '+967771700055', 'علي نونو',
        );
        $first = $this->svc->recordSale($account, '1000', referenceNumber: 'INV-OLD');
        $second = $this->svc->recordSale($account, '900', referenceNumber: 'INV-NEW');

        // سداد ٤٠٠ للفواتير الجديدة فقط، لا يعاد توزيعها على الأقدم بصمت.
        app(\App\Services\CreditSourceSettlementService::class)
            ->allocate($account->fresh(), '400', $second->movement_ulid);
        $this->svc->recordPayment(
            $account->fresh(), '400', referenceType: 'credit_sale_payment',
            referenceId: $second->movement_ulid,
        );

        Passport::actingAs($this->customer->fresh(), [], 'api');
        $this->getJson("/api/v1/amial/customer/credits/{$account->id}/statement")
            ->assertOk()
            ->assertJsonPath('meta.invoices.0.reference_number', 'INV-OLD')
            ->assertJsonPath('meta.invoices.0.remaining', '1000.0000')
            ->assertJsonPath('meta.invoices.1.reference_number', 'INV-NEW')
            ->assertJsonPath('meta.invoices.1.remaining', '500.0000');
    }
}
