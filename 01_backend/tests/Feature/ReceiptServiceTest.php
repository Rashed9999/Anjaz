<?php

namespace Tests\Feature;

use App\Jobs\GeneratePdfReceiptJob;
use App\Models\Receipt;
use App\Models\User;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-RECEIPTS-001 (v0.9-A) — اختبارات.
 */
class ReceiptServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReceiptService $service;
    private User $sender;
    private User $receiver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReceiptService::class);
        $this->sender = User::factory()->create(['zone_code' => 'SOUTH']);
        $this->receiver = User::factory()->create(['zone_code' => 'SOUTH']);
    }

    /** @test */
    public function it_issues_debit_receipt_with_unique_codes()
    {
        Queue::fake();

        $receipt = $this->service->issueDebit([
            'user_id' => $this->sender->id,
            'counterparty_user_id' => $this->receiver->id,
            'reference_transaction_id' => 'TX_TEST_001',
            'receipt_type' => 'send_money',
            'amount' => '100.0000',
            'fee' => '5.0000',
            'zone_code' => 'SOUTH',
        ]);

        $this->assertInstanceOf(Receipt::class, $receipt);
        $this->assertEquals('debit', $receipt->direction);
        $this->assertEquals('100.0000', (string)$receipt->amount);
        $this->assertEquals('5.0000', (string)$receipt->fee);
        $this->assertEquals('105.0000', (string)$receipt->net_amount); // amount + fee
        $this->assertEquals('pending_pdf', $receipt->status);

        // AMIAL-RECEIPT-NUMBERS-001: الشكلان صارا رقميَّين بحتَين.
        // كان الكود 16 حرفاً Base32 والرقم AMY-YYYYMMDD-XXXXXXXX — ولا
        // واحد منهما يستطيع عميل إملاءه على الهاتف لموظّف الدعم، وهو
        // الغرض الأول من رقم الإشعار.
        $this->assertMatchesRegularExpression('/^[0-9]{16}$/', $receipt->verification_code);

        // رقم الإشعار: YYMMDD + 6 أرقام (التاريخ في الصدر يجعله مرتّباً)
        $this->assertMatchesRegularExpression('/^[0-9]{12}$/', $receipt->receipt_number);
        $this->assertStringStartsWith(now()->format('ymd'), $receipt->receipt_number);
    }

    /** @test */
    public function it_dispatches_pdf_generation_job()
    {
        Queue::fake();

        $this->service->issueCredit([
            'user_id' => $this->receiver->id,
            'reference_transaction_id' => 'TX_TEST_002',
            'receipt_type' => 'send_money',
            'amount' => '50.0000',
        ]);

        Queue::assertPushedOn('receipts', GeneratePdfReceiptJob::class);
    }

    /** @test */
    public function credit_receipt_has_no_fee_in_net_amount()
    {
        Queue::fake();

        $receipt = $this->service->issueCredit([
            'user_id' => $this->receiver->id,
            'reference_transaction_id' => 'TX_TEST_003',
            'receipt_type' => 'send_money',
            'amount' => '100.0000',
            'fee' => '0',
        ]);

        $this->assertEquals('credit', $receipt->direction);
        $this->assertEquals('100.0000', (string)$receipt->amount);
        // Credit: المستلم لا يدفع رسوم → net_amount = amount
        $this->assertEquals('100.0000', (string)$receipt->net_amount);
    }

    /** @test */
    public function dual_transfer_issues_two_receipts_with_different_directions()
    {
        Queue::fake();

        [$debitReceipt, $creditReceipt] = $this->service->issueDualForTransfer([
            'from_user_id' => $this->sender->id,
            'to_user_id' => $this->receiver->id,
            'reference_transaction_id' => 'TX_DUAL_001',
            'receipt_type' => 'send_money',
            'amount' => '200.0000',
            'fee' => '3.0000',
        ]);

        $this->assertEquals('debit', $debitReceipt->direction);
        $this->assertEquals($this->sender->id, $debitReceipt->user_id);
        $this->assertEquals($this->receiver->id, $debitReceipt->counterparty_user_id);
        $this->assertEquals('203.0000', (string)$debitReceipt->net_amount); // 200 + 3

        $this->assertEquals('credit', $creditReceipt->direction);
        $this->assertEquals($this->receiver->id, $creditReceipt->user_id);
        $this->assertEquals($this->sender->id, $creditReceipt->counterparty_user_id);
        $this->assertEquals('200.0000', (string)$creditReceipt->net_amount); // 200 only

        // كل receipt له verification_code مختلف
        $this->assertNotEquals($debitReceipt->verification_code, $creditReceipt->verification_code);

        // كل receipt له receipt_number مختلف
        $this->assertNotEquals($debitReceipt->receipt_number, $creditReceipt->receipt_number);

        // ولكن نفس reference_transaction_id (تربط الـ pair)
        $this->assertEquals($debitReceipt->reference_transaction_id, $creditReceipt->reference_transaction_id);

        // Queue assertion: dual transfer dispatches 2 jobs
        Queue::assertPushed(GeneratePdfReceiptJob::class, 2);
    }

    /** @test */
    public function verification_codes_are_globally_unique_across_thousand_receipts()
    {
        Queue::fake();

        $codes = [];
        for ($i = 0; $i < 100; $i++) {
            $receipt = $this->service->issueDebit([
                'user_id' => $this->sender->id,
                'reference_transaction_id' => "TX_UNIQ_{$i}",
                'receipt_type' => 'fee_charge',
                'amount' => '1.0000',
            ]);
            $codes[] = $receipt->verification_code;
        }

        // 100 unique codes
        $this->assertCount(100, array_unique($codes));
    }

    /** @test */
    public function verify_by_code_returns_null_for_pending_receipt()
    {
        Queue::fake(); // Job يُؤجَّل، الـ status يبقى pending_pdf

        $receipt = $this->service->issueDebit([
            'user_id' => $this->sender->id,
            'reference_transaction_id' => 'TX_VERIFY_001',
            'receipt_type' => 'send_money',
            'amount' => '10.0000',
        ]);

        // pending_pdf → verification يجب أن يرجع null (PDF غير جاهز)
        $result = $this->service->verifyByCode($receipt->verification_code);
        $this->assertNull($result);
    }

    /** @test */
    public function verify_by_code_returns_summary_for_generated_receipt()
    {
        Queue::fake();

        $receipt = $this->service->issueDebit([
            'user_id' => $this->sender->id,
            'reference_transaction_id' => 'TX_VERIFY_002',
            'receipt_type' => 'send_money',
            'amount' => '25.0000',
            'fee' => '1.0000',
        ]);

        // علم كأن الـ Job نجح
        $receipt->update(['status' => 'pdf_generated', 'pdf_storage_path' => 'receipts/test.pdf']);

        $result = $this->service->verifyByCode($receipt->verification_code);

        $this->assertIsArray($result);
        $this->assertEquals($receipt->receipt_number, $result['receipt_number']);
        $this->assertEquals('send_money', $result['receipt_type']);
        $this->assertEquals('25.0000', $result['amount']);
        $this->assertTrue($result['is_valid']);

        // لا بيانات حساسة في الـ public response
        $this->assertArrayNotHasKey('user_id', $result);
        $this->assertArrayNotHasKey('counterparty_user_id', $result);
    }

    /** @test */
    public function verify_by_code_returns_null_for_invalid_code()
    {
        $result = $this->service->verifyByCode('AAAAAAAAAAAAAAAA');
        $this->assertNull($result);
    }
}
