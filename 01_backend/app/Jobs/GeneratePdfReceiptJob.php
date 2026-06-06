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
 * **الـ PDF Library:**
 *   يستخدم `barryvdh/laravel-dompdf` (الـ default للـ Laravel).
 *   إذا غير مُثبَّت، الـ Job يفشل بشكل آمن ويسجل warning.
 *
 *   composer require barryvdh/laravel-dompdf
 *
 *   ثم Service Provider يُسجَّل تلقائياً.
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

        if ($receipt->status === 'pdf_generated' && !empty($receipt->pdf_storage_path)) {
            // مُنتج بالفعل — idempotent
            return;
        }

        try {
            // فحص أن المكتبة موجودة
            if (!class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                Log::error('GeneratePdfReceiptJob: barryvdh/laravel-dompdf not installed');
                $receipt->update(['status' => 'pdf_failed']);
                return;
            }

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('receipts.pdf', [
                'receipt' => $receipt,
                'user' => $receipt->user,
                'counterparty' => $receipt->counterparty,
                'qrUrl' => $this->buildVerificationUrl($receipt->verification_code),
            ])->setPaper('a5', 'portrait');

            $path = $this->buildStoragePath($receipt);
            Storage::disk('local')->put($path, $pdf->output());

            $receipt->update([
                'pdf_storage_path' => $path,
                'pdf_generated_at' => now(),
                'status' => 'pdf_generated',
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

    private function buildVerificationUrl(string $code): string
    {
        $base = config('app.url', 'https://amialpay.com');
        return rtrim($base, '/') . '/v/' . $code;
    }
}
