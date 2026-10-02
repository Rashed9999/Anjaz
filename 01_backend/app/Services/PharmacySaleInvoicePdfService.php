<?php

namespace App\Services;

use App\Models\CustomerCreditMovement;
use App\Models\Merchant;
use App\Models\PharmacySale;
use App\Services\Merchant\MerchantLogoService;
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
        $qr = app(DocumentQrService::class);
        $verificationCode = (string) $sale->sale_ulid;

        $items = $sale->items->map(fn ($item) => [
            'name' => (string) $item->product_trade_name,
            'batch_number' => (string) ($item->batch?->batch_number ?? ''),
            'expiry_date' => $item->batch?->expiry_date?->format('Y-m-d'),
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'total' => (string) $item->total_price,
            'requires_prescription' => (bool) $item->required_prescription,
        ])->values()->all();

        $creditState = $this->creditSnapshot($sale);

        $html = view('pdf.pharmacy-sale-invoice', [
            'sale' => $sale,
            'merchant' => $merchant,
            'merchantLogoData' => app(MerchantLogoService::class)->dataUri($merchant),
            'items' => $items,
            'paymentLabel' => $this->paymentLabel((string) $sale->payment_method),
            'creditState' => $creditState,
            'verificationUrl' => $qr->url($verificationCode),
            'qrDataUri' => $qr->dataUri($verificationCode),
        ])->render();

        return ArabicPdf::render($html, ['format' => 'A4', 'margin' => 12]);
    }

    /** @return array{state:string,label:string,remaining:string} */
    public function creditSnapshot(PharmacySale $sale): array
    {
        if ($sale->payment_method !== 'credit') {
            return [
                'state' => 'paid',
                'label' => 'مكتملة',
                'remaining' => MoneyService::normalize('0'),
            ];
        }

        $movement = CustomerCreditMovement::query()
            ->where('type', 'sale')
            ->where('reference_type', 'pharmacy_sale')
            ->where('reference_id', $sale->sale_ulid)
            ->orderBy('id')
            ->first();

        if (!$movement || !$movement->account) {
            return [
                'state' => 'unknown',
                'label' => 'آجلة — حالة السداد غير متاحة',
                'remaining' => MoneyService::normalize((string) $sale->total_amount),
            ];
        }

        $open = app(CreditSourceSettlementService::class)->openInvoices($movement->account);
        $entry = collect($open)->first(
            fn (array $row) => ($row['movement_ulid'] ?? null) === $movement->movement_ulid
        );
        $remaining = $entry
            ? MoneyService::normalize((string) $entry['remaining'])
            : MoneyService::normalize('0');
        $total = MoneyService::normalize((string) $sale->total_amount);

        if (!MoneyService::isPositive($remaining)) {
            return ['state' => 'paid', 'label' => 'آجلة — مسددة', 'remaining' => $remaining];
        }

        if (MoneyService::compare($remaining, $total) < 0) {
            return ['state' => 'partial', 'label' => 'آجلة — مدفوعة جزئياً', 'remaining' => $remaining];
        }

        return ['state' => 'unpaid', 'label' => 'آجلة — غير مسددة', 'remaining' => $remaining];
    }

    public function cacheKey(PharmacySale $sale): string
    {
        $credit = $this->creditSnapshot($sale);
        $fingerprint = implode('|', [
            (string) $sale->status,
            (string) $sale->total_amount,
            $credit['state'],
            $credit['remaining'],
            (string) ($sale->updated_at?->format('YmdHis.u') ?? '0'),
        ]);

        return "pharmacy_invoice_{$sale->sale_ulid}_" . sha1($fingerprint);
    }

    public function suggestedFilename(PharmacySale $sale): string
    {
        $number = $sale->invoice_number ?: $sale->sale_ulid;

        return 'pharmacy_invoice_' . $this->safeFilenamePart((string) $number) . '.pdf';
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
            default => '—',
        };
    }
}
