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
 * 3 تقارير رئيسية:
 *   1. Aging Report — تقادم المديونيات (0-30, 30-60, 60-90, 90+ يوم).
 *   2. Customer Statement — كشف حساب كامل لعميل (فواتير + تحصيلات).
 *   3. Sales Reps Performance — أداء مندوبي المبيعات.
 */
class WholesaleReportsService
{
    /**
     * تقرير تقادم المديونيات.
     * يُرجع كل عميل عليه balance > 0 موزّعاً على شرائح عمرية.
     */
    public function agingReport(WholesaleBusiness $business): array
    {
        $today = Carbon::today();
        $buckets = [
            'current' => 0,        // 0-30 يوم
            '30_60' => 0,          // 30-60 يوم
            '60_90' => 0,          // 60-90 يوم
            'over_90' => 0,        // 90+ يوم
        ];
        $byCustomer = [];

        $invoices = WholesaleInvoice::where('business_id', $business->id)
            ->whereIn('status', ['issued', 'partial_paid'])
            ->where('balance_due', '>', 0)
            ->with('customer:id,full_name,company_name,phone')
            ->get();

        foreach ($invoices as $inv) {
            $daysOverdue = $today->diffInDays($inv->due_date, false);
            $balance = (float)$inv->balance_due;

            $bucket = match(true) {
                $daysOverdue >= 0 => 'current',       // لم يحن بعد أو في يوم الاستحقاق
                $daysOverdue > -30 => 'current',
                $daysOverdue > -60 => '30_60',
                $daysOverdue > -90 => '60_90',
                default => 'over_90',
            };
            // ملاحظة: $daysOverdue سالب إن due_date في الماضي.
            // أعيد الحساب بشكل مباشر:
            if ($inv->due_date >= $today) {
                $bucket = 'current';
            } else {
                $overdue = abs($today->diffInDays($inv->due_date)); // Carbon 3: diffInDays موقّع — نأخذ المطلق
                $bucket = match(true) {
                    $overdue <= 30 => 'current',
                    $overdue <= 60 => '30_60',
                    $overdue <= 90 => '60_90',
                    default => 'over_90',
                };
            }

            $buckets[$bucket] += $balance;

            $cid = $inv->customer_id;
            if (!isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [
                    'customer_id' => $cid,
                    'customer_name' => $inv->customer?->full_name,
                    'company_name' => $inv->customer?->company_name,
                    'phone' => $inv->customer?->phone,
                    'current' => 0, '30_60' => 0, '60_90' => 0, 'over_90' => 0,
                    'total' => 0,
                    'invoices_count' => 0,
                ];
            }
            $byCustomer[$cid][$bucket] += $balance;
            $byCustomer[$cid]['total'] += $balance;
            $byCustomer[$cid]['invoices_count']++;
        }

        $totalReceivable = array_sum($buckets);
        uasort($byCustomer, fn($a, $b) => $b['total'] <=> $a['total']);

        return [
            'as_of_date' => $today->toDateString(),
            'total_receivable' => $totalReceivable,
            'buckets' => $buckets,
            'percentages' => $totalReceivable > 0 ? [
                'current' => round($buckets['current'] / $totalReceivable * 100, 1),
                '30_60' => round($buckets['30_60'] / $totalReceivable * 100, 1),
                '60_90' => round($buckets['60_90'] / $totalReceivable * 100, 1),
                'over_90' => round($buckets['over_90'] / $totalReceivable * 100, 1),
            ] : ['current' => 0, '30_60' => 0, '60_90' => 0, 'over_90' => 0],
            'by_customer' => array_values($byCustomer),
        ];
    }

    /**
     * كشف حساب عميل.
     * يُرجع كل الفواتير + التحصيلات بترتيب زمني، مع running_balance.
     */
    public function customerStatement(
        WholesaleCustomer $customer,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): array {
        $from = $from ?? Carbon::today()->subYear();
        $to = $to ?? Carbon::today();

        $invoices = WholesaleInvoice::where('customer_id', $customer->id)
            ->whereBetween('invoice_date', [$from, $to])
            ->where('status', '!=', 'voided')
            ->orderBy('invoice_date')
            ->get();

        $collections = WholesaleCollection::where('customer_id', $customer->id)
            ->whereBetween('collection_date', [$from, $to])
            ->orderBy('collection_date')
            ->get();

        // ادمج وادفع بـ running balance
        $events = [];
        foreach ($invoices as $inv) {
            $events[] = [
                'date' => $inv->invoice_date->toDateString(),
                'type' => 'invoice',
                'reference' => $inv->invoice_number,
                'description' => "فاتورة {$inv->invoice_number}",
                'debit' => (float)$inv->total_amount,
                'credit' => 0,
                'sort_key' => $inv->invoice_date->timestamp . '_1',
            ];
        }
        foreach ($collections as $col) {
            $events[] = [
                'date' => $col->collection_date->toDateString(),
                'type' => 'collection',
                'reference' => $col->collection_ulid,
                'description' => "تحصيل ({$col->payment_method})"
                    . ($col->reference_number ? " #{$col->reference_number}" : ''),
                'debit' => 0,
                'credit' => (float)$col->amount,
                'sort_key' => $col->collection_date->timestamp . '_2',
            ];
        }

        usort($events, fn($a, $b) => strcmp($a['sort_key'], $b['sort_key']));

        $balance = 0;
        $totalInvoiced = 0;
        $totalPaid = 0;
        foreach ($events as &$e) {
            $balance += $e['debit'] - $e['credit'];
            $e['running_balance'] = $balance;
            $totalInvoiced += $e['debit'];
            $totalPaid += $e['credit'];
            unset($e['sort_key']);
        }

        return [
            'customer' => [
                'id' => $customer->id,
                'full_name' => $customer->full_name,
                'company_name' => $customer->company_name,
                'phone' => $customer->phone,
                'credit_limit' => (float)$customer->credit_limit,
                'current_balance' => (float)$customer->current_balance,
            ],
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'summary' => [
                'total_invoiced' => $totalInvoiced,
                'total_paid' => $totalPaid,
                'closing_balance' => $balance,
            ],
            'events' => $events,
        ];
    }

    /**
     * أداء مندوبي المبيعات.
     */
    public function salesRepsPerformance(
        WholesaleBusiness $business,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): array {
        $from = $from ?? Carbon::today()->subMonth();
        $to = $to ?? Carbon::today();

        $reps = WholesaleSalesRep::where('business_id', $business->id)
            ->where('is_active', true)
            ->get();

        $result = [];
        foreach ($reps as $rep) {
            $periodInvoices = WholesaleInvoice::where('sales_rep_id', $rep->id)
                ->whereBetween('invoice_date', [$from, $to])
                ->where('status', '!=', 'voided');

            $totalSales = (float)(clone $periodInvoices)->sum('total_amount');
            $totalCommission = (float)(clone $periodInvoices)->sum('sales_rep_commission_amount');
            $invoicesCount = (clone $periodInvoices)->count();

            $result[] = [
                'rep_id' => $rep->id,
                'full_name' => $rep->full_name,
                'commission_rate' => (float)$rep->default_commission_rate,
                'period' => [
                    'invoices_count' => $invoicesCount,
                    'total_sales' => $totalSales,
                    'total_commission' => $totalCommission,
                ],
                'all_time' => [
                    'total_sales' => (float)$rep->total_sales,
                    'total_commission_earned' => (float)$rep->total_commission_earned,
                    'total_commission_paid' => (float)$rep->total_commission_paid,
                    'pending_commission' => $rep->pendingCommission(),
                ],
            ];
        }

        usort($result, fn($a, $b) => $b['period']['total_sales'] <=> $a['period']['total_sales']);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'reps' => $result,
        ];
    }
}
