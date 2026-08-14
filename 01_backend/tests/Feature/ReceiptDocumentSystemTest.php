<?php

namespace Tests\Feature;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\Receipt;
use App\Models\Retail\SaleLine;
use App\Models\User;
use App\Services\ReceiptDocumentService;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** AMIAL-DOCUMENTS-001 - حقيقة المستند، فصل السند عن الفاتورة، وإعادة الطباعة. */
class ReceiptDocumentSystemTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $type, string $phone): User
    {
        return User::factory()->create([
            'type' => $type,
            'phone' => $phone,
            'zone_code' => 'SOUTH',
        ]);
    }

    private function receipt(User $owner, ?User $counterparty = null, array $overrides = []): Receipt
    {
        return Receipt::create(array_merge([
            'receipt_number' => now()->format('ymd') . random_int(100000, 999999),
            'verification_code' => str_repeat('7', 16),
            'receipt_type' => 'send_money',
            'user_id' => $owner->id,
            'counterparty_user_id' => $counterparty?->id,
            'reference_transaction_id' => 'TX-DOC-' . random_int(1000, 9999),
            'amount' => '5000.0000',
            'fee' => '50.0000',
            'net_amount' => '5050.0000',
            'direction' => 'debit',
            'status' => 'pending_pdf',
            'zone_code' => 'SOUTH',
            'issued_at' => now(),
        ], $overrides));
    }

    /** @test التحويل والإيداع والسحب سندات مالية، لا فواتير بيع. */
    public function wallet_movements_are_vouchers_not_invoices(): void
    {
        $owner = $this->user(CUSTOMER_TYPE, '967770071001');
        $other = $this->user(CUSTOMER_TYPE, '967770071002');
        $documents = app(ReceiptDocumentService::class);

        foreach ([
            'send_money' => ['سند تحويل أموال', '7000000000000001'],
            'cash_in' => ['سند إيداع نقدي', '7000000000000002'],
            'cash_out' => ['سند سحب نقدي', '7000000000000003'],
        ] as $type => [$title, $verificationCode]) {
            $document = $documents->build($this->receipt($owner, $other, [
                'receipt_type' => $type,
                'verification_code' => $verificationCode,
            ]));

            $this->assertSame('wallet_voucher', $document['kind']);
            $this->assertSame($title, $document['title']);
            $this->assertSame([], $document['items']);
        }
    }

    /** @test فاتورة التجزئة تقرأ snapshot الأسطر من قاعدة البيانات، لا metadata مرسلة من واجهة. */
    public function retail_invoice_uses_authoritative_sale_lines(): void
    {
        $customer = $this->user(CUSTOMER_TYPE, '967770071011');
        $merchantUser = $this->user(MERCHANT_TYPE, '967770071012');
        MerchantProfile::create([
            'user_id' => $merchantUser->id,
            'business_type' => 'retail',
            'verification_status' => 'verified',
        ]);
        $merchant = new Merchant();
        $merchant->user_id = $merchantUser->id;
        $merchant->store_name = 'متجر الوثيقة';
        $merchant->merchant_number = 'M-DOC-1';
        $merchant->save();

        $sale = MerchantSale::create([
            'sale_ulid' => '01JDOCTEST000000000000001',
            'merchant_user_id' => $merchantUser->id,
            'total_amount' => '3800.0000',
            'discount_amount' => '200.0000',
            'payment_method' => 'amial_pay',
            'status' => 'completed',
            'paid_transaction_id' => 'TX-RETAIL-DOC',
            'zone_code' => 'SOUTH',
        ]);
        SaleLine::create([
            'uuid' => '00000000-0000-4000-8000-000000000001',
            'merchant_user_id' => $merchantUser->id,
            'sale_id' => $sale->id,
            'sale_ulid' => $sale->sale_ulid,
            'name' => 'أرز 5 كجم',
            'barcode' => '628100000001',
            'quantity' => '2.000',
            'unit_price' => '2000.0000',
            'line_discount' => '100.0000',
            'line_total' => '3800.0000',
            'cost_source' => 'unknown',
            'returned_quantity' => '0',
            'zone_code' => 'SOUTH',
        ]);

        $receipt = $this->receipt($customer, $merchantUser, [
            'receipt_type' => 'pos_payment',
            'reference_transaction_id' => 'TX-RETAIL-DOC',
            'reference_type' => 'merchant_sale',
            'reference_id' => $sale->id,
            'amount' => '3800.0000',
            'fee' => '0.0000',
            'net_amount' => '3800.0000',
            // محاولة بيانات مزيفة يجب ألا تصبح مصدر الفاتورة.
            'metadata' => ['items' => [['name' => 'صنف مزيف', 'qty' => 99]]],
        ]);

        $document = app(ReceiptDocumentService::class)->build($receipt);

        $this->assertSame('merchant_invoice', $document['kind']);
        $this->assertSame('retail', $document['vertical']);
        $this->assertSame('أرز 5 كجم', $document['items'][0]['name']);
        $this->assertSame('628100000001', $document['items'][0]['sku']);
        $this->assertSame('3800.0000', $document['total']);
        $this->assertStringNotContainsString('مزيف', json_encode($document['items'], JSON_UNESCAPED_UNICODE));

        $descriptor = app(ReceiptDocumentService::class)->descriptor($receipt);
        $this->assertSame('أرز 5 كجم', $descriptor['thermal_print']['lines'][0]['name']);
        $this->assertSame('3800.0000', $descriptor['thermal_print']['lines'][0]['line_total']);
    }

    /** @test كل قطاع يختار هوية فاتورته حتى في الدفع بمبلغ حر بلا أصناف. */
    public function all_supported_merchant_verticals_have_distinct_invoice_titles(): void
    {
        $customer = $this->user(CUSTOMER_TYPE, '967770071013');
        $documents = app(ReceiptDocumentService::class);

        $suffix = 0;
        foreach ([
            'quick_sale' => 'فاتورة بيع سريع',
            'retail' => 'فاتورة بيع بالتجزئة',
            'fuel' => 'فاتورة بيع وقود',
            'pharmacy' => 'فاتورة صيدلية',
            'wholesale' => 'فاتورة بيع جملة',
            'restaurant' => 'فاتورة مطعم',
        ] as $vertical => $title) {
            $suffix++;
            $merchant = $this->user(MERCHANT_TYPE, '9677700720' . str_pad((string) $suffix, 2, '0', STR_PAD_LEFT));
            MerchantProfile::create([
                'user_id' => $merchant->id,
                'business_type' => $vertical,
                'verification_status' => 'verified',
            ]);

            $document = $documents->build($this->receipt($customer, $merchant, [
                'receipt_type' => 'pay_merchant',
                'verification_code' => '710000000000000' . $suffix,
                'reference_transaction_id' => 'TX-VERTICAL-' . $merchant->id,
                'amount' => '1250.0000',
                'fee' => '0.0000',
                'net_amount' => '1250.0000',
            ]));

            $this->assertSame('merchant_invoice', $document['kind']);
            $this->assertSame($vertical, $document['vertical']);
            $this->assertSame($title, $document['title']);
            $this->assertSame('دفع مشتريات عبر أميال باي', $document['items'][0]['name']);
        }
    }

    /** @test مرجع بيع لا يسمح بتسريب أصناف تاجر آخر. */
    public function a_cross_merchant_sale_reference_is_never_used_as_invoice_source(): void
    {
        $customer = $this->user(CUSTOMER_TYPE, '967770071014');
        $merchantA = $this->user(MERCHANT_TYPE, '967770071015');
        $merchantB = $this->user(MERCHANT_TYPE, '967770071016');
        MerchantProfile::create([
            'user_id' => $merchantA->id,
            'business_type' => 'retail',
            'verification_status' => 'verified',
        ]);

        $foreignSale = MerchantSale::create([
            'sale_ulid' => '01JDOCFOREIGN0000000000001',
            'merchant_user_id' => $merchantB->id,
            'total_amount' => '9999.0000',
            'discount_amount' => '0.0000',
            'payment_method' => 'amial_pay',
            'status' => 'completed',
            'paid_transaction_id' => 'TX-FOREIGN-SALE',
            'zone_code' => 'SOUTH',
        ]);
        SaleLine::create([
            'uuid' => '00000000-0000-4000-8000-000000000002',
            'merchant_user_id' => $merchantB->id,
            'sale_id' => $foreignSale->id,
            'sale_ulid' => $foreignSale->sale_ulid,
            'name' => 'صنف سري لتاجر آخر',
            'quantity' => '1.000',
            'unit_price' => '9999.0000',
            'line_discount' => '0.0000',
            'line_total' => '9999.0000',
            'cost_source' => 'unknown',
            'returned_quantity' => '0.000',
            'zone_code' => 'SOUTH',
        ]);

        $receipt = $this->receipt($customer, $merchantA, [
            'receipt_type' => 'pos_payment',
            'verification_code' => '7200000000000001',
            'reference_transaction_id' => 'TX-CUSTOMER-A',
            'reference_type' => 'merchant_sale',
            'reference_id' => $foreignSale->id,
            'amount' => '500.0000',
            'fee' => '0.0000',
            'net_amount' => '500.0000',
        ]);

        $document = app(ReceiptDocumentService::class)->build($receipt);
        $this->assertSame('دفع مشتريات عبر أميال باي', $document['items'][0]['name']);
        $this->assertStringNotContainsString('سري', json_encode($document['items'], JSON_UNESCAPED_UNICODE));
    }

    /** @test رسم التاجر لا يظهر كخصم إضافي على العميل. */
    public function merchant_fee_is_borne_by_the_receiver_receipt(): void
    {
        Queue::fake();
        $customer = $this->user(CUSTOMER_TYPE, '967770071021');
        $merchant = $this->user(MERCHANT_TYPE, '967770071022');

        [$customerReceipt, $merchantReceipt] = app(ReceiptService::class)->issueDualForTransfer([
            'from_user_id' => $customer->id,
            'to_user_id' => $merchant->id,
            'reference_transaction_id' => 'TX-MERCHANT-FEE-DOC',
            'receipt_type' => 'pos_payment',
            'amount' => '10000.0000',
            'fee' => '200.0000',
            'fee_bearer' => 'receiver',
        ]);

        $this->assertSame('0.0000', (string) $customerReceipt->fee);
        $this->assertSame('10000.0000', (string) $customerReceipt->net_amount);
        $this->assertSame('200.0000', (string) $merchantReceipt->fee);
        $this->assertSame('9800.0000', (string) $merchantReceipt->net_amount);
    }

    /** @test وصف API يعلن المقاسات الثلاثة بوضوح. */
    public function document_descriptor_advertises_a4_and_both_thermal_sizes(): void
    {
        $owner = $this->user(CUSTOMER_TYPE, '967770071041');
        $document = app(ReceiptDocumentService::class)->descriptor($this->receipt($owner));

        $this->assertSame(['a4', 'thermal_80', 'thermal_58'], array_column($document['formats'], 'code'));
        $this->assertSame('wallet_voucher', $document['thermal_print']['kind']);
        $this->assertSame('5050.0000', $document['thermal_print']['final_amount']);
    }
}
