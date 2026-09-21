<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Services\NotificationDeliveryLogService;
use App\Services\TransactionEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-TRANSACTION-EMAIL-001
 *
 * Email is best-effort and always outside the financial transaction. A failed
 * provider must never roll back, repeat or alter money movement.
 */
class SendTransactionEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [15, 90, 300];

    public function __construct(public readonly int $transactionDbId)
    {
        $this->onQueue('notifications');
    }

    public function handle(
        TransactionEmailService $mail,
        NotificationDeliveryLogService $delivery,
    ): void {
        $attempt = max(1, $this->attempts());
        $transaction = Transaction::with('user')->find($this->transactionDbId);

        if (!$transaction || !$transaction->user) {
            return;
        }

        $user = $transaction->user;
        if ((int) $user->type !== 2) {
            return;
        }

        if (!(bool) config('amial_transaction_mail.enabled', true)) {
            $delivery->skipped(
                (int) $user->id,
                'TRANSACTION_EMAIL_DISABLED',
                (string) $transaction->transaction_type,
                (string) $transaction->transaction_id,
                $attempt,
                channel: 'email',
            );
            return;
        }

        // Only a verified mailbox may receive financial information.
        if ((int) ($user->is_email_verified ?? 0) !== 1 || empty($user->email_verified_at)) {
            return;
        }

        $email = trim((string) $user->email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $delivery->skipped(
                (int) $user->id,
                'CUSTOMER_EMAIL_INVALID',
                (string) $transaction->transaction_type,
                (string) $transaction->transaction_id,
                $attempt,
                channel: 'email',
            );
            return;
        }

        if (trim((string) config('amial_transaction_mail.resend.api_key', '')) === '') {
            $delivery->skipped(
                (int) $user->id,
                'RESEND_API_KEY_MISSING',
                (string) $transaction->transaction_type,
                (string) $transaction->transaction_id,
                $attempt,
                channel: 'email',
            );
            return;
        }

        try {
            $sent = $mail->send($transaction, $user);

            $delivery->accepted(
                (int) $user->id,
                (string) $transaction->transaction_type,
                (string) $transaction->transaction_id,
                $sent['provider_id'],
                $sent['http_status'],
                $attempt,
                channel: 'email',
            );
        } catch (\Throwable $e) {
            $code = preg_match('/^[A-Z0-9_]+$/', $e->getMessage())
                ? $e->getMessage()
                : 'TRANSACTION_EMAIL_TRANSPORT_ERROR';

            $httpStatus = null;
            if (preg_match('/^RESEND_HTTP_(\d{3})$/', $code, $m)) {
                $httpStatus = (int) $m[1];
            }

            $delivery->failed(
                (int) $user->id,
                $code,
                null, // provider errors can contain request data; never persist it.
                (string) $transaction->transaction_type,
                (string) $transaction->transaction_id,
                $httpStatus,
                $attempt,
                channel: 'email',
            );

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $transaction = Transaction::with('user')->find($this->transactionDbId);
        if (!$transaction || !$transaction->user || (int) $transaction->user->type !== 2) {
            return;
        }

        Log::error('SendTransactionEmailJob permanently failed', [
            'transaction_db_id' => $this->transactionDbId,
            'transaction_id' => $transaction->transaction_id,
            'user_id' => $transaction->user_id,
            'error_code' => preg_match('/^[A-Z0-9_]+$/', $exception->getMessage())
                ? $exception->getMessage()
                : 'TRANSACTION_EMAIL_TRANSPORT_ERROR',
        ]);

        app(NotificationDeliveryLogService::class)->failed(
            (int) $transaction->user_id,
            'TRANSACTION_EMAIL_PERMANENT_FAILURE',
            null,
            (string) $transaction->transaction_type,
            (string) $transaction->transaction_id,
            null,
            $this->tries,
            true,
            channel: 'email',
        );
    }
}
