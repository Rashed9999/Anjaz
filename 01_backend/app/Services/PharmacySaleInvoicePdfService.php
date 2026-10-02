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
        return ArabicPdf::render(
            $this->renderHtml($sale),
            ['format' => 'A4', 'margin' => 12],
        );
    }

    /**
     * يفصل بناء HTML عن mPDF حتى يمكن اختبار القالب وحده، وتظهر أخطاء
     * Blade كأخطاء صريحة بدلاً من الاختباء خلف استجابة PDF_FAILED.
     */
    public function renderHtml(PharmacySale $sale): string
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

        $credit = $this->creditSnapshot($sale);
        $customerName = $sale->customer?->full_name
            ?: ($credit['customer_name'] ?? null)
            ?: ($sale->payment_method === 'credit' ? 'عميل آجل' : 'عميل نقدي');
        $customerPhone = $sale->customer?->phone
            ?: ($credit['customer_phone'] ?? null);

        return view('pdf.pharmacy-sale-invoice', [
            'sale' => $sale,
            'merchant' => $merchant,
            'merchantLogoData' => app(MerchantLogoService::class)->dataUri($merchant),
            'items' => $items,
            'paymentLabel' => $this->paymentLabel((string) $sale->payment_method),
            'creditStateCode' => (string) $credit['state'],
            'creditLabel' => (string) $credit['label'],
            'creditRemaining' => (string) $credit['remaining'],
            'creditDueDate' => $credit['due_date'] ?? null,
            'displayCustomerName' => $customerName,
            'displayCustomerPhone' => $customerPhone,
            'verificationUrl' => $qr->url($verificationCode),
            'qrDataUri' => $qr->dataUri($verificationCode),
        ])->render();
    }

    /** @return array{state:string,label:string,remaining:string,customer_name:?string,customer_phone:?string,due_date:?string} */
    public function creditSnapshot(PharmacySale $sale): array
    {
        if ($sale->payment_method !== 'credit') {
            return [
                'state' => 'paid',
                'label' => 'مكتملة',
                'remaining' => MoneyService::normalize('0'),
                'customer_name' => null,
                'customer_phone' => null,
                'due_date' => null,
            ];
        }

        $movement = CustomerCreditMovement::query()
            ->where('type', 'sale')
            ->where('reference_type', 'pharmacy_sale')
            ->where('reference_id', $sale->sale_ulid)
            ->orderBy('id')
            ->first();

        $account = $movement?->account;
        $identity = [
            'customer_name' => $account?->customer_name,
            'customer_phone' => $account?->customer_phone,
            'due_date' => $movement?->due_date?->format('Y-m-d'),
        ];

        if (!$movement || !$account) {
            return [
                'state' => 'unknown',
                'label' => 'آجلة — حالة السداد غير متاحة',
                'remaining' => MoneyService::normalize((string) $sale->total_amount),
                ...$identity,
            ];
        }

        $open = app(CreditSourceSettlementService::class)->openInvoices($account);
        $entry = collect($open)->first(
            fn (array $row) => ($row['movement_ulid'] ?? null) === $movement->movement_ulid
        );
        $remaining = $entry
            ? MoneyService::normalize((string) $entry['remaining'])
            : MoneyService::normalize('0');
        $total = MoneyService::normalize((string) $sale->total_amount);

        if (!MoneyService::isPositive($remaining)) {
            return ['state' => 'paid', 'label' => 'آجلة — مسددة', 'remaining' => $remaining, ...$identity];
        }

        if (MoneyService::compare($remaining, $total) < 0) {
            return ['state' => 'partial', 'label' => 'آجلة — مدفوعة جزئياً', 'remaining' => $remaining, ...$identity];
        }

        return ['state' => 'unpaid', 'label' => 'آجلة — غير مسددة', 'remaining' => $remaining, ...$identity];
    }

    public function cacheKey(PharmacySale $sale): string
    {
        $credit = $this->creditSnapshot($sale);
        $fingerprint = implode('|', [
            (string) $sale->status,
            (string) $sale->total_amount,
            $credit['state'],
            $credit['remaining'],
            (string) ($credit['customer_name'] ?? ''),
            (string) ($credit['customer_phone'] ?? ''),
            (string) ($credit['due_date'] ?? ''),
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
