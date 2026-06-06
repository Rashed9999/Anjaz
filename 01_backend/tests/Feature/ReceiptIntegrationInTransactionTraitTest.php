<?php

namespace Tests\Feature;

use App\Jobs\GeneratePdfReceiptJob;
use App\Models\EMoney;
use App\Models\Receipt;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-RECEIPTS-001 (v0.9-D) — تحقق أن TransactionTrait يُصدر إيصالات
 * بعد commit ناجح، وأن فشل الإيصال لا يفشل العملية المالية.
 */
class ReceiptIntegrationInTransactionTraitTest extends TestCase
{
    use RefreshDatabase;
    use TransactionTrait; // الـ trait نفسه يُختبر داخل test class

    private User $sender;
    private User $receiver;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->sender = User::factory()->create([
            'zone_code' => 'SOUTH',
            'security_hold_until' => null,
        ]);
        EMoney::create(['user_id' => $this->sender->id, 'current_balance' => '500.0000']);

        $this->receiver = User::factory()->create([
            'zone_code' => 'SOUTH',
            'security_hold_until' => null,
        ]);
        EMoney::create(['user_id' => $this->receiver->id, 'current_balance' => '0.0000']);
    }

    /** @test */
    public function send_money_issues_two_receipts_after_commit()
    {
        $txId = $this->customer_send_money_transaction(
            from_user_id: $this->sender->id,
            to_user_id: $this->receiver->id,
            amount: '100.0000',
            charge: '2.0000',
        );

        $this->assertNotNull($txId);

        // المعاملة المالية ناجحة
        $senderWallet = EMoney::where('user_id', $this->sender->id)->first();
        $this->assertEquals('398.0000', (string)$senderWallet->current_balance); // 500 - 102

        $receiverWallet = EMoney::where('user_id', $this->receiver->id)->first();
        $this->assertEquals('100.0000', (string)$receiverWallet->current_balance);

        // إيصالين تم إنشاؤهما
        $senderReceipt = Receipt::where('user_id', $this->sender->id)->first();
        $receiverReceipt = Receipt::where('user_id', $this->receiver->id)->first();

        $this->assertNotNull($senderReceipt, 'Sender receipt should exist');
        $this->assertNotNull($receiverReceipt, 'Receiver receipt should exist');

        $this->assertEquals('debit', $senderReceipt->direction);
        $this->assertEquals('credit', $receiverReceipt->direction);

        $this->assertEquals('send_money', $senderReceipt->receipt_type);
        $this->assertEquals($txId, $senderReceipt->reference_transaction_id);
        $this->assertEquals($txId, $receiverReceipt->reference_transaction_id);

        // PDF Jobs مُدفَعَين (واحد لكل receipt)
        Queue::assertPushed(GeneratePdfReceiptJob::class, 2);
    }

    /** @test */
    public function failed_send_money_does_not_create_receipts()
    {
        // محاولة send بمبلغ أكبر من الرصيد
        $this->expectException(\App\Exceptions\InsufficientBalanceException::class);

        try {
            $this->customer_send_money_transaction(
                from_user_id: $this->sender->id,
                to_user_id: $this->receiver->id,
                amount: '1000.0000', // أكثر من 500
                charge: '0',
            );
        } finally {
            // لا إيصالات
            $this->assertEquals(0, Receipt::count());

            // المحفظة لم تتغير
            $senderWallet = EMoney::where('user_id', $this->sender->id)->first();
            $this->assertEquals('500.0000', (string)$senderWallet->current_balance);
        }
    }

    /** @test */
    public function safe_issue_receipts_swallows_exceptions_silently()
    {
        // محاكاة فشل ReceiptService — العملية المالية يجب أن تظل ناجحة
        $this->app->bind(\App\Services\ReceiptService::class, function () {
            $mock = $this->createMock(\App\Services\ReceiptService::class);
            $mock->method('issueDualForTransfer')
                ->willThrowException(new \RuntimeException('Receipt service broken'));
            return $mock;
        });

        $txId = $this->customer_send_money_transaction(
            from_user_id: $this->sender->id,
            to_user_id: $this->receiver->id,
            amount: '50.0000',
            charge: '0',
        );

        // العملية المالية نجحت
        $this->assertNotNull($txId);

        $senderWallet = EMoney::where('user_id', $this->sender->id)->first();
        $this->assertEquals('450.0000', (string)$senderWallet->current_balance);

        $receiverWallet = EMoney::where('user_id', $this->receiver->id)->first();
        $this->assertEquals('50.0000', (string)$receiverWallet->current_balance);

        // لا إيصالات (الـ service مكسور) - لكن لا exception bubbled up
        $this->assertEquals(0, Receipt::count());
    }
}
