<?php

namespace App\Services\Reporting;

use App\Services\CreditSourceSettlementService;
use App\Support\Money\Currencies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-REPORTING-CENTER-004 — رقابة المخزون والذمم.
 *
 * لا ندّعي «تقييم مخزون» قبل وجود سياسة تكلفة/عملة صريحة للصنف. أمّا
 * الذمم فتعيد تشغيل دفتر الديون نفسه الذي تستخدمه «فواتيري الآجلة»؛
 * السداد القديم FIFO والسداد المحدد يحترم sale_movement_ulid. لا نضيف
 * رصيد الجملة فوق الرصيد الموحد إذا كانت الفاتورة نفسها ممثلة فيه.
 */
class P1MerchantOperationsReportService
{
    public function __construct(
        private readonly CreditSourceSettlementService $creditSources,
    ) {
    }

    public function inventoryControl(?string $from = null, ?string $to = null): array
    {
        [$fromDate, $toDate] = $this->period($from, $to);
        foreach (['merchant_products', 'product_stocks', 'merchant_locations'] as $table) {
            if (! Schema::hasTable($table)) return $this->unavailable($table);
        }

        $products = DB::table('merchant_products as p')
            ->leftJoin('product_stocks as s', 's.product_id', '=', 'p.id')
            ->selectRaw("p.id, p.merchant_user_id, p.name, p.track_stock, p.reorder_level,
                p.expiry_date, p.cost_price,
                COALESCE(SUM(s.on_hand),0) as on_hand,
                COALESCE(SUM(s.reserved),0) as reserved,
                COALESCE(SUM(s.reorder_level),0) as location_reorder")
            ->groupBy('p.id', 'p.merchant_user_id', 'p.name', 'p.track_stock', 'p.reorder_level', 'p.expiry_date', 'p.cost_price')
            ->get();

        $onHand = '0.000';
        $reserved = '0.000';
        $outOfStock = 0;
        $negative = 0;
        $lowStock = 0;
        $tracked = 0;
        $expired = 0;
        $expiring30 = 0;
        $today = now()->startOfDay();
        $in30 = now()->addDays(30)->endOfDay();

        foreach ($products as $p) {
            $qty = bcadd((string) ($p->on_hand ?? '0'), '0', 3);
            $res = bcadd((string) ($p->reserved ?? '0'), '0', 3);
            $onHand = bcadd($onHand, $qty, 3);
            $reserved = bcadd($reserved, $res, 3);

            if ((bool) $p->track_stock) $tracked++;
            if (bccomp($qty, '0', 3) === 0 && (bool) $p->track_stock) $outOfStock++;
            if (bccomp($qty, '0', 3) < 0) $negative++;

            $threshold = bccomp((string) ($p->location_reorder ?? '0'), '0', 3) > 0
                ? (string) $p->location_reorder
                : (string) ($p->reorder_level ?? '0');
            if ((bool) $p->track_stock && bccomp($threshold, '0', 3) > 0 && bccomp($qty, $threshold, 3) <= 0) {
                $lowStock++;
            }

            if ($p->expiry_date) {
                $expiry = Carbon::parse($p->expiry_date)->startOfDay();
                if ($expiry->lt($today)) $expired++;
                elseif ($expiry->lte($in30)) $expiring30++;
            }
        }

        $movementsByReason = [];
        if (Schema::hasTable('stock_movements')) {
            $movementsByReason = DB::table('stock_movements')
                ->whereBetween('created_at', [$fromDate, $toDate])
                ->selectRaw('reason as value, COUNT(*) as total')
                ->groupBy('reason')->orderByDesc('total')->get()
                ->map(fn ($r) => ['value' => (string) $r->value, 'total' => (int) $r->total])
                ->all();
        }

        $locations = DB::table('merchant_locations')
            ->selectRaw('kind as value, COUNT(*) as total')
            ->whereNull('deleted_at')
            ->groupBy('kind')->get()
            ->map(fn ($r) => ['value' => (string) $r->value, 'total' => (int) $r->total])
            ->all();

        return [
            'report' => 'inventory_valuation',
            'basis' => 'product_stocks snapshots + stock_movements truth + merchant_products metadata',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'products' => $products->count(),
            'tracked_products' => $tracked,
            'on_hand_units' => $onHand,
            'reserved_units' => $reserved,
            'available_units' => bcsub($onHand, $reserved, 3),
            'out_of_stock_products' => $outOfStock,
            'low_stock_products' => $lowStock,
            'negative_stock_products' => $negative,
            'expired_products' => $expired,
            'expiring_30_days' => $expiring30,
            'locations_by_kind' => $locations,
            'movements_by_reason' => $movementsByReason,
            // cost_price لا يحمل عملة، ولا توجد سياسة FIFO/WA موحّدة مثبتة
            // لكل القطاعات؛ لذا جمعه كـ«قيمة مخزون» سيكون رقماً غير قابل للدفاع.
            'valuation_available' => false,
            'valuation_reason' => 'current product cost has no explicit currency and no platform-wide inventory costing policy is recorded',
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * تقرير تقادم الذمم الموحد.
     *
     * المصدر المالي للحساب الموحد هو current_balance، بينما توزيع العمر
     * يعاد بناؤه من حركات البيع والسداد نفسها. أي تعديل موجب بلا فاتورة
     * يظهر صراحةً كرصيد غير مؤرخ بدلاً من اختلاق تاريخ استحقاق له.
     *
     * فواتير الجملة الحديثة تُسجل أيضاً في الدفتر الموحد. لذلك لا نجمع
     * wholesale.balance_due مرة ثانية؛ نضيف فقط الفواتير التاريخية المفتوحة
     * التي لا يوجد لها sale movement موحد، ونكشف أي اختلاف بين المرآتين.
     */
    public function creditControl(): array
    {
        $emptyBuckets = fn (): array => [
            'current' => '0.0000',
            '1_30' => '0.0000',
            '31_60' => '0.0000',
            '61_90' => '0.0000',
            'over_90' => '0.0000',
        ];

        $unified = [
            'available' => Schema::hasTable('customer_credit_accounts') && Schema::hasTable('customer_credit_movements'),
            'accounts' => 0,
            'active_accounts' => 0,
            'linked_to_amial_customer' => 0,
            'receivable' => '0.0000',
            'open_invoices' => 0,
            'overdue_invoices' => 0,
            'overdue_receivable' => '0.0000',
            'invoice_backed_receivable' => '0.0000',
            'unaged_non_invoice_receivable' => '0.0000',
            'accounts_with_unaged_balance' => 0,
            'over_limit_accounts' => 0,
            'buckets' => $emptyBuckets(),
            'reconciliation_anomalies' => 0,
            'reconciliation_gap' => '0.0000',
        ];

        $unifiedWholesaleOpen = [];
        $linkedWholesaleRefs = [];

        if ($unified['available']) {
            $rows = DB::table('customer_credit_accounts')->get([
                'id', 'credit_limit', 'current_balance', 'is_active', 'customer_user_id',
            ]);
            $unified['accounts'] = $rows->count();

            $openByAccount = $this->creditSources->openInvoicesForAccounts($rows->pluck('id')->all());
            $today = now()->startOfDay();

            foreach ($rows as $row) {
                $balance = bcadd((string) ($row->current_balance ?? '0'), '0', 4);
                $limit = bcadd((string) ($row->credit_limit ?? '0'), '0', 4);
                if (bccomp($balance, '0', 4) > 0) {
                    $unified['receivable'] = bcadd($unified['receivable'], $balance, 4);
                }
                if ((bool) $row->is_active) $unified['active_accounts']++;
                if ($row->customer_user_id !== null) $unified['linked_to_amial_customer']++;
                if (bccomp($limit, '0', 4) > 0 && bccomp($balance, $limit, 4) > 0) $unified['over_limit_accounts']++;

                $invoiceTotal = '0.0000';
                foreach ($openByAccount[(int) $row->id] ?? [] as $invoice) {
                    $remaining = bcadd((string) $invoice['remaining'], '0', 4);
                    if (bccomp($remaining, '0', 4) <= 0) continue;

                    $invoiceTotal = bcadd($invoiceTotal, $remaining, 4);
                    $unified['invoice_backed_receivable'] = bcadd($unified['invoice_backed_receivable'], $remaining, 4);
                    $unified['open_invoices']++;

                    $bucket = $this->agingBucket($invoice['due_date'] ?? null, $today);
                    $unified['buckets'][$bucket] = bcadd($unified['buckets'][$bucket], $remaining, 4);
                    if ($bucket !== 'current') {
                        $unified['overdue_invoices']++;
                        $unified['overdue_receivable'] = bcadd($unified['overdue_receivable'], $remaining, 4);
                    }

                    if (($invoice['reference_type'] ?? null) === 'wholesale_invoice'
                        && ! empty($invoice['reference_id'])) {
                        $ref = (string) $invoice['reference_id'];
                        $unifiedWholesaleOpen[$ref] = bcadd($unifiedWholesaleOpen[$ref] ?? '0.0000', $remaining, 4);
                    }
                }

                $gap = bcsub($balance, $invoiceTotal, 4);
                $unified['reconciliation_gap'] = bcadd($unified['reconciliation_gap'], $gap, 4);
                if (bccomp($gap, '0', 4) > 0) {
                    // تعديل موجب أو رصيد تاريخي بلا فاتورة: يبقى مستحقاً لكن
                    // لا نختلق له due_date، لذلك يظهر في خانة غير مؤرخة.
                    $unified['unaged_non_invoice_receivable'] = bcadd(
                        $unified['unaged_non_invoice_receivable'], $gap, 4,
                    );
                    $unified['accounts_with_unaged_balance']++;
                } elseif (bccomp($gap, '0', 4) < 0) {
                    // فواتير مفتوحة أكبر من الرصيد الحالي لا يمكن تفسيرها
                    // كتعديل موجب؛ هذه علامة عدم اتساق تحتاج تحقيقاً.
                    $unified['reconciliation_anomalies']++;
                }
            }

            $linkedWholesaleRefs = DB::table('customer_credit_movements')
                ->where('type', 'sale')
                ->where('reference_type', 'wholesale_invoice')
                ->whereNotNull('reference_id')
                ->pluck('reference_id')
                ->map(fn ($v) => (string) $v)
                ->unique()
                ->flip()
                ->all();
        }

        $wholesale = [
            'available' => Schema::hasTable('wholesale_invoices'),
            'open_invoices' => 0,
            'receivable' => '0.0000',
            'mirrored_in_unified' => 0,
            'legacy_orphan_invoices' => 0,
            'legacy_orphan_receivable' => '0.0000',
            'source_divergences' => 0,
            'source_divergence_amount' => '0.0000',
            'legacy_orphan_buckets' => $emptyBuckets(),
        ];

        if ($wholesale['available']) {
            $today = now()->startOfDay();
            $invoices = DB::table('wholesale_invoices')
                ->whereIn('status', ['issued', 'partial_paid', 'overdue'])
                ->where('balance_due', '>', 0)
                ->get(['invoice_ulid', 'balance_due', 'due_date']);
            $wholesale['open_invoices'] = $invoices->count();

            foreach ($invoices as $invoice) {
                $balance = bcadd((string) $invoice->balance_due, '0', 4);
                $ref = (string) $invoice->invoice_ulid;
                $wholesale['receivable'] = bcadd($wholesale['receivable'], $balance, 4);

                if (isset($linkedWholesaleRefs[$ref])) {
                    $wholesale['mirrored_in_unified']++;
                    $unifiedBalance = $unifiedWholesaleOpen[$ref] ?? '0.0000';
                    if (bccomp($unifiedBalance, $balance, 4) !== 0) {
                        $wholesale['source_divergences']++;
                        $diff = bcsub($balance, $unifiedBalance, 4);
                        if (str_starts_with($diff, '-')) $diff = ltrim($diff, '-');
                        $wholesale['source_divergence_amount'] = bcadd($wholesale['source_divergence_amount'], $diff, 4);
                    }
                    continue;
                }

                // سجل قديم لم يدخل بعد الدفتر الموحد: نضيفه مرة واحدة فقط
                // إلى إجمالي الذمم ونوضح أنه legacy بدلاً من مضاعفة الدين.
                $wholesale['legacy_orphan_invoices']++;
                $wholesale['legacy_orphan_receivable'] = bcadd($wholesale['legacy_orphan_receivable'], $balance, 4);
                $bucket = $this->agingBucket($invoice->due_date ? (string) $invoice->due_date : null, $today);
                $wholesale['legacy_orphan_buckets'][$bucket] = bcadd(
                    $wholesale['legacy_orphan_buckets'][$bucket], $balance, 4,
                );
            }
        }

        $totalKnown = bcadd($unified['receivable'], $wholesale['legacy_orphan_receivable'], 4);
        $agedKnown = bcadd($unified['invoice_backed_receivable'], $wholesale['legacy_orphan_receivable'], 4);
        $coverage = bccomp($totalKnown, '0', 4) > 0
            ? bcdiv(bcmul($agedKnown, '100', 4), $totalKnown, 2)
            : '100.00';

        return [
            'report' => 'credit_aging',
            'currency' => Currencies::BASE,
            'basis' => 'unified append-only credit movements replay + wholesale legacy mirror control',
            'unified_credit' => $unified,
            'wholesale' => $wholesale,
            'total_known_receivable' => $totalKnown,
            'aged_receivable' => $agedKnown,
            'unaged_receivable' => $unified['unaged_non_invoice_receivable'],
            'aging_coverage_pct' => $coverage,
            'invoice_aging_available' => true,
            'fully_unified_aging' => $unified['accounts_with_unaged_balance'] === 0
                && $unified['reconciliation_anomalies'] === 0
                && $wholesale['legacy_orphan_invoices'] === 0
                && $wholesale['source_divergences'] === 0,
            'controls' => [
                'wholesale_double_count_prevented' => true,
                'selected_sale_payments_respected' => true,
                'legacy_unallocated_payments_replayed_fifo' => true,
                'non_invoice_positive_adjustments_are_unaged_not_guessed' => true,
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function agingBucket(?string $dueDate, Carbon $today): string
    {
        if (! $dueDate) return 'current';
        $due = Carbon::parse($dueDate)->startOfDay();
        if ($due->gte($today)) return 'current';

        $days = $due->diffInDays($today);
        return match (true) {
            $days <= 30 => '1_30',
            $days <= 60 => '31_60',
            $days <= 90 => '61_90',
            default => 'over_90',
        };
    }

    private function period(?string $from, ?string $to): array
    {
        return [
            Carbon::parse($from ?: now()->startOfMonth()->toDateString())->startOfDay(),
            Carbon::parse($to ?: now()->toDateString())->endOfDay(),
        ];
    }

    private function unavailable(string $source): array
    {
        return ['available' => false, 'source' => $source, 'reason' => 'source_table_missing', 'generated_at' => now()->toIso8601String()];
    }
}
