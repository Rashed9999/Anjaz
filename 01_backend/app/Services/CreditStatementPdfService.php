<?php

namespace App\Services;

use App\Models\CustomerCreditAccount;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * AMIAL-CREDIT-PDF-001 — تصدير PDF لكشف حساب عميل.
 *
 * يُستخدم بعد جلب الكشف عبر CustomerCreditService::getStatement.
 */
class CreditStatementPdfService
{
    public function __construct(
        private readonly CustomerCreditService $credit,
    ) {}

    /**
     * يولّد PDF ويرجع content (binary).
     * يُمرَّر للـ Response::stream أو ->download().
     */
    public function generate(CustomerCreditAccount $account, ?string $from = null, ?string $to = null): string
    {
        $statement = $this->credit->getStatement($account, $from, $to);

        $pdf = Pdf::loadView('pdf.credit-statement', [
            'account' => $statement['account'],
            'opening_balance' => $statement['opening_balance'],
            'closing_balance' => $statement['closing_balance'],
            'totals' => $statement['totals'],
            'movements' => $statement['movements'],
            'period' => $statement['period'],
        ]);

        // RTL + خط يدعم العربية
        $pdf->setOption(['defaultFont' => 'DejaVu Sans']);
        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }

    /** اسم الملف المقترح للتنزيل. */
    public function suggestedFilename(CustomerCreditAccount $account): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $account->customer_name);
        $date = now()->format('Y-m-d');
        return "كشف_حساب_{$safe}_{$date}.pdf";
    }
}
