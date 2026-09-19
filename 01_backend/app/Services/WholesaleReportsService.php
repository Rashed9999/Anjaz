<?php

namespace App\Services;

use App\Models\WholesaleBusiness;
use App\Models\WholesaleCollection;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleInvoice;
use App\Models\WholesaleSalesRep;
use Illuminate\Support\Carbon;

/**
 * AMIAL-WHOLESALE-001 — تقارير الجملة.
 *
 * كل مبلغ يبقى DECIMAL نصياً حتى العرض. لا float في تقادم الذمم،
 * كشف الحساب، أو أداء المندوبين.
 */
class WholesaleReportsService
{
    public function agingReport(WholesaleBusiness $business): array
    {
        $today = Carbon::today();
        $buckets = [
            'current' => '0.0000',
            '30_60' => '0.0000',
            '60_90' => '0.0000',
            'over_90' => '0.0000',
        ];
        $byCustomer = [];

        $invoices = WholesaleInvoice::where('business_id', $business->id)
            ->whereIn('status', ['issued', 'partial_paid'])
            ->where('balance_due', '>', 0)
            ->with('customer:id,full_name,company_name,phone')
            ->get();

        foreach ($invoices as $inv) {
            $balance = MoneyService::normalize((string) $inv->balance_due);

            if ($inv->due_date >= $today) {
                $bucket = 'current';
            } else {
                $overdue = abs($today->diffInDays($inv->due_date));
                $bucket = match (true) {
                    $overdue <= 30 => 'current',
                    $overdue <= 60 => '30_60',
                    $overdue <= 90 => '60_90',
                    default => 'over_90',
                };
            }

            $buckets[$bucket] = MoneyService::add($buckets[$bucket], $balance);

            $customerId = (int) $inv->customer_id;
            if (! isset($byCustomer[$customerId])) {
                $byCustomer[$customerId] = [
                    'customer_id' => $customerId,
                    'customer_name' => $inv->customer?->full_name,
                    'company_name' => $inv->customer?->company_name,
                    'phone' => $inv->customer?->phone,
                    'current' => '0.0000',
                    '30_60' => '0.0000',
                    '60_90' => '0.0000',
                    'over_90' => '0.0000',
                    'total' => '0.0000',
                    'invoices_count' => 0,
                ];
            }
            $byCustomer[$customerId][$bucket] = MoneyService::add(
                $byCustomer[$customerId][$bucket], $balance
            );
            $byCustomer[$customerId]['total'] = MoneyService::add(
                $byCustomer[$customerId]['total'], $balance
            );
            $byCustomer[$customerId]['invoices_count']++;
        }

        $totalReceivable = '0.0000';
        foreach ($buckets as $amount) {
            $totalReceivable = MoneyService::add($totalReceivable, $amount);
        }

        uasort($byCustomer, fn ($a, $b) => bccomp($b['total'], $a['total'], 4));

        return [
            'as_of_date' => $today->toDateString(),
            'total_receivable' => $totalReceivable,
            'buckets' => $buckets,
            'percentages' => [
                'current' => $this->percent($buckets['current'], $totalReceivable),
                '30_60' => $this->percent($buckets['30_60'], $totalReceivable),
                '60_90' => $this->percent($buckets['60_90'], $totalReceivable),
                'over_90' => $this->percent($buckets['over_90'], $totalReceivable),
            ],
            'by_customer' => array_values($byCustomer),
        ];
    }

    public function customerStatement(
        WholesaleCustomer $customer,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): array {
        $from ??= Carbon::today()->subYear();
        $to ??= Carbon::today();

        $invoices = WholesaleInvoice::where('customer_id', $customer->id)
            ->whereBetween('invoice_date', [$from, $to])
            ->where('status', '!=', 'voided')
            ->orderBy('invoice_date')
            ->get();

        $collections = WholesaleCollection::where('customer_id', $customer->id)
            ->whereBetween('collection_date', [$from, $to])
            ->orderBy('collection_date')
            ->get();

        $events = [];
        foreach ($invoices as $invoice) {
            $events[] = [
                'date' => $invoice->invoice_date->toDateString(),
                'type' => 'invoice',
                'reference' => $invoice->invoice_number,
                'description' => "فاتورة {$invoice->invoice_number}",
                'debit' => MoneyService::normalize((string) $invoice->total_amount),
                'credit' => '0.0000',
                'sort_key' => $invoice->invoice_date->timestamp . '_1',
            ];
        }
        foreach ($collections as $collection) {
            $events[] = [
                'date' => $collection->collection_date->toDateString(),
                'type' => 'collection',
                'reference' => $collection->collection_ulid,
                'description' => "تحصيل ({$collection->payment_method})"
                    . ($collection->reference_number ? " #{$collection->reference_number}" : ''),
                'debit' => '0.0000',
                'credit' => MoneyService::normalize((string) $collection->amount),
                'sort_key' => $collection->collection_date->timestamp . '_2',
            ];
        }

        usort($events, fn ($a, $b) => strcmp($a['sort_key'], $b['sort_key']));

        $balance = '0.0000';
        $totalInvoiced = '0.0000';
        $totalPaid = '0.0000';
        foreach ($events as &$event) {
            $balance = MoneyService::add(
                $balance,
                MoneyService::sub($event['debit'], $event['credit'])
            );
            $event['running_balance'] = $balance;
            $totalInvoiced = MoneyService::add($totalInvoiced, $event['debit']);
            $totalPaid = MoneyService::add($totalPaid, $event['credit']);
            unset($event['sort_key']);
        }
        unset($event);

        return [
            'customer' => [
                'id' => $customer->id,
                'full_name' => $customer->full_name,
                'company_name' => $customer->company_name,
                'phone' => $customer->phone,
                'credit_limit' => MoneyService::normalize((string) $customer->credit_limit),
                'current_balance' => MoneyService::normalize((string) $customer->current_balance),
            ],
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'summary' => [
                'total_invoiced' => $totalInvoiced,
                'total_paid' => $totalPaid,
                'closing_balance' => $balance,
            ],
            'events' => $events,
        ];
    }

    public function salesRepsPerformance(
        WholesaleBusiness $business,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): array {
        $from ??= Carbon::today()->subMonth();
        $to ??= Carbon::today();

        $reps = WholesaleSalesRep::where('business_id', $business->id)
            ->where('is_active', true)
            ->get();

        $result = [];
        foreach ($reps as $rep) {
            $periodInvoices = WholesaleInvoice::where('sales_rep_id', $rep->id)
                ->whereBetween('invoice_date', [$from, $to])
                ->where('status', '!=', 'voided');

            $totalSales = MoneyService::normalize(
                (string) ((clone $periodInvoices)->sum('total_amount') ?: '0')
            );
            $totalCommission = MoneyService::normalize(
                (string) ((clone $periodInvoices)->sum('sales_rep_commission_amount') ?: '0')
            );
            $invoicesCount = (clone $periodInvoices)->count();
            $earned = MoneyService::normalize((string) $rep->total_commission_earned);
            $paid = MoneyService::normalize((string) $rep->total_commission_paid);

            $result[] = [
                'rep_id' => $rep->id,
                'full_name' => $rep->full_name,
                'commission_rate' => (string) $rep->default_commission_rate,
                'period' => [
                    'invoices_count' => $invoicesCount,
                    'total_sales' => $totalSales,
                    'total_commission' => $totalCommission,
                ],
                'all_time' => [
                    'total_sales' => MoneyService::normalize((string) $rep->total_sales),
                    'total_commission_earned' => $earned,
                    'total_commission_paid' => $paid,
                    'pending_commission' => MoneyService::sub($earned, $paid),
                ],
            ];
        }

        usort(
            $result,
            fn ($a, $b) => bccomp($b['period']['total_sales'], $a['period']['total_sales'], 4)
        );

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'reps' => $result,
        ];
    }

    private function percent(string $part, string $total): string
    {
        if (bccomp($total, '0', 4) === 0) {
            return '0.0';
        }

        return bcmul(bcdiv($part, $total, 6), '100', 1);
    }
}
