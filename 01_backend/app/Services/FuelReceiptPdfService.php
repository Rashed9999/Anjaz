<?php

namespace App\Services;

use App\Models\FuelSale;
use App\Models\Merchant;
use App\Services\Merchant\MerchantLogoService;
use App\Support\ArabicPdf;

/**
 * AMIAL-FUEL-RECEIPT-001 — توليد PDF إيصال بيع وقود.
 */
class FuelReceiptPdfService
{
    public function generate(FuelSale $sale): string
    {
        $sale->loadMissing(['pump.station', 'product', 'companyAccount']);
        $merchant = Merchant::where('user_id', $sale->merchant_user_id)->first();

        $html = view('pdf.fuel-sale-receipt', [
            'sale' => $sale,
            'pump' => $sale->pump,
            'product' => $sale->product,
            'station' => $sale->pump->station,
            'company' => $sale->companyAccount,
            'merchantLogoData' => app(MerchantLogoService::class)->dataUri($merchant),
        ])->render();

        // DomPDF يعكس العربية ويفصل حروفها؛ محرك المشروع العربي هو مصدر
        // التصيير الوحيد لهذه الوثيقة أيضاً. القالب يضبط هامشه بنفسه.
        return ArabicPdf::render($html, ['format' => 'A5', 'margin' => 0]);
    }

    public function suggestedFilename(FuelSale $sale): string
    {
        $number = $sale->invoice_number ?: $sale->sale_ulid;
        $safeNumber = trim((string) preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $number), '-');
        $date = $sale->created_at->format('Y-m-d');
        return "fuel_receipt_{$safeNumber}_{$date}.pdf";
    }
}
