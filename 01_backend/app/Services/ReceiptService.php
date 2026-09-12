<?php

namespace App\Services;

use App\Jobs\GeneratePdfReceiptJob;
use App\Models\Receipt;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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
        $fee = (string) ($base['fee'] ?? '0');
        $feeBearer = (string) ($base['fee_bearer'] ?? 'sender');

        $senderReceipt = $this->issueDebit(array_merge($base, [
            'user_id' => $base['from_user_id'],
            'counterparty_user_id' => $base['to_user_id'],
            'amount' => $base['amount'],
            'fee' => $feeBearer === 'sender' ? $fee : '0',
        ]));

        $receiverReceipt = $this->issueCredit(array_merge($base, [
            'user_id' => $base['to_user_id'],
            'counterparty_user_id' => $base['from_user_id'],
            'amount' => $base['amount'],
            'fee' => $feeBearer === 'receiver' ? $fee : '0',
            // بعض إيصالات credit القديمة تمرّر مبلغاً صافياً ورسمَ قناةٍ
            // منفصلاً ولا يعني ذلك خصمه مرة ثانية. لذلك نصرّح بالصافي
            // هنا فقط عندما يكون رسم التحويل نفسه على المستلم.
            'net_amount' => $feeBearer === 'receiver'
                ? MoneyService::sub((string) $base['amount'], $fee)
                : (string) $base['amount'],
        ]));

        return [$senderReceipt, $receiverReceipt];
    }

    /**
     * يربط إيصالَي الدفع بسجل البيع القطاعي بعد إنشاء السجل الموثوق.
     *
     * دفع المحفظة قد يكتمل قبل أن ينشئ الكاشير FuelSale/MerchantSale. عند
     * اكتمال سجل البيع نربطه برقم المعاملة ونُبطل ملف PDF القديم فقط؛ لا
     * نغيّر مبلغاً أو رصيداً أو قيداً مالياً. إعادة التوليد idempotent.
     */
    public function attachBusinessReference(
        string $transactionId,
        string $referenceType,
        int $referenceId,
        array $metadata = [],
    ): int {
        if ($transactionId === '' || $referenceId <= 0) {
            return 0;
        }

        $receipts = Receipt::where('reference_transaction_id', $transactionId)->get();
        foreach ($receipts as $receipt) {
            $oldPath = $receipt->pdf_storage_path;
            $mergedMetadata = array_merge(
                is_array($receipt->metadata) ? $receipt->metadata : [],
                $metadata,
            );

            $receipt->update([
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'metadata' => $mergedMetadata ?: null,
                'status' => 'pending_pdf',
                'pdf_storage_path' => null,
                'pdf_generated_at' => null,
            ]);

            // لا نحذف النسخة السابقة داخل معاملة البيع: إذا تراجعت المعاملة
            // يجب أن يبقى المستند القديم قابلاً للتنزيل. الإبطال والتوليد
            // يحدثان بعد نجاح commit فقط ولا يمسان القيود المالية.
            $regenerate = function () use ($receipt, $oldPath): void {
                try {
                    if ($oldPath && Storage::disk('local')->exists($oldPath)) {
                        Storage::disk('local')->delete($oldPath);
                    }
                    GeneratePdfReceiptJob::dispatch($receipt->id)->onQueue('receipts');
                } catch (\Throwable $e) {
                    // الطباعة طبقة لاحقة للمال: لا نعيد خطأً بعد commit فيظن
                    // الكاشير أن البيع فشل ويكرره. تبقى النسخة قابلة لإعادة
                    // الصف من حالة واضحة، ويُحفظ السبب في السجل.
                    Receipt::whereKey($receipt->id)->update(['status' => 'pdf_failed']);
                    \Illuminate\Support\Facades\Log::error('Receipt regeneration could not be queued', [
                        'receipt_id' => $receipt->id,
                        'exception' => get_class($e),
                        'message' => $e->getMessage(),
                    ]);
                }
            };

            if (DB::transactionLevel() > 0) {
                DB::afterCommit($regenerate);
            } else {
                $regenerate();
            }
        }

        if ($receipts->isNotEmpty()) {
            $this->audit->record([
                'actor_type' => 'system',
                'subject_type' => 'receipt',
                'subject_id' => $transactionId,
                'action' => 'RECEIPT_BUSINESS_REFERENCE_ATTACHED',
                'decision_code' => 'DOCUMENT_SOURCE_LINKED',
                'transaction_id' => $transactionId,
                'severity' => 'info',
                'context' => [
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'receipt_count' => $receipts->count(),
                ],
            ]);
        }

        return $receipts->count();
    }

    /**
     * Core method.
     */
    private function issue(array $data): Receipt
    {
        $amount = (string)$data['amount'];
        $fee = (string)($data['fee'] ?? '0');
        $netAmount = array_key_exists('net_amount', $data)
            ? MoneyService::normalize((string) $data['net_amount'])
            : ($data['direction'] === 'debit'
                ? MoneyService::add($amount, $fee) // المرسل يدفع amount+fee
                : $amount);                       // الائتمان القديم هو المبلغ الصافي

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

        // ══════════════════════════════════════════════════════════════
        // AMIAL-RECEIPT-NUMBER-RACE-001 — **«افحص ثمّ أدرج» ليس ذرّيّاً.**
        //
        // كان هذا الاحتياطُ يُرجع رقماً **بلا فحصِ تفرّد** («احتياطٌ لا
        // يُتوقّع بلوغه»). وهو صحيحٌ احتماليّاً وخطأٌ بنيويّاً: على
        // `receipts.receipt_number` **قيدٌ فريدٌ في القاعدة**، فأيُّ تصادمٍ
        // — مهما ندر — يخرج `UniqueConstraintViolationException` **يوقف
        // إصدارَ إيصالٍ لعمليّةٍ ماليّةٍ وقعت فعلاً**.
        //
        // وهو النمطُ نفسُه الذي كلّف هذا المشروعَ من قبل في
        // `firstOrCreate` (‏`1062 Duplicate entry 'PLATFORM_FEE'`)،
        // ومكتوبٌ في `CLAUDE.md` بثمنه: **الفحصُ ثمّ الإدراجُ خطوتان،
        // وبينهما يمرّ غيرُك.**
        //
        // **وما دفعني إليه ملاحظةٌ لا برهان**: سقط
        // `receipt_numbers_do_not_collide` في جولتَي بوّابة، ومرّ ٨/٨
        // منفرداً ومرّتين في المجموعة كاملةً وبعد طبقة الضغط. **فلم
        // أُثبت أنّ هذا سببُه ولا أدّعيه** — لكنّ الشقَّ مفتوحٌ بذاته،
        // وإغلاقُه صوابٌ سواءٌ كان هو أم لا.
        //
        // فالاحتياطُ صار **يوسّع الفضاءَ ويفحص** — وبفضاءِ مئةِ مليون
        // لليوم الواحد يصير التصادمُ بعد ثمانيةِ تصادماتٍ متتاليةٍ
        // مستحيلاً عمليّاً، ولا يُرجَع رقمٌ لم يُسأل عنه.
        // ══════════════════════════════════════════════════════════════
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $wide = $date
                . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
                . str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);

            if (!Receipt::where('receipt_number', $wide)->exists()) {
                return $wide;
            }
        }

        // **ولا يُرمى استثناءٌ يوقف مالاً**: الطابعُ الزمنيُّ بالميكروثانية
        // يجعل التكرارَ يحتاج إيصالين في الميكروثانية نفسِها **وبنفس
        // القرعة** — وهو أبعدُ من كلّ ما سبق.
        return $date . substr((string) now()->format('Hisu'), -8);
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
                    'receipt_type_label' => $this->publicTypeLabel($receipt->receipt_type),
                    'amount' => $receipt->amount,
                    'fee' => $receipt->fee,
                    'issued_at' => $receipt->issued_at?->toIso8601String(),
                    'is_valid' => $receipt->status === 'pdf_generated',
                    // لا نرجع: user_id, counterparty (privacy)
                ];
            }
        );
    }

    private function publicTypeLabel(string $type): string
    {
        return match ($type) {
            'send_money' => 'تحويل أموال',
            'cash_in' => 'إيداع نقدي',
            'cash_out', 'withdraw' => 'سحب نقدي',
            'add_money' => 'إضافة رصيد',
            'pay_merchant' => 'دفع لتاجر',
            'pos_payment' => 'دفع نقطة بيع',
            'qr_payment' => 'دفع عبر رمز QR',
            'refund' => 'استرجاع',
            default => 'عملية مالية',
        };
    }
}
