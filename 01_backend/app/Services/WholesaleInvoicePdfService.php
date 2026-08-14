<?php

namespace App\Services;

use App\Models\WholesaleInvoice;
use App\Support\ArabicPdf;

/**
 * AMIAL-WHOLESALE-001 — توليد PDF فاتورة جملة.
 *
 * فاتورة A4 رسمية تشمل:
 *   - شعار وبيانات المنشأة (business)
 *   - بيانات العميل + ضريبية
 *   - جدول العناصر مع snapshot كامل (الأسعار وقت البيع)
 *   - الإجماليات (subtotal/discount/tax/total/paid/balance)
 *   - التحصيلات (إن وُجدت)
 *   - QR للتحقّق + رقم الفاتورة
 *   - حالة الدفع (مدفوعة/جزئية/مستحقّة)
 */
class WholesaleInvoicePdfService
{
    public function generate(WholesaleInvoice $invoice): string
    {
        // تحميل العلاقات اللازمة
        $invoice->loadMissing(['business', 'customer', 'salesRep', 'items', 'collections']);

        $html = view('pdf.wholesale-invoice', [
            'invoice' => $invoice,
            'business' => $invoice->business,
            'customer' => $invoice->customer,
            'salesRep' => $invoice->salesRep,
            'items' => $invoice->items,
            'collections' => $invoice->collections,
            // الحساب المسبق للأرقام للعرض
            'discount_pct' => $this->discountPercentage($invoice),
            'days_overdue' => $invoice->isOverdue() ? $invoice->daysOverdue() : 0,
            'status_label' => $this->statusLabel($invoice->status),
            'status_color' => $this->statusColor($invoice->status),
        ])->render();

        return ArabicPdf::render($html, ['format' => 'A4', 'margin' => 0]);
    }

    public function suggestedFilename(WholesaleInvoice $invoice): string
    {
        $safe = str_replace(['/', '\\'], '-', $invoice->invoice_number);
        return "wholesale_invoice_{$safe}.pdf";
    }

    private function discountPercentage(WholesaleInvoice $inv): float
    {
        $sub = (float)$inv->subtotal;
        if ($sub <= 0) return 0;
        return round((float)$inv->discount_amount / $sub * 100, 2);
    }

    private function statusLabel(string $status): string
    {
        return match($status) {
            'paid' => 'مدفوعة',
            'partial_paid' => 'دفع جزئي',
            'issued' => 'صادرة',
            'overdue' => 'متأخّرة',
            'voided' => 'مُبطَلة',
            'draft' => 'مسودّة',
            default => $status,
        };
    }

    private function statusColor(string $status): string
    {
        return match($status) {
            'paid' => '#059669',
            'partial_paid' => '#F59E0B',
            'issued' => '#053391',
            'overdue' => '#DC2626',
            'voided' => '#6B7280',
            default => '#6B7280',
        };
    }
}
