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
    /**
     * AMIAL-RECEIPT-NUMBERS-001 — رقم إشعار رقميّ بحت.
     *
     * كان `AMY-20260725-HUW8YUD1`: واحد وعشرون خانة فيها حروف وشَرطات.
     * والرقم غرضه الأول أن يقرأه العميل على الهاتف لموظّف الدعم — و«HUW8YUD1»
     * لا تُملى ولا تُكتب بلا خطأ، خصوصاً لمن لا يعرف الإنجليزية. الحروف
     * المتشابهة (O/0، I/1) تضاعف الخطأ.
     *
     * الشكل الجديد: YYMMDD + ست خانات عشوائية = 12 رقماً، تُعرض مجموعةً
     * `260726 481037`. التاريخ في صدره يجعله مرتّباً زمنياً ومفيداً للدعم،
     * والذيل العشوائي يمنع التخمين والتصادم (مليون احتمال لليوم الواحد،
     * مع فحص تفرّد وإعادة محاولة).
     */
    private function generateReceiptNumber(string $type): string
    {
        $date = now()->format('ymd');

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $number = $date . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            if (!Receipt::where('receipt_number', $number)->exists()) {
                return $number;
            }
        }

        // احتياط لا يُتوقّع بلوغه: نوسّع الفضاء بدل رمي استثناء يوقف عملية مالية.
        return $date . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
            . str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);
    }

    /**
     * AMIAL-RECEIPT-NUMBERS-001 — كود تحقّق رقميّ.
     *
     * كان 16 حرفاً Base32. صار 16 رقماً تُعرض `1234 5678 9012 3456`.
     *
     * ملاحظة على الأمان: الفضاء انخفض من 31^16 (~79 بت) إلى 10^16 (~53 بت).
     * ما زال بعيداً عن التخمين اليدوي، لكنه لم يعد كافياً وحده أمام آلة
     * تجرّب بلا حدّ — ولذلك أُضيف حدّ معدّل على /v/{code} في نفس التغيير.
     * الاثنان معاً لا أحدهما.
     */
    private function generateVerificationCode(): string
    {
        $code = '';
        for ($i = 0; $i < 16; $i++) {
            $code .= (string) random_int(0, 9);
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
