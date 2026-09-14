<?php

namespace App\Services\Reporting;

use App\Support\Access\AccessConstants as A;
use App\Support\Money\Currencies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-REPORTING-VERTICALS-001 — مقارنة القطاعات الستة من مصادرها الأصلية.
 *
 * لا نجمع مبالغ الجداول القطاعية القديمة لأنها لا تحمل عمود عملة. المقارنة
 * المالية المشتركة تستخدم merchant_sales.base_amount فقط، وهو مبلغ مجمّد
 * بعملة الأساس. أما الوقود/الصيدلية/الجملة/المطعم فتُعرض مؤشرات التشغيل
 * القطاعية (عدد/لترات/تراكم/تنبيهات) بلا تحويل عملة ضمني.
 */
class P1VerticalPerformanceReportService
{
    /** @return array<string,mixed> */
    public function report(?string $from = null, ?string $to = null): array
    {
        [$fromDate, $toDate] = $this->period($from, $to);

        if (! Schema::hasTable('merchant_profiles')) {
            return $this->unavailable('merchant_profiles');
        }

        $merchantCounts = DB::table('merchant_profiles')
            ->selectRaw('business_type, COUNT(*) as total')
            ->whereIn('business_type', A::ALL_BUSINESS_TYPES)
            ->groupBy('business_type')
            ->pluck('total', 'business_type');

        $commonSales = $this->commonSales($fromDate, $toDate);
        $rows = [];

        foreach (A::ALL_BUSINESS_TYPES as $vertical) {
            $row = [
                'vertical' => $vertical,
                'label' => A::BUSINESS_TYPE_LABELS[$vertical] ?? $vertical,
                'merchants' => (int) ($merchantCounts[$vertical] ?? 0),
                'base_currency' => Currencies::BASE,
                'common_pos_sales' => $commonSales[$vertical] ?? [
                    'count' => 0,
                    'base_amount' => '0.0000',
                    'cash' => 0,
                    'credit' => 0,
                    'amial_pay' => 0,
                ],
                'domain' => $this->domainMetrics($vertical, $fromDate, $toDate),
            ];
            $rows[] = $row;
        }

        return [
            'report' => 'vertical_performance',
            'from' => $fromDate->toDateString(),
            'to' => $toDate->toDateString(),
            'base_currency' => Currencies::BASE,
            'basis' => 'merchant_profiles + common merchant_sales base_amount + vertical operational tables',
            'monetary_comparison_policy' => 'only common POS base_amount is compared across sectors; legacy vertical-native monetary columns are not silently combined',
            'verticals' => $rows,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function commonSales(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('merchant_sales')) {
            return [];
        }

        $baseExpr = Schema::hasColumn('merchant_sales', 'base_amount')
            ? 'COALESCE(s.base_amount, s.total_amount)'
            : 's.total_amount';

        return DB::table('merchant_sales as s')
            ->join('merchant_profiles as p', 'p.user_id', '=', 's.merchant_user_id')
            ->whereIn('p.business_type', A::ALL_BUSINESS_TYPES)
            ->whereBetween('s.created_at', [$from, $to])
            ->selectRaw("p.business_type,
                COUNT(*) as sales_count,
                COALESCE(SUM({$baseExpr}),0) as base_amount,
                SUM(CASE WHEN s.payment_method = 'cash' THEN 1 ELSE 0 END) as cash_count,
                SUM(CASE WHEN s.payment_method = 'credit' THEN 1 ELSE 0 END) as credit_count,
                SUM(CASE WHEN s.payment_method = 'amial_pay' THEN 1 ELSE 0 END) as amial_pay_count")
            ->groupBy('p.business_type')
            ->get()
            ->mapWithKeys(fn ($r) => [(string) $r->business_type => [
                'count' => (int) $r->sales_count,
                'base_amount' => $this->decimal($r->base_amount),
                'cash' => (int) $r->cash_count,
                'credit' => (int) $r->credit_count,
                'amial_pay' => (int) $r->amial_pay_count,
            ]])->all();
    }

    /** @return array<string,mixed> */
    private function domainMetrics(string $vertical, Carbon $from, Carbon $to): array
    {
        return match ($vertical) {
            A::BIZ_FUEL => $this->fuel($from, $to),
            A::BIZ_PHARMACY => $this->pharmacy($from, $to),
            A::BIZ_WHOLESALE => $this->wholesale($from, $to),
            A::BIZ_RESTAURANT => $this->restaurant($from, $to),
            A::BIZ_RETAIL => $this->retailLike(A::BIZ_RETAIL, $from, $to),
            A::BIZ_QUICK_SALE => $this->retailLike(A::BIZ_QUICK_SALE, $from, $to),
            default => ['available' => false, 'reason' => 'unsupported_vertical'],
        };
    }

    /** @return array<string,mixed> */
    private function fuel(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('fuel_stations')) {
            return ['available' => false, 'reason' => 'fuel_stations_missing'];
        }

        $data = [
            'available' => true,
            'stations' => DB::table('fuel_stations')->count(),
            'active_stations' => DB::table('fuel_stations')->where('is_active', 1)->count(),
            'pumps' => Schema::hasTable('fuel_pumps') ? DB::table('fuel_pumps')->count() : null,
            'sales_in_period' => null,
            'liters_in_period' => null,
            'open_shifts' => null,
            'pending_variances' => null,
        ];

        if (Schema::hasTable('fuel_sales')) {
            $sales = DB::table('fuel_sales')->whereBetween('created_at', [$from, $to]);
            $data['sales_in_period'] = (clone $sales)->count();
            $data['liters_in_period'] = $this->decimal((clone $sales)
                ->where('status', 'completed')->sum('liters'));
        }
        if (Schema::hasTable('fuel_shifts')) {
            $data['open_shifts'] = DB::table('fuel_shifts')->whereNull('closed_at')->count();
        }
        if (Schema::hasTable('fuel_variance_records')) {
            $data['pending_variances'] = DB::table('fuel_variance_records')
                ->where('resolution_status', 'pending')->count();
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function pharmacy(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('pharmacies')) {
            return ['available' => false, 'reason' => 'pharmacies_missing'];
        }

        $data = [
            'available' => true,
            'pharmacies' => DB::table('pharmacies')->count(),
            'active_pharmacies' => DB::table('pharmacies')->where('is_active', 1)->count(),
            'products' => Schema::hasTable('pharmacy_products') ? DB::table('pharmacy_products')->count() : null,
            'sales_in_period' => null,
            'refunded_or_voided_in_period' => null,
            'expired_batches_with_stock' => null,
            'expiring_batches_30d' => null,
            'active_stock_alerts' => null,
        ];

        if (Schema::hasTable('pharmacy_sales')) {
            $sales = DB::table('pharmacy_sales')->whereBetween('created_at', [$from, $to]);
            $data['sales_in_period'] = (clone $sales)->count();
            $data['refunded_or_voided_in_period'] = (clone $sales)
                ->whereIn('status', ['refunded', 'voided'])->count();
        }
        if (Schema::hasTable('pharmacy_batches')) {
            $data['expired_batches_with_stock'] = DB::table('pharmacy_batches')
                ->where('expiry_date', '<', now()->toDateString())
                ->where('quantity_remaining', '>', 0)->count();
            $data['expiring_batches_30d'] = DB::table('pharmacy_batches')
                ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(30)->toDateString()])
                ->where('quantity_remaining', '>', 0)->count();
        }
        if (Schema::hasTable('pharmacy_stock_alerts')) {
            $data['active_stock_alerts'] = DB::table('pharmacy_stock_alerts')
                ->where('status', 'active')->count();
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function wholesale(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('wholesale_businesses')) {
            return ['available' => false, 'reason' => 'wholesale_businesses_missing'];
        }

        $data = [
            'available' => true,
            'businesses' => DB::table('wholesale_businesses')->count(),
            'active_businesses' => DB::table('wholesale_businesses')->where('is_active', 1)->count(),
            'products' => Schema::hasTable('wholesale_products') ? DB::table('wholesale_products')->count() : null,
            'customers' => Schema::hasTable('wholesale_customers') ? DB::table('wholesale_customers')->count() : null,
            'invoices_in_period' => null,
            'overdue_open_invoices' => null,
            'collections_in_period' => null,
        ];

        if (Schema::hasTable('wholesale_invoices')) {
            $data['invoices_in_period'] = DB::table('wholesale_invoices')
                ->whereBetween('created_at', [$from, $to])->count();
            $data['overdue_open_invoices'] = DB::table('wholesale_invoices')
                ->where('due_date', '<', now()->toDateString())
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', ['paid', 'voided'])->count();
        }
        if (Schema::hasTable('wholesale_collections')) {
            $data['collections_in_period'] = DB::table('wholesale_collections')
                ->whereBetween('created_at', [$from, $to])->count();
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function restaurant(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('restaurant_orders')) {
            return ['available' => false, 'reason' => 'restaurant_orders_missing'];
        }

        $period = DB::table('restaurant_orders')->whereBetween('created_at', [$from, $to]);

        return [
            'available' => true,
            'orders_in_period' => (clone $period)->count(),
            'closed_in_period' => (clone $period)->where('status', 'closed')->count(),
            'cancelled_in_period' => (clone $period)->where('status', 'cancelled')->count(),
            'kitchen_backlog_now' => DB::table('restaurant_orders')
                ->whereIn('status', ['open', 'preparing', 'ready'])->count(),
            'tables' => Schema::hasTable('restaurant_tables') ? DB::table('restaurant_tables')->count() : null,
            'occupied_tables' => Schema::hasTable('restaurant_tables')
                ? DB::table('restaurant_tables')->where('status', 'occupied')->count() : null,
        ];
    }

    /** @return array<string,mixed> */
    private function retailLike(string $vertical, Carbon $from, Carbon $to): array
    {
        $ids = DB::table('merchant_profiles')->where('business_type', $vertical)->pluck('user_id');
        $data = [
            'available' => Schema::hasTable('merchant_products'),
            'products' => null,
            'negative_stock_products' => null,
            'inventory_audits_in_period' => null,
        ];
        if (! $data['available']) return $data + ['reason' => 'merchant_products_missing'];
        if ($ids->isEmpty()) return $data + ['products' => 0, 'negative_stock_products' => 0, 'inventory_audits_in_period' => 0];

        $data['products'] = DB::table('merchant_products')->whereIn('merchant_user_id', $ids)->count();
        if (Schema::hasTable('product_stocks')) {
            $data['negative_stock_products'] = DB::table('product_stocks as s')
                ->join('merchant_products as p', 'p.id', '=', 's.product_id')
                ->whereIn('p.merchant_user_id', $ids)
                ->where('s.on_hand', '<', 0)
                ->distinct()->count('p.id');
        } else {
            $data['negative_stock_products'] = DB::table('merchant_products')
                ->whereIn('merchant_user_id', $ids)->where('quantity', '<', 0)->count();
        }
        if (Schema::hasTable('inventory_audits')) {
            $data['inventory_audits_in_period'] = DB::table('inventory_audits')
                ->whereIn('merchant_user_id', $ids)
                ->whereBetween('created_at', [$from, $to])->count();
        }

        return $data;
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function period(?string $from, ?string $to): array
    {
        return [
            Carbon::parse($from ?: now()->startOfMonth()->toDateString())->startOfDay(),
            Carbon::parse($to ?: now()->toDateString())->endOfDay(),
        ];
    }

    private function decimal(mixed $value): string
    {
        $value = (string) ($value ?? '0');
        return function_exists('bcadd') ? bcadd($value, '0', 4) : number_format((float) $value, 4, '.', '');
    }

    private function unavailable(string $source): array
    {
        return ['available' => false, 'source' => $source, 'reason' => 'source_table_missing', 'generated_at' => now()->toIso8601String()];
    }
}
