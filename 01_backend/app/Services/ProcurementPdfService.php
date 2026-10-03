<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Services\Merchant\MerchantLogoService;
use App\Support\ArabicPdf;

class ProcurementPdfService
{
    public function purchaseOrder(PurchaseOrder $order): string
    {
        $order->loadMissing(['supplier', 'items']);
        $merchant = Merchant::where('user_id', $order->merchant_user_id)->first();
        $qr = app(DocumentQrService::class);
        $code = (string) $order->document_ulid;

        $html = view('pdf.purchase-order', [
            'order' => $order,
            'supplier' => $order->supplier,
            'merchant' => $merchant,
            'merchantLogoData' => app(MerchantLogoService::class)->dataUri($merchant),
            'statusLabel' => $this->purchaseStatus((string) $order->status),
            'verificationUrl' => $qr->url($code),
            'qrDataUri' => $qr->dataUri($code),
        ])->render();

        return ArabicPdf::render($html, ['format' => 'A4', 'margin' => 12]);
    }

    public function supplierStatement(Supplier $supplier): string
    {
        $supplier->load([
            'ledger' => fn ($q) => $q->orderBy('id'),
            'purchaseOrders' => fn ($q) => $q->latest('id')->limit(100),
        ]);
        $merchant = Merchant::where('user_id', $supplier->merchant_user_id)->first();

        $html = view('pdf.supplier-statement', [
            'supplier' => $supplier,
            'ledgerRows' => $supplier->ledger,
            'orders' => $supplier->purchaseOrders,
            'merchant' => $merchant,
            'merchantLogoData' => app(MerchantLogoService::class)->dataUri($merchant),
            'generatedAt' => now('Asia/Riyadh'),
        ])->render();

        return ArabicPdf::render($html, ['format' => 'A4', 'margin' => 12]);
    }

    public function supplierPaymentReceipt(SupplierLedgerEntry $payment): string
    {
        $payment->loadMissing([]);
        $supplier = Supplier::whereKey($payment->supplier_id)->firstOrFail();
        $merchant = Merchant::where('user_id', $payment->merchant_user_id)->first();
        $qr = app(DocumentQrService::class);
        $code = (string) $payment->entry_ulid;

        $html = view('pdf.supplier-payment-receipt', [
            'payment' => $payment,
            'supplier' => $supplier,
            'merchant' => $merchant,
            'merchantLogoData' => app(MerchantLogoService::class)->dataUri($merchant),
            'paymentLabel' => $this->paymentMethod((string) $payment->payment_method),
            'documentTitle' => $payment->entry_type === 'supplier_refund'
                ? 'سند تحصيل من مورد' : 'سند سداد مورد',
            'amountLabel' => $payment->entry_type === 'supplier_refund'
                ? 'المبلغ المحصل' : 'المبلغ المسدد',
            'verificationUrl' => $qr->url($code),
            'qrDataUri' => $qr->dataUri($code),
        ])->render();

        return ArabicPdf::render($html, ['format' => 'A5', 'margin' => 10]);
    }

    public function purchaseOrderFilename(PurchaseOrder $order): string
    {
        return 'purchase_order_'.$this->safe($order->po_number).'.pdf';
    }

    public function supplierStatementFilename(Supplier $supplier): string
    {
        return 'supplier_statement_'.$supplier->id.'.pdf';
    }

    public function supplierPaymentFilename(SupplierLedgerEntry $payment): string
    {
        $prefix = $payment->entry_type === 'supplier_refund'
            ? 'supplier_collection' : 'supplier_payment';
        return $prefix.'_'.$this->safe((string) $payment->entry_ulid).'.pdf';
    }

    private function purchaseStatus(string $status): string
    {
        return match ($status) {
            'draft' => 'مسودة',
            'approved' => 'معتمد',
            'partially_received' => 'مستلم جزئياً',
            'completed' => 'مكتمل',
            'cancelled' => 'ملغى',
            default => $status,
        };
    }

    private function paymentMethod(string $method): string
    {
        return match ($method) {
            'amial_pay' => 'أميال باي',
            'cash_shift' => 'نقد من درج وردية POS',
            'cash_external' => 'نقد خارجي',
            'credit' => 'آجل',
            default => $method !== '' ? $method : 'غير محدد',
        };
    }

    private function safe(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]/', '-', $value), '-');
    }
}
