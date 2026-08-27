<?php

namespace App\Services;

use App\Models\FuelSale;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\Ledger\LedgerEntryLine;
use App\Models\PharmacySale;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleCollection;
use App\Models\WholesaleInvoice;
use Carbon\Carbon;

/**
 * عقد التقرير المالي التشغيلي الموحد للتاجر.
 *
 * لا يخلط هذا التقرير ما دخل المحفظة بما بيع نقداً أو آجلاً. كل رقم يحمل
 * مصدره ونوعه، لذلك لا تتحول «دفعة أميال» إلى إجمالي مبيعات ولا يختفي
 * الآجل لأنه لم يدخل المحفظة بعد.
 */
class MerchantFinancialTruthReportService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function report(User $merchant, ?string $from = null, ?string $to = null): array
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : now()->startOfDay();
        $end = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();
        if ($end->lt($start)) throw new \InvalidArgumentException('نهاية الفترة يجب أن تكون بعد بدايتها');
        $vertical = MerchantProfile::where('user_id', $merchant->id)->value('business_type') ?: 'retail';

        [$sales, $source] = $this->salesFor($merchant, $vertical, $start, $end);
        $methods = ['cash' => '0', 'amial_pay' => '0', 'credit' => '0', 'other' => '0'];
        $gross = '0'; $count = 0;
        foreach ($sales as $sale) {
            $amount = MoneyService::normalize((string) ($sale->amount ?? '0'));
            $method = $this->normalizeMethod((string) ($sale->method ?? ''));
            $methods[$method] = MoneyService::add($methods[$method], $amount);
            $gross = MoneyService::add($gross, $amount);
            $count++;
        }

        $collections = $vertical === 'wholesale'
            ? $this->wholesaleCollections($merchant, $start, $end)
            : ['cash' => '0', 'amial_pay' => '0', 'other' => '0', 'count' => 0];

        $wallet = $this->walletTruth($merchant, $start, $end);
        return [
            'contract_version' => 'merchant-financial-truth/v1',
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'vertical' => $vertical,
            'currency' => 'YER',
            'sales' => [
                'gross' => $gross, 'count' => $count,
                'by_payment_method' => $methods,
                'source' => $source,
            ],
            // التحصيلات تُعرض مستقلة: قد تخص دين فاتورة قديمة، فلا تضاف إلى بيع اليوم.
            'collections' => $collections,
            'wallet' => $wallet,
            'receivables' => $this->receivables($merchant, $vertical),
        ];
    }

    private function salesFor(User $merchant, string $vertical, Carbon $start, Carbon $end): array
    {
        if ($vertical === 'wholesale') {
            $businessId = WholesaleBusiness::where('merchant_user_id', $merchant->id)->value('id');
            if (!$businessId) return [collect(), 'wholesale_invoices'];
            return [WholesaleInvoice::where('business_id', $businessId)->where('status', '!=', 'voided')
                ->whereBetween('invoice_date', [$start->toDateString(), $end->toDateString()])
                ->get(['total_amount as amount', 'payment_type as method']), 'wholesale_invoices'];
        }
        if ($vertical === 'fuel') {
            return [FuelSale::where('merchant_user_id', $merchant->id)->where('status', 'completed')
                ->whereBetween('created_at', [$start, $end])->get(['total_amount as amount', 'payment_method as method']), 'fuel_sales'];
        }
        if ($vertical === 'pharmacy') {
            return [PharmacySale::where('merchant_user_id', $merchant->id)->where('status', 'completed')
                ->whereBetween('created_at', [$start, $end])->get(['total_amount as amount', 'payment_method as method']), 'pharmacy_sales'];
        }
        return [MerchantSale::where('merchant_user_id', $merchant->id)
            ->whereIn('status', ['completed', 'credit_unpaid', 'credit_paid'])
            ->whereBetween('created_at', [$start, $end])
            ->get(['total_amount as amount', 'payment_method as method']), 'merchant_sales'];
    }

    private function wholesaleCollections(User $merchant, Carbon $start, Carbon $end): array
    {
        $businessId = WholesaleBusiness::where('merchant_user_id', $merchant->id)->value('id');
        $result = ['cash' => '0', 'amial_pay' => '0', 'other' => '0', 'count' => 0];
        if (!$businessId) return $result;
        $rows = WholesaleCollection::where('business_id', $businessId)
            ->whereBetween('collection_date', [$start->toDateString(), $end->toDateString()])
            ->get(['amount', 'payment_method']);
        foreach ($rows as $row) {
            $method = $this->normalizeMethod((string) $row->payment_method);
            if (!array_key_exists($method, $result)) $method = 'other';
            $result[$method] = MoneyService::add($result[$method], (string) $row->amount);
            $result['count']++;
        }
        return $result;
    }

    private function receivables(User $merchant, string $vertical): array
    {
        if ($vertical !== 'wholesale') return ['known' => false, 'amount' => null, 'source' => null];
        $businessId = WholesaleBusiness::where('merchant_user_id', $merchant->id)->value('id');
        return [
            'known' => true,
            'amount' => $businessId ? (string) WholesaleInvoice::where('business_id', $businessId)
                ->whereNotIn('status', ['voided', 'paid'])->sum('balance_due') : '0',
            'source' => 'wholesale_invoices.balance_due',
        ];
    }

    private function walletTruth(User $merchant, Carbon $start, Carbon $end): array
    {
        $wallet = $this->ledger->getOrCreateUserWallet($merchant->id);
        $credits = (string) LedgerEntryLine::where('account_id', $wallet->id)->where('direction', 'credit')
            ->whereBetween('created_at', [$start, $end])->sum('amount');
        $debits = (string) LedgerEntryLine::where('account_id', $wallet->id)->where('direction', 'debit')
            ->whereBetween('created_at', [$start, $end])->sum('amount');
        return [
            'received' => $credits ?: '0', 'paid_out' => $debits ?: '0',
            'net_movement' => MoneyService::sub($credits ?: '0', $debits ?: '0'),
            'balance' => (string) $wallet->current_balance,
            'source' => 'ledger_entry_lines',
        ];
    }

    private function normalizeMethod(string $method): string
    {
        return match ($method) {
            'cash' => 'cash', 'amial_pay' => 'amial_pay', 'credit', 'company_card', 'corporate' => 'credit',
            default => 'other',
        };
    }
}
