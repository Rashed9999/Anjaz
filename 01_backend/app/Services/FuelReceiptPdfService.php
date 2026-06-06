<?php

namespace App\Services;

use App\Models\FuelCompanyAccount;
use App\Models\FuelSale;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * AMIAL-FUEL-RECEIPT-001 — توليد PDF إيصال بيع وقود.
 */
class FuelReceiptPdfService
{
    public function generate(FuelSale $sale): string
    {
        $sale->loadMissing(['pump.station', 'product', 'companyAccount']);

        $pdf = Pdf::loadView('pdf.fuel-sale-receipt', [
            'sale' => $sale,
            'pump' => $sale->pump,
            'product' => $sale->product,
            'station' => $sale->pump->station,
            'company' => $sale->companyAccount,
        ]);
        $pdf->setOption(['defaultFont' => 'DejaVu Sans']);
        $pdf->setPaper('A5', 'portrait'); // إيصال A5 أصغر من A4

        return $pdf->output();
    }

    public function suggestedFilename(FuelSale $sale): string
    {
        $short = strtoupper(substr($sale->sale_ulid, -8));
        $date = $sale->created_at->format('Y-m-d');
        return "fuel_receipt_{$short}_{$date}.pdf";
    }
}
