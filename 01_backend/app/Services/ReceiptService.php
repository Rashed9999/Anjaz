<?php

namespace App\Services;

use App\Jobs\GeneratePdfReceiptJob;
use App\Models\Receipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * AMIAL-RECEIPTS-001
 *
 * ReceiptService — مسؤول عن إنشاء الـ receipt records و dispatch PDF jobs.
 *
 * **مبدأ تصميم حاسم:** الـ service يُستدعى من **خارج** DB::transaction للعمليات
 * المالية. الـ TransactionTrait بعد commit ناجح يستدعي `issueFor*()` للطرفين.
 * هذا يضمن:
 *   - لا يفشل الإيصال العملية المالية (إذا فشل الإصدار، العملية ناجحة بالفعل)
 *   - الـ PDF يولّد async (queue) ولا يبطئ HTTP response
 *
 * **آلية الـ verification:**
 *   كل إيصال له `verification_code` (16 chars random). الـ QR على الـ PDF
 *   يحتوي URL: `https://amialpay.com/v/{verification_code}`
 *   عند المسح: endpoint عام يعرض ملخص الإيصال (مبلغ، تاريخ، نوع) — للتحقق
 *   أن الإيصال أصلي وغير مزور.
 */
class ReceiptService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {}

    /**
     * يُصدر إيصالاً للمرسل (debit).
     *
     * @param array $data {
     *   user_id: int,                     // مالك الإيصال
     *   counterparty_user_id: ?int,       // الطرف الآخر
     *   reference_transaction_id: string, // ULID من Transaction
     *   receipt_type: string,             // send_money, cash_out, ...
     *   amount: string,
     *   fee?: string,
     *   reference_type?: string,
     *   reference_id?: int,
     *   metadata?: array,
     *   zone_code?: string,
     * }
     */
    public function issueDebit(array $data): Receipt
    {
        return $this->issue(array_merge($data, ['direction' => 'debit']));
    }

    /**
     * يُصدر إيصالاً للمستلم (credit).
     */
    public function issueCredit(array $data): Receipt
    {
        return $this->issue(array_merge($data, ['direction' => 'credit']));
    }

    /**
     * Helper مبسط: يصدر إيصالين (debit للمرسل + credit للمستلم) لـ peer-to-peer transfers.
     *
     * @return array{0: Receipt, 1: Receipt} [senderReceipt, receiverReceipt]
     */
    public function issueDualForTransfer(array $base): array
    {
        $senderReceipt = $this->issueDebit(array_merge($base, [
            'user_id' => $base['from_user_id'],
            'counterparty_user_id' => $base['to_user_id'],
            'amount' => $base['amount'],
            'fee' => $base['fee'] ?? '0',
        ]));

        $receiverReceipt = $this->issueCredit(array_merge($base, [
            'user_id' => $base['to_user_id'],
            'counterparty_user_id' => $base['from_user_id'],
            'amount' => $base['amount'],
            'fee' => '0', // المستلم لا يدفع رسوم
        ]));

        return [$senderReceipt, $receiverReceipt];
    }

    /**
     * Core method.
     */
    private function issue(array $data): Receipt
    {
        $amount = (string)$data['amount'];
        $fee = (string)($data['fee'] ?? '0');
        $netAmount = $data['direction'] === 'debit'
            ? MoneyService::add($amount, $fee)  // المرسل يدفع amount+fee
            : $amount;                          // المستلم يستلم amount

        $receipt = Receipt::create([
            'receipt_number' => $this->generateReceiptNumber($data['receipt_type']),
            'verification_code' => $this->generateVerificationCode(),
            'receipt_type' => $data['receipt_type'],
            'user_id' => $data['user_id'],
            'counterparty_user_id' => $data['counterparty_user_id'] ?? null,
            'reference_transaction_id' => $data['reference_transaction_id'],
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'amount' => $amount,
            'fee' => $fee,
            'net_amount' => $netAmount,
            'direction' => $data['direction'],
            'status' => 'pending_pdf',
            'metadata' => $data['metadata'] ?? null,
            'zone_code' => $data['zone_code'] ?? 'SOUTH',
            'issued_at' => now(),
        ]);

        // Dispatch PDF generation async — لا يبطئ الاستجابة
        GeneratePdfReceiptJob::dispatch($receipt->id)
            ->onQueue('receipts');

        return $receipt;
    }

    /**
     * مفتاح عرض: AMY-2026-0516-AB12CD34
     */
    private function generateReceiptNumber(string $type): string
    {
        $prefix = 'AMY';
        $date = now()->format('Ymd');
        // 8 chars random لتجنب التصادم (entropy: 36^8 ≈ 2.8 trillion)
        $rand = strtoupper(Str::random(8));
        return "{$prefix}-{$date}-{$rand}";
    }

    /**
     * كود تحقق للـ QR code (16 chars).
     */
    private function generateVerificationCode(): string
    {
        // Base32-like (لتجنب 0/O، 1/I)
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 16; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    /**
     * يستخدم في endpoint عام للـ verification (QR scan).
     * يعيد ملخصاً مُجرَّداً (لا بيانات حساسة).
     */
    public function verifyByCode(string $verificationCode): ?array
    {
        // Cache لتجنب abuse — كل verification يُكاش لـ 5 دقائق
        return Cache::remember(
            "receipt_verify:{$verificationCode}",
            now()->addMinutes(5),
            function () use ($verificationCode) {
                $receipt = Receipt::where('verification_code', $verificationCode)
                    ->where('status', 'pdf_generated')
                    ->first();

                if (!$receipt) {
                    return null;
                }

                return [
                    'receipt_number' => $receipt->receipt_number,
                    'receipt_type' => $receipt->receipt_type,
                    'amount' => $receipt->amount,
                    'fee' => $receipt->fee,
                    'issued_at' => $receipt->issued_at?->toIso8601String(),
                    'is_valid' => $receipt->status === 'pdf_generated',
                    // لا نرجع: user_id, counterparty (privacy)
                ];
            }
        );
    }
}
