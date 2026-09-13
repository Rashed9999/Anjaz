<?php

namespace App\Services\Reporting;

use App\Support\Money\Currencies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-REPORTING-CENTER-004 — رقابة المخزون والذمم.
 *
 * لا ندّعي «تقييم مخزون» قبل وجود سياسة تكلفة/عملة صريحة للصنف. كما لا
 * نوزّع دفعة آجل على فواتير لم تسجل تخصيصها. ما يمكن قياسه يُقاس، وما لا
 * يمكن إثباته يظهر كفجوة رقابية لا كصفر.
 */
class P1MerchantOperationsReportService
{
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

    public function creditControl(): array
    {
        $unified = [
            'available' => Schema::hasTable('customer_credit_accounts'),
            'accounts' => 0,
            'active_accounts' => 0,
            'linked_to_amial_customer' => 0,
            'receivable' => '0.0000',
            'over_limit_accounts' => 0,
            'aging_available' => false,
            'aging_reason' => 'payments are account-level and are not allocated to individual unified credit sales',
        ];

        if ($unified['available']) {
            $rows = DB::table('customer_credit_accounts')->get([
                'credit_limit', 'current_balance', 'is_active', 'customer_user_id',
            ]);
            $unified['accounts'] = $rows->count();
            foreach ($rows as $row) {
                $balance = bcadd((string) ($row->current_balance ?? '0'), '0', 4);
                $limit = bcadd((string) ($row->credit_limit ?? '0'), '0', 4);
                $unified['receivable'] = bcadd($unified['receivable'], $balance, 4);
                if ((bool) $row->is_active) $unified['active_accounts']++;
                if ($row->customer_user_id !== null) $unified['linked_to_amial_customer']++;
                if (bccomp($limit, '0', 4) > 0 && bccomp($balance, $limit, 4) > 0) $unified['over_limit_accounts']++;
            }
        }

        $wholesale = [
            'available' => Schema::hasTable('wholesale_invoices'),
            'receivable' => '0.0000',
            'open_invoices' => 0,
            'buckets' => ['current' => '0.0000', '1_30' => '0.0000', '31_60' => '0.0000', '61_90' => '0.0000', 'over_90' => '0.0000'],
        ];

        if ($wholesale['available']) {
            $today = now()->startOfDay();
            $invoices = DB::table('wholesale_invoices')
                ->whereIn('status', ['issued', 'partial_paid', 'overdue'])
                ->where('balance_due', '>', 0)
                ->get(['balance_due', 'due_date']);
            $wholesale['open_invoices'] = $invoices->count();

            foreach ($invoices as $invoice) {
                $balance = bcadd((string) $invoice->balance_due, '0', 4);
                $wholesale['receivable'] = bcadd($wholesale['receivable'], $balance, 4);
                if (! $invoice->due_date || Carbon::parse($invoice->due_date)->startOfDay()->gte($today)) {
                    $bucket = 'current';
                } else {
                    $days = Carbon::parse($invoice->due_date)->startOfDay()->diffInDays($today);
                    $bucket = match (true) {
                        $days <= 30 => '1_30',
                        $days <= 60 => '31_60',
                        $days <= 90 => '61_90',
                        default => 'over_90',
                    };
                }
                $wholesale['buckets'][$bucket] = bcadd($wholesale['buckets'][$bucket], $balance, 4);
            }
        }

        return [
            'report' => 'credit_aging',
            'currency' => Currencies::BASE,
            'basis' => 'customer_credit_accounts current balances + wholesale invoice outstanding balances',
            'unified_credit' => $unified,
            'wholesale' => $wholesale,
            'total_known_receivable' => bcadd($unified['receivable'], $wholesale['receivable'], 4),
            'fully_unified_aging' => false,
            'control_gap' => 'unified customer credit needs per-sale payment allocation before defensible aging buckets are possible',
            'generated_at' => now()->toIso8601String(),
        ];
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
