<?php

namespace App\Services;

use App\Models\Merchant;
use App\Models\PharmacySale;
use App\Support\ArabicPdf;

/**
 * فاتورة الصيدلية الرسمية. مصدرها بيع الصيدلية وتشغيلاته، لذلك لا يجوز
 * استعمال مولّد فاتورة الكاشير العام الذي يقرأ `merchant_sales`.
 */
class PharmacySaleInvoicePdfService
{
    public function generate(PharmacySale $sale): string
    {
        $sale->loadMissing(['items.batch', 'customer']);
        $merchant = Merchant::where('user_id', $sale->merchant_user_id)->first();

        $items = $sale->items->map(fn ($item) => [
            'name' => (string) $item->product_trade_name,
            'batch_number' => (string) ($item->batch?->batch_number ?? ''),
            'expiry_date' => $item->batch?->expiry_date?->format('Y-m-d'),
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'total' => (string) $item->total_price,
            'requires_prescription' => (bool) $item->required_prescription,
        ])->values()->all();

        $html = view('pdf.pharmacy-sale-invoice', [
            'sale' => $sale,
            'merchant' => $merchant,
            'items' => $items,
            'paymentLabel' => $this->paymentLabel((string) $sale->payment_method),
        ])->render();

        return ArabicPdf::render($html, ['format' => 'A4', 'margin' => 12]);
    }

    public function suggestedFilename(PharmacySale $sale): string
    {
        return 'pharmacy_invoice_' . strtoupper(substr((string) $sale->sale_ulid, -10)) . '.pdf';
    }

    private function paymentLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'نقداً',
            'credit' => 'بيع آجل',
            'amial_pay' => 'أميال باي',
            default => '—',
        };
    }
}
