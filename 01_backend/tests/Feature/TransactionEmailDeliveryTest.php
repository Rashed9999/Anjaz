<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionEmailJob;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NotificationDeliveryLogService;
use App\Services\TransactionEmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AMIAL-TRANSACTION-EMAIL-DELIVERY-001
 *
 * تتحقق هذه الرحلة من التعاقد كاملاً بدون مزود حي: إنشاء صف العملية، إدخاله
 * إلى queue الإشعارات، طلب Resend الآمن، ثم سجل القبول الذي تراه لوحة الإدارة.
 */
class TransactionEmailDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_a_verified_customer_transaction_is_queued_on_the_notifications_queue(): void
    {
        $transaction = $this->transactionFor($this->verifiedCustomer());

        Queue::assertPushed(
            SendTransactionEmailJob::class,
            fn (SendTransactionEmailJob $job, ?string $queue) =>
                $job->transactionDbId === $transaction->id && $queue === 'notifications',
        );
    }

    public function test_the_job_sends_one_idempotent_receipt_and_records_provider_acceptance(): void
    {
        config([
            'amial_transaction_mail.enabled' => true,
            'amial_transaction_mail.resend.api_key' => 're_test_receipts',
            'amial_transaction_mail.resend.api_url' => 'https://resend.test/emails',
            'amial_transaction_mail.from_address' => 'receipts@example.test',
            'amial_transaction_mail.from_name' => 'Amial Pay Receipts',
        ]);
        Http::fake([
            'https://resend.test/emails' => Http::response(['id' => 'email_provider_123'], 201),
        ]);

        $customer = $this->verifiedCustomer();
        $transaction = $this->transactionFor($customer);

        (new SendTransactionEmailJob($transaction->id))->handle(
            app(TransactionEmailService::class),
            app(NotificationDeliveryLogService::class),
        );

        Http::assertSent(function (Request $request) use ($customer, $transaction): bool {
            $payload = $request->data();

            return $request->url() === 'https://resend.test/emails'
                && $request->hasHeader('Authorization', 'Bearer re_test_receipts')
                && $request->hasHeader('Idempotency-Key', 'transaction-receipt/' . $transaction->id)
                && ($payload['to'][0] ?? null) === $customer->email
                && ($payload['headers']['X-Amial-Category'] ?? null) === 'transaction-receipt'
                && ($payload['headers']['X-Amial-Transaction'] ?? null) === $transaction->transaction_id
                && str_contains((string) ($payload['subject'] ?? ''), 'إيصال أميال باي');
        });

        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $customer->id,
            'channel' => 'email',
            'transaction_id' => $transaction->transaction_id,
            'status' => 'provider_accepted',
            'provider_message_id' => 'email_provider_123',
            'http_status' => 201,
        ]);
    }

    public function test_missing_provider_credentials_is_visible_as_a_safe_skip_not_a_false_delivery(): void
    {
        config([
            'amial_transaction_mail.enabled' => true,
            'amial_transaction_mail.resend.api_key' => '',
        ]);
        Http::fake();

        $customer = $this->verifiedCustomer();
        $transaction = $this->transactionFor($customer);

        (new SendTransactionEmailJob($transaction->id))->handle(
            app(TransactionEmailService::class),
            app(NotificationDeliveryLogService::class),
        );

        Http::assertNothingSent();
        $this->assertDatabaseHas('notification_delivery_logs', [
            'user_id' => $customer->id,
            'channel' => 'email',
            'transaction_id' => $transaction->transaction_id,
            'status' => 'skipped',
            'error_code' => 'RESEND_API_KEY_MISSING',
        ]);
    }

    private function verifiedCustomer(): User
    {
        return User::factory()->create([
            'type' => 2,
            'email' => 'customer-' . bin2hex(random_bytes(6)) . '@example.test',
            'is_email_verified' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function transactionFor(User $customer): Transaction
    {
        return Transaction::create([
            'user_id' => $customer->id,
            'transaction_id' => 'TXN-' . bin2hex(random_bytes(8)),
            'transaction_type' => 'send_money',
            'debit' => '1250.0000',
            'credit' => '0.0000',
            'charge' => '10.0000',
            'amount' => '1250.0000',
            'balance' => '3740.0000',
        ]);
    }
}
