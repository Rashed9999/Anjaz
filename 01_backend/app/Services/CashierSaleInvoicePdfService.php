<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Services\Merchant\MerchantLogoService;
use App\Support\ArabicPdf;

/**
 * AMIAL-CASHIER-INVOICE-001 — فاتورة PDF لبيع الكاشير النقدي/الآجل.
 *
 * لا تعتمد هذه الوثيقة على لقطة شاشة الهاتف ولا على `items` التي وصلت من
 * العميل؛ تقرأ أسطر البيع المخزنة، وتبقى صالحة لإعادة التحميل لاحقاً.
 */
class CashierSaleInvoicePdfService
{
    public function generate(MerchantSale $sale): string
    {
        $sale->loadMissing('lines');
        $merchant = Merchant::where('user_id', $sale->merchant_user_id)->first();
        $profile = MerchantProfile::where('user_id', $sale->merchant_user_id)->first();
        $qr = app(DocumentQrService::class);
        $verificationCode = (string) $sale->sale_ulid;

        $items = $sale->lines->isNotEmpty()
            ? $sale->lines->map(fn ($line) => [
                'name' => (string) $line->name,
                'barcode' => (string) ($line->barcode ?? ''),
                'quantity' => (string) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'discount' => (string) ($line->line_discount ?? '0'),
                'total' => (string) $line->line_total,
            ])->values()->all()
            : collect((array) $sale->items)->map(fn ($line) => [
                'name' => (string) ($line['name'] ?? 'صنف'),
                'barcode' => (string) ($line['barcode'] ?? ''),
                'quantity' => (string) ($line['qty'] ?? 1),
                'unit_price' => (string) ($line['price'] ?? 0),
                'discount' => '0',
                'total' => (string) (($line['qty'] ?? 1) * ($line['price'] ?? 0)),
            ])->values()->all();

        $vertical = (string) ($profile?->business_type ?? 'retail');
        $title = match ($vertical) {
            'quick_sale' => 'فاتورة بيع سريع',
            'restaurant' => 'فاتورة مطعم',
            default => 'فاتورة بيع بالتجزئة',
        };
        $discount = (string) ($sale->discount_amount ?? '0');
        $total = (string) $sale->total_amount;
        $subtotal = MoneyService::add($total, $discount);

        $html = view('pdf.cashier-sale-invoice', [
            'sale' => $sale,
            'merchant' => $merchant,
            'merchantLogoData' => app(MerchantLogoService::class)->dataUri($merchant),
            'title' => $title,
            'vertical' => $vertical,
            'items' => $items,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $total,
            'paymentLabel' => $this->paymentLabel((string) $sale->payment_method),
            'statusLabel' => $this->statusLabel((string) $sale->status),
            'verificationUrl' => $qr->url($verificationCode),
            'qrDataUri' => $qr->dataUri($verificationCode),
        ])->render();

        return ArabicPdf::render($html, ['format' => 'A4', 'margin' => 12]);
    }

    /**
     * مفتاح النسخة يتبدّل عندما تتبدّل حالة البيع.
     *
     * بيع الآجل يمكن أن ينتقل من credit_unpaid إلى credit_paid بعد أن يكون
     * العميل قد نزّل PDF مرةً؛ المفتاح الثابت كان سيخدم النسخة القديمة
     * ويعرض «غير مسددة» بعد وصول المال. الحالة + updated_at تمنع ذلك.
     */
    public function cacheKey(MerchantSale $sale): string
    {
        $version = $sale->updated_at?->format('YmdHis') ?? '0';

        return "cashier_invoice_{$sale->sale_ulid}_{$sale->status}_{$version}";
    }

    public function suggestedFilename(MerchantSale $sale): string
    {
        $number = $sale->invoice_number ?: $sale->sale_ulid;

        return 'cashier_invoice_' . $this->safeFilenamePart((string) $number) . '.pdf';
    }

    private function safeFilenamePart(string $value): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]/', '-', $value), '-');
    }

    private function paymentLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'نقداً',
            'credit' => 'بيع آجل',
            'amial_pay' => 'أميال باي',
            'corporate' => 'حساب شركة',
            'mixed' => 'دفع مختلط',
            default => '—',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'completed', 'credit_paid' => 'مكتملة',
            'credit_unpaid' => 'آجلة — غير مسددة',
            'pending_payment' => 'بانتظار الدفع',
            default => $status,
        };
    }
}
