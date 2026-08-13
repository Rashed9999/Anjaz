<?php

namespace App\Services;

use App\Models\FuelSale;
use App\Support\ArabicPdf;

/**
 * AMIAL-FUEL-RECEIPT-001 — توليد PDF إيصال بيع وقود.
 */
class FuelReceiptPdfService
{
    public function generate(FuelSale $sale): string
    {
        $sale->loadMissing(['pump.station', 'product', 'companyAccount']);

        $html = view('pdf.fuel-sale-receipt', [
            'sale' => $sale,
            'pump' => $sale->pump,
            'product' => $sale->product,
            'station' => $sale->pump->station,
            'company' => $sale->companyAccount,
        ])->render();

        // DomPDF يعكس العربية ويفصل حروفها؛ محرك المشروع العربي هو مصدر
        // التصيير الوحيد لهذه الوثيقة أيضاً. القالب يضبط هامشه بنفسه.
        return ArabicPdf::render($html, ['format' => 'A5', 'margin' => 0]);
    }

    public function suggestedFilename(FuelSale $sale): string
    {
        $short = strtoupper(substr($sale->sale_ulid, -8));
        $date = $sale->created_at->format('Y-m-d');
        return "fuel_receipt_{$short}_{$date}.pdf";
    }
}
