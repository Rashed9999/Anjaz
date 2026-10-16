<?php

namespace App\Services\Merchant;

use App\Models\FuelPump;
use App\Models\FuelSale;
use App\Models\MerchantProduct;
use App\Models\MerchantProfile;
use App\Models\PharmacyBatch;
use App\Models\PharmacyProduct;
use App\Models\RestaurantOrder;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Models\WholesaleBusiness;
use App\Models\WholesaleInvoice;
use App\Services\SalesBreakdownService;
use App\Services\Retail\StockService;
use App\Support\Access\AccessConstants as A;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Merchant Portal V2 — vertical-specific operational intelligence.
 *
 * This service never invents a universal metric across incompatible sectors.
 * Each vertical publishes a small, explicit contract from its real source.
 */
final class MerchantSectorDashboardService
{
    public function __construct(
        private readonly SalesBreakdownService $salesBreakdown,
        private readonly StockService $stock,
    ) {}

    public function build(User $merchant, int $days = 14): array
    {
        $days = max(7, min(30, $days));
        $vertical = (string) (MerchantProfile::where('user_id', $merchant->id)
            ->value('business_type') ?: A::BIZ_RETAIL);

        return match ($vertical) {
            A::BIZ_PHARMACY => $this->pharmacy($merchant),
            A::BIZ_FUEL => $this->fuel($merchant),
            A::BIZ_RESTAURANT => $this->restaurant($merchant),
            A::BIZ_WHOLESALE => $this->wholesale($merchant),
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL => $this->retail($merchant, $days, $vertical),
            default => [
                'vertical' => $vertical,
                'kind' => 'generic',
                'cards' => [],
                'lists' => [],
            ],
        };
    }

    private function retail(User $merchant, int $days, string $vertical): array
    {
        $to = now()->toDateString();
        $from = now()->subDays($days - 1)->toDateString();
        $breakdown = $this->salesBreakdown->report($merchant, $from, $to);

        // المخزون قطاعيّ، لكن مصدر الحقيقة مشترك: رصيد الموقع لا مرآة
        // merchant_products.quantity. فالمستودع الممتلئ لا يجعل فرعاً
        // نافداً «سليماً»، والمحجوز لا يُعد متاحاً للبيع.
        $low = $this->stock->lowStock($merchant->id);
        $out = $this->stock->outOfStock($merchant->id);
        $negative = $this->stock->negativeStock($merchant->id);
        $health = $this->stock->healthCounts($merchant->id);

        $stockAttention = collect($out)
            ->map(fn (array $row) => [
                ...$row,
                'state' => bccomp((string) $row['available'], '0', 3) < 0 ? 'negative' : 'out',
            ])
            ->concat(
                collect($low)
                    ->filter(fn (array $row) => bccomp((string) $row['available'], '0', 3) > 0)
                    ->map(fn (array $row) => [...$row, 'state' => 'low'])
            )
            ->take(8)->values()->all();

        return [
            'vertical' => $vertical,
            'kind' => 'retail',
            'cards' => [
                ['code' => 'low_stock', 'label' => 'مواقع تحت حد إعادة الطلب', 'value' => $health['low_locations'], 'tone' => $health['low_locations'] ? 'warning' : 'ok'],
                ['code' => 'out_of_stock', 'label' => 'مواقع نافدة للبيع', 'value' => $health['out_locations'], 'tone' => $health['out_locations'] ? 'danger' : 'ok'],
                ['code' => 'negative_stock', 'label' => 'أرصدة سالبة تحتاج جرداً', 'value' => $health['negative_locations'], 'tone' => $health['negative_locations'] ? 'danger' : 'ok'],
                ['code' => 'sold_qty', 'label' => 'صافي الوحدات المباعة', 'value' => $breakdown['totals']['qty'], 'tone' => 'neutral'],
                ['code' => 'cost_coverage', 'label' => 'أسطر بتكلفة مجهولة', 'value' => $breakdown['cost_coverage']['unknown_cost_lines'], 'tone' => $breakdown['cost_coverage']['unknown_cost_lines'] ? 'warning' : 'ok'],
            ],
            'lists' => [
                'stock_attention' => $stockAttention,
                'top_products' => array_slice($breakdown['items'], 0, 5),
                'top_categories' => array_slice($breakdown['categories'], 0, 5),
            ],
            'meta' => [
                'range' => $breakdown['range'],
                'source' => 'product_stocks + stock_movements + merchant_sale_items',
                'stock_scope' => 'active_locations',
                'cost_note' => $breakdown['cost_coverage']['note'],
            ],
        ];
    }

    private function pharmacy(User $merchant): array
    {
        $pharmacyId = DB::table('pharmacies')->where('merchant_user_id', $merchant->id)->value('id');
        if (! $pharmacyId) {
            return ['vertical' => A::BIZ_PHARMACY, 'kind' => 'pharmacy', 'cards' => [], 'lists' => [], 'meta' => ['configured' => false]];
        }

        $products = PharmacyProduct::where('pharmacy_id', $pharmacyId)->where('is_active', true);
        $low = (clone $products)->whereColumn('current_stock', '<=', 'low_stock_threshold')->count();
        $out = (clone $products)->where('current_stock', '<=', 0)->count();

        $batchBase = PharmacyBatch::whereHas('product', fn ($q) => $q->where('pharmacy_id', $pharmacyId))
            ->where('quantity_remaining', '>', 0);

        $expired = (clone $batchBase)->whereDate('expiry_date', '<', now()->toDateString())->count();
        $near = (clone $batchBase)
            ->whereDate('expiry_date', '>=', now()->toDateString())
            ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
            ->count();

        $expiring = (clone $batchBase)
            ->with('product:id,trade_name')
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(60)->toDateString())
            ->orderBy('expiry_date')
            ->limit(8)
            ->get(['id', 'product_id', 'batch_number', 'expiry_date', 'quantity_remaining', 'status'])
            ->map(fn ($b) => [
                'id' => $b->id,
                'product' => $b->product?->trade_name ?: '—',
                'batch_number' => $b->batch_number,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'quantity_remaining' => (string) $b->quantity_remaining,
                'status' => $b->isExpired() ? 'expired' : ($b->isNearExpiry(30) ? 'near_expiry' : 'upcoming'),
            ])->all();

        return [
            'vertical' => A::BIZ_PHARMACY,
            'kind' => 'pharmacy',
            'cards' => [
                ['code' => 'low_stock', 'label' => 'أدوية منخفضة', 'value' => $low, 'tone' => $low ? 'warning' : 'ok'],
                ['code' => 'out_of_stock', 'label' => 'أدوية نافدة', 'value' => $out, 'tone' => $out ? 'danger' : 'ok'],
                ['code' => 'near_expiry', 'label' => 'دفعات تنتهي خلال 30 يومًا', 'value' => $near, 'tone' => $near ? 'warning' : 'ok'],
                ['code' => 'expired_batches', 'label' => 'دفعات منتهية وبها رصيد', 'value' => $expired, 'tone' => $expired ? 'danger' : 'ok'],
            ],
            'lists' => ['expiring_batches' => $expiring],
            'meta' => ['configured' => true, 'source' => 'pharmacy_products + pharmacy_batches'],
        ];
    }

    private function fuel(User $merchant): array
    {
        $stationId = DB::table('fuel_stations')->where('merchant_user_id', $merchant->id)->value('id');
        if (! $stationId) {
            return ['vertical' => A::BIZ_FUEL, 'kind' => 'fuel', 'cards' => [], 'lists' => [], 'meta' => ['configured' => false]];
        }

        $activePumps = FuelPump::where('station_id', $stationId)->where('is_active', true)->count();
        $activeProducts = DB::table('fuel_products')->where('station_id', $stationId)->where('is_active', true)->count();

        $today = FuelSale::where('station_id', $stationId)->where('status', 'completed')
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()]);
        $liters = (string) ((clone $today)->sum('liters') ?: '0');
        $sales = (string) ((clone $today)->sum('total_amount') ?: '0');

        $byProduct = FuelSale::query()
            ->join('fuel_products as fp', 'fp.id', '=', 'fuel_sales.fuel_product_id')
            ->where('fuel_sales.station_id', $stationId)
            ->where('fuel_sales.status', 'completed')
            ->whereBetween('fuel_sales.created_at', [now()->startOfDay(), now()->endOfDay()])
            ->selectRaw('fp.id, fp.name, SUM(fuel_sales.liters) as liters, SUM(fuel_sales.total_amount) as total')
            ->groupBy('fp.id', 'fp.name')
            ->orderByDesc('liters')
            ->limit(8)->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'liters' => (string) $r->liters,
                'total' => (string) $r->total,
            ])->all();

        return [
            'vertical' => A::BIZ_FUEL,
            'kind' => 'fuel',
            'cards' => [
                ['code' => 'active_pumps', 'label' => 'المضخات النشطة', 'value' => $activePumps, 'tone' => 'neutral'],
                ['code' => 'fuel_products', 'label' => 'أنواع الوقود', 'value' => $activeProducts, 'tone' => 'neutral'],
                ['code' => 'liters_today', 'label' => 'لترات اليوم', 'value' => bcadd($liters, '0', 3), 'tone' => 'ok'],
                ['code' => 'sales_today', 'label' => 'قيمة مبيعات الوقود اليوم', 'value' => bcadd($sales, '0', 4), 'tone' => 'ok', 'money' => true],
            ],
            'lists' => ['by_product' => $byProduct],
            'meta' => ['configured' => true, 'source' => 'fuel_sales + fuel_pumps + fuel_products'],
        ];
    }

    private function restaurant(User $merchant): array
    {
        $activeOrders = RestaurantOrder::where('merchant_user_id', $merchant->id)
            ->whereIn('status', RestaurantOrder::ACTIVE);

        $statusCounts = (clone $activeOrders)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')->pluck('cnt', 'status');

        $tables = RestaurantTable::where('merchant_user_id', $merchant->id)->where('is_active', true);
        $activeTables = (clone $tables)->count();
        $occupiedTables = (clone $tables)->where('status', '!=', 'available')->count();

        $recent = (clone $activeOrders)->orderBy('opened_at')->limit(8)->get([
            'id', 'order_no', 'invoice_number', 'status', 'total', 'opened_at', 'table_id',
        ])->map(fn ($o) => [
            'id' => $o->id,
            'order_no' => $o->order_no,
            'invoice_number' => $o->invoice_number,
            'status' => $o->status,
            'total' => (string) $o->total,
            'opened_at' => $o->opened_at?->toIso8601String(),
            'table_id' => $o->table_id,
        ])->all();

        return [
            'vertical' => A::BIZ_RESTAURANT,
            'kind' => 'restaurant',
            'cards' => [
                ['code' => 'active_orders', 'label' => 'طلبات مفتوحة', 'value' => (clone $activeOrders)->count(), 'tone' => 'neutral'],
                ['code' => 'preparing', 'label' => 'قيد التحضير', 'value' => (int) ($statusCounts['preparing'] ?? 0), 'tone' => 'warning'],
                ['code' => 'ready', 'label' => 'جاهزة للتقديم', 'value' => (int) ($statusCounts['ready'] ?? 0), 'tone' => 'ok'],
                ['code' => 'occupied_tables', 'label' => 'طاولات مشغولة', 'value' => $occupiedTables . ' / ' . $activeTables, 'tone' => 'neutral'],
            ],
            'lists' => ['active_orders' => $recent],
            'meta' => ['source' => 'restaurant_orders + restaurant_tables'],
        ];
    }

    private function wholesale(User $merchant): array
    {
        $businessId = WholesaleBusiness::where('merchant_user_id', $merchant->id)->value('id');
        if (! $businessId) {
            return ['vertical' => A::BIZ_WHOLESALE, 'kind' => 'wholesale', 'cards' => [], 'lists' => [], 'meta' => ['configured' => false]];
        }

        $open = WholesaleInvoice::where('business_id', $businessId)
            ->whereIn('status', ['issued', 'partial_paid', 'overdue']);

        $overdue = (clone $open)->where('balance_due', '>', 0)
            ->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString());
        $dueSoon = (clone $open)->where('balance_due', '>', 0)
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()]);

        $pendingRefundRows = \App\Models\WholesaleReturn::where('business_id', $businessId)
            ->where('status', 'approved')
            ->where('refund_due_amount', '>', 0)
            ->withSum('settlements as refund_paid_amount', 'amount')
            ->get(['id', 'refund_due_amount']);

        $pendingRefund = '0';
        foreach ($pendingRefundRows as $ret) {
            $remaining = bcsub(
                (string) $ret->refund_due_amount,
                (string) ($ret->refund_paid_amount ?? '0'),
                4
            );
            if (bccomp($remaining, '0', 4) > 0) {
                $pendingRefund = bcadd($pendingRefund, $remaining, 4);
            }
        }

        $overdueRows = (clone $overdue)
            ->with('customer:id,full_name,company_name')
            ->orderBy('due_date')
            ->limit(8)
            ->get(['id', 'invoice_number', 'customer_id', 'due_date', 'balance_due', 'status'])
            ->map(fn ($i) => [
                'id' => $i->id,
                'invoice_number' => $i->invoice_number,
                'customer' => $i->customer?->company_name ?: $i->customer?->full_name ?: '—',
                'due_date' => $i->due_date?->toDateString(),
                'balance_due' => (string) $i->balance_due,
                'days_overdue' => $i->daysOverdue(),
            ])->all();

        return [
            'vertical' => A::BIZ_WHOLESALE,
            'kind' => 'wholesale',
            'cards' => [
                ['code' => 'open_balance', 'label' => 'إجمالي رصيد الفواتير المفتوحة', 'value' => bcadd((string) ((clone $open)->sum('balance_due') ?: '0'), '0', 4), 'money' => true, 'tone' => 'neutral'],
                ['code' => 'overdue_invoices', 'label' => 'فواتير متأخرة', 'value' => (clone $overdue)->count(), 'tone' => (clone $overdue)->exists() ? 'danger' : 'ok'],
                ['code' => 'overdue_amount', 'label' => 'مبلغ متأخر', 'value' => bcadd((string) ((clone $overdue)->sum('balance_due') ?: '0'), '0', 4), 'money' => true, 'tone' => 'danger'],
                ['code' => 'due_7_days', 'label' => 'يستحق خلال 7 أيام', 'value' => (clone $dueSoon)->count(), 'tone' => 'warning'],
                ['code' => 'return_refund_liability', 'label' => 'مستحقات مرتجعات للعملاء', 'value' => bcadd($pendingRefund, '0', 4), 'money' => true, 'tone' => bccomp($pendingRefund, '0', 4) > 0 ? 'danger' : 'ok'],
            ],
            'lists' => ['overdue_invoices' => $overdueRows],
            'meta' => ['configured' => true, 'source' => 'wholesale_invoices'],
        ];
    }
}
