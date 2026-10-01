<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\PdfCacheService;
use App\Services\ProcurementPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WebProcurementDocumentController extends Controller
{
    public function __construct(
        private readonly ProcurementPdfService $pdf,
        private readonly PdfCacheService $cache,
    ) {}

    public function purchaseOrder(Request $request, int $id): Response|JsonResponse
    {
        $owner = $request->user('merchant_web');
        $order = PurchaseOrder::whereKey($id)
            ->where('merchant_user_id', $owner->id)->first();

        if (! $order) return $this->notFound('أمر الشراء غير موجود');

        try {
            // أمر الشراء متغير حتى الإغلاق؛ updated_at جزء من مفتاح الكاش.
            $key = 'purchase_order_'.$order->document_ulid.'_'.($order->updated_at?->timestamp ?? 0);
            $bytes = $this->cache->remember($key, fn () => $this->pdf->purchaseOrder($order));

            return $this->download($bytes, $this->pdf->purchaseOrderFilename($order));
        } catch (\Throwable $e) {
            \Log::error('Purchase order PDF failed', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->pdfFailed();
        }
    }

    public function supplierStatement(Request $request, int $id): Response|JsonResponse
    {
        $owner = $request->user('merchant_web');
        $supplier = Supplier::whereKey($id)
            ->where('merchant_user_id', $owner->id)->first();

        if (! $supplier) return $this->notFound('المورد غير موجود');

        try {
            // الكشف حيّ؛ يدخل آخر قيد + الرصيد في المفتاح حتى لا نخدم كشفاً قديماً.
            $last = SupplierLedgerEntry::where('supplier_id', $supplier->id)->max('id') ?? 0;
            $key = 'supplier_statement_'.$supplier->id.'_'.$last.'_'
                .str_replace('.', '_', (string) $supplier->current_debt).'_'
                .str_replace('.', '_', (string) ($supplier->current_credit ?? '0'));
            $bytes = $this->cache->remember($key, fn () => $this->pdf->supplierStatement($supplier));

            return $this->download($bytes, $this->pdf->supplierStatementFilename($supplier));
        } catch (\Throwable $e) {
            \Log::error('Supplier statement PDF failed', ['id' => $id, 'error' => $e->getMessage()]);
            return $this->pdfFailed();
        }
    }

    public function supplierPayment(Request $request, string $entryUlid): Response|JsonResponse
    {
        $owner = $request->user('merchant_web');
        $payment = SupplierLedgerEntry::where('entry_ulid', strtoupper($entryUlid))
            ->where('merchant_user_id', $owner->id)
            ->where('entry_type', 'payment')
            ->first();

        if (! $payment) return $this->notFound('سند سداد المورد غير موجود');

        try {
            // السداد immutable؛ نفس المستند ونفس الرقم، وإعادة التحميل لا تنشئ دفعاً جديداً.
            $bytes = $this->cache->remember(
                'supplier_payment_'.$payment->entry_ulid,
                fn () => $this->pdf->supplierPaymentReceipt($payment)
            );

            return $this->download($bytes, $this->pdf->supplierPaymentFilename($payment));
        } catch (\Throwable $e) {
            \Log::error('Supplier payment PDF failed', [
                'entry_ulid' => $entryUlid, 'error' => $e->getMessage(),
            ]);
            return $this->pdfFailed();
        }
    }

    private function download(string $bytes, string $filename): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, max-age=900',
            'Content-Encoding' => 'identity',
        ]);
    }

    private function notFound(string $message): JsonResponse
    {
        return response()->json([
            'success' => false, 'code' => 'NOT_FOUND', 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], 404);
    }

    private function pdfFailed(): JsonResponse
    {
        return response()->json([
            'success' => false, 'code' => 'PDF_GEN_FAILED',
            'message' => 'تعذّر توليد المستند', 'errors' => (object) [], 'meta' => (object) [],
        ], 500);
    }
}
