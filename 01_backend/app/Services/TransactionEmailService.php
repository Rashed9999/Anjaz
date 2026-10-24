<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * AMIAL-TRANSACTION-EMAIL-001
 *
 * Builds and delivers one customer-facing email receipt from the committed
 * transactions row. No wallet mutation happens here.
 */
class TransactionEmailService
{
    /**
     * @return array{provider_id:string,http_status:int}
     */
    public function send(Transaction $transaction, User $user): array
    {
        $apiKey = trim((string) config('amial_transaction_mail.resend.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('RESEND_API_KEY_MISSING');
        }

        $email = mb_strtolower(trim((string) $user->email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('CUSTOMER_EMAIL_INVALID');
        }

        $payload = $this->payload($transaction, $user);
        $html = view('emails.amial-transaction-receipt', $payload)->render();
        $text = view('emails.amial-transaction-receipt-text', $payload)->render();

        $apiUrl = (string) config(
            'amial_transaction_mail.resend.api_url',
            'https://api.resend.com/emails'
        );
        $fromAddress = (string) config(
            'amial_transaction_mail.from_address',
            'receipts@amialpay.com'
        );
        $fromName = (string) config(
            'amial_transaction_mail.from_name',
            'أميال باي | إيصالات المعاملات'
        );

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->withHeaders([
                // A retry must never create a second customer receipt.
                'Idempotency-Key' => 'transaction-receipt/' . $transaction->id,
            ])
            ->timeout(12)
            ->retry(2, 250, throw: false)
            ->post($apiUrl, [
                'from' => $fromName . ' <' . $fromAddress . '>',
                'to' => [$email],
                'subject' => $payload['subject'],
                'text' => $text,
                'html' => $html,
                'headers' => [
                    'X-Amial-Category' => 'transaction-receipt',
                    'X-Amial-Transaction' => (string) $transaction->transaction_id,
                ],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('RESEND_HTTP_' . $response->status());
        }

        $providerId = (string) ($response->json('id') ?? '');
        if ($providerId === '') {
            throw new RuntimeException('RESEND_MESSAGE_ID_MISSING');
        }

        return [
            'provider_id' => $providerId,
            'http_status' => $response->status(),
        ];
    }

    /** @return array<string,mixed> */
    private function payload(Transaction $transaction, User $user): array
    {
        $amount = $this->amount($transaction);
        $fee = is_numeric($transaction->charge) ? (string) $transaction->charge : '0';
        $debit = is_numeric($transaction->debit) ? (string) $transaction->debit : '0';
        $credit = is_numeric($transaction->credit) ? (string) $transaction->credit : '0';
        $direction = bccomp($debit, '0', 4) > 0 ? 'debit' : 'credit';
        $typeLabel = $this->typeLabel((string) $transaction->transaction_type);
        $reference = trim((string) ($transaction->transaction_no ?? ''));
        if ($reference === '') {
            $reference = trim((string) $transaction->transaction_id);
        }

        $counterparty = $this->counterparty($transaction, (int) $user->id);
        $date = $transaction->created_at
            ? $transaction->created_at->copy()->timezone(config('app.timezone'))->format('Y-m-d H:i')
            : now()->format('Y-m-d H:i');

        return [
            'subject' => $typeLabel . ' — إيصال أميال باي',
            'customerName' => $this->customerName($user),
            'typeLabel' => $typeLabel,
            'directionLabel' => $direction === 'debit' ? 'خصم من الحساب' : 'إضافة إلى الحساب',
            'amount' => $this->money($amount),
            'fee' => bccomp($fee, '0', 4) > 0 ? $this->money($fee) : null,
            'balance' => is_numeric($transaction->balance) ? $this->money((string) $transaction->balance) : null,
            'counterparty' => $counterparty,
            'reference' => $reference !== '' ? $reference : '—',
            'date' => $date,
            'websiteUrl' => rtrim((string) config('amial_transaction_mail.website_url', 'https://amialpay.com'), '/'),
        ];
    }

    private function amount(Transaction $transaction): string
    {
        if (is_numeric($transaction->amount) && bccomp((string) $transaction->amount, '0', 4) > 0) {
            return (string) $transaction->amount;
        }
        if (is_numeric($transaction->debit) && bccomp((string) $transaction->debit, '0', 4) > 0) {
            return (string) $transaction->debit;
        }
        return is_numeric($transaction->credit) ? (string) $transaction->credit : '0';
    }

    private function customerName(User $user): string
    {
        $parts = array_filter([
            trim((string) ($user->f_name ?? '')),
            trim((string) ($user->father_name ?? '')),
            trim((string) ($user->grandfather_name ?? '')),
            trim((string) ($user->family_name ?? $user->l_name ?? '')),
        ], fn (string $part) => $part !== '');

        return $parts !== [] ? implode(' ', $parts) : 'عميل أميال باي';
    }

    private function counterparty(Transaction $transaction, int $userId): ?string
    {
        $counterpartyId = null;
        if ((int) ($transaction->from_user_id ?? 0) === $userId) {
            $counterpartyId = (int) ($transaction->to_user_id ?? 0);
        } elseif ((int) ($transaction->to_user_id ?? 0) === $userId) {
            $counterpartyId = (int) ($transaction->from_user_id ?? 0);
        }

        if (!$counterpartyId || $counterpartyId === $userId) {
            return null;
        }

        $other = User::find($counterpartyId);
        if (!$other) {
            return null;
        }

        $name = trim(
            (string) ($other->f_name ?? '') . ' ' .
            (string) ($other->family_name ?? $other->l_name ?? '')
        );

        return $name !== '' ? preg_replace('/\s+/u', ' ', $name) : null;
    }

    private function typeLabel(string $type): string
    {
        return match (mb_strtolower(trim($type))) {
            'cash_out', 'withdraw' => 'سحب نقدي',
            'cash_in', 'add_money' => 'إيداع نقدي',
            'send_money' => 'تحويل أموال',
            'received_money' => 'تحويل وارد',
            'merchant_payment', 'pay_merchant' => 'دفع للتاجر',
            'pos_payment' => 'دفع عبر نقطة بيع',
            'qr_payment' => 'دفع عبر QR',
            'debt_payment', 'credit_payment' => 'سداد آجل',
            'bill_payment' => 'سداد فاتورة',
            'refund', 'reversal' => 'استرجاع مبلغ',
            'safe_payment_funded' => 'حجز مبلغ للدفع الآمن',
            'safe_payment_released' => 'تحرير دفع آمن',
            'safe_payment_refunded' => 'استرجاع دفع آمن',
            'family_fund_contribute' => 'مساهمة في الصندوق العائلي',
            'donation' => 'تبرع',
            default => 'معاملة مالية',
        };
    }

    private function money(string $amount): string
    {
        $formatted = number_format((float) $amount, 2, '.', ',');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted . ' ر.ي';
    }
}
