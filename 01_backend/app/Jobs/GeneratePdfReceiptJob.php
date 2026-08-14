<?php

namespace App\Jobs;

use App\Models\Receipt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * AMIAL-RECEIPTS-001
 *
 * GeneratePdfReceiptJob — توليد PDF للإيصال async.
 *
 * **التصميم:**
 *   - يُدفع للـ queue 'receipts' (يمكن فصلها عن queue المهم)
 *   - retry 3 مرات مع backoff تصاعدي (10s, 60s, 5min)
 *   - يفشل بصمت — العملية المالية لا تتأثر
 *   - PDF يُخزَّن في `storage/app/receipts/{YYYY}/{MM}/{DD}/{receipt_number}.pdf`
 *
 * **الـ PDF Library:** mPDF عبر ArabicPdf لتشكيل العربية وRTL.
 */
class GeneratePdfReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 300]; // 10s, 60s, 5min

    public function __construct(
        public readonly int $receiptId,
    ) {}

    public function handle(): void
    {
        $receipt = Receipt::find($this->receiptId);
        if (!$receipt) {
            Log::warning("GeneratePdfReceiptJob: receipt #{$this->receiptId} not found");
            return;
        }

        $documents = app(\App\Services\ReceiptDocumentService::class);
        if ($receipt->status === 'pdf_generated'
            && !empty($receipt->pdf_storage_path)
            && Storage::disk('local')->exists($receipt->pdf_storage_path)
            && $documents->isCurrent($receipt)) {
            // مُنتج بالفعل — idempotent
            return;
        }

        try {
            // AMIAL-DOCUMENTS-001: نفس عقد البيانات ونفس القالب الذي تستعمله
            // المعاينة والطباعة. نوع المعاملة يختار سند محفظة، ودفع التاجر
            // يختار فاتورة قطاعية من سجل البيع الموثوق.
            $document = $documents->build($receipt);

            $html = View::make($documents->a4View($document), [
                'document' => $document,
                'qrDataUri' => $this->qrDataUri((string) $document['verification_url']),
            ])->render();

            $bytes = \App\Support\ArabicPdf::render($html, ['format' => 'A4', 'margin' => 12]);

            $path = $this->buildStoragePath($receipt);
            Storage::disk('local')->put($path, $bytes);

            $receipt->update([
                'pdf_storage_path' => $path,
                'pdf_generated_at' => now(),
                'status' => 'pdf_generated',
                'metadata' => array_merge(
                    is_array($receipt->metadata) ? $receipt->metadata : [],
                    ['print_document_version' => \App\Services\ReceiptDocumentService::DOCUMENT_VERSION],
                ),
            ]);

            Log::info("Receipt PDF generated: {$receipt->receipt_number}");
        } catch (\Throwable $e) {
            Log::error("GeneratePdfReceiptJob failed for receipt #{$this->receiptId}", [
                'error' => $e->getMessage(),
                'trace' => substr($e->getTraceAsString(), 0, 500),
            ]);

            // علّم كـ failed لكن لا ترمي exception (يمنع retry غير المُسيطر عليه)
            // الـ retry يحدث تلقائياً عبر إعدادات $tries إن لم نعلِّم.
            // عمداً نسمح بـ retry عند failure مؤقت.
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $receipt = Receipt::find($this->receiptId);
        if ($receipt) {
            $receipt->update(['status' => 'pdf_failed']);
        }
        Log::critical("Receipt PDF generation permanently failed", [
            'receipt_id' => $this->receiptId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * مسار التخزين: receipts/2026/05/16/AMY-20260516-AB12CD34.pdf
     */
    private function qrDataUri(string $url): ?string
    {
        try {
            $svg = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')
                ->size(150)->margin(0)->generate($url);
            return 'data:image/svg+xml;base64,' . base64_encode($svg);
        } catch (\Throwable) {
            return null;
        }
    }

    private function buildStoragePath(Receipt $receipt): string
    {
        $date = $receipt->issued_at ?? $receipt->created_at;
        return sprintf(
            'receipts/%s/%s/%s/%s.pdf',
            $date->format('Y'),
            $date->format('m'),
            $date->format('d'),
            $receipt->receipt_number,
        );
    }

}
