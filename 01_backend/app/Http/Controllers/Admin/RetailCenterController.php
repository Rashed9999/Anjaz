<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MerchantProduct;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductPriceVersion;
use App\Models\Retail\ProductStock;
use App\Models\Retail\SaleReturn;
use App\Models\Retail\StockCount;
use App\Models\Retail\StockMovement;
use App\Models\Retail\StockReservation;
use App\Models\Retail\StockTransfer;
use App\Models\Retail\StockWaste;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-RETAIL-VERTICAL-001 · المرحلة ١١ — **مركزُ التجزئة في اللوحة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * القاعدةُ الثانيةَ عشرة: **لا يُبنى في التطبيق ما لا تراه اللوحة.**
 *
 * وبُنيت في هذه الجولة عشرُ طبقات — مخزونٌ بالموقع وحركاتٌ وأسطرُ مبيعةٍ
 * بتكلفةٍ وتصنيفاتٌ ومتغيّراتٌ وتحويلاتٌ وجردٌ وهالكٌ ومرتجعاتٌ وأسعارٌ
 * وحجز — **ولو بقيت بلا شاشةٍ هنا لكانت «مبنيّةً ولا يُوصَل إليها»**.
 *
 * **وما تراه الإدارةُ هنا رقابةٌ لا إدارة:** لا تعتمد جردَ تاجرٍ ولا
 * تُقرّ هالكَه — ذاك له. تراه، وتستعلم عنه، وتكتشف نمطاً.
 *
 * **وثلاثةُ أنماطٍ تُكشف من هنا ولا تُرى من داخل متجرٍ واحد:**
 *
 * | النمط | ما يعنيه |
 * |---|---|
 * | مخزونٌ سالبٌ مستمرّ | البيعُ يتجاوز المسجَّل — إدخالٌ ناقصٌ أو سرقة |
 * | هالكٌ مرتفعٌ بسبب «سرقة» | يستحقّ نظرةً لا إنذاراً آليّاً |
 * | تحويلٌ في الطريق منذ أسابيع | بضاعةٌ خرجت ولم تصل ولم يسأل أحد |
 */
class RetailCenterController extends Controller
{
    public function page()
    {
        return view('admin-views.amial.retail.index');
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    /** المتاجرُ كلُّها بمؤشّراتها — الصفحةُ الأولى. */
    public function merchants(Request $request): JsonResponse
    {
        // AMIAL-MERCHANT-360-DRILL-001 — الفتحُ على تاجرٍ بعينه من ملفّه.
        $ids = ($mid = (int) $request->query('merchant_id', 0))
            ? collect([$mid])
            : MerchantLocation::query()->distinct()->pluck('merchant_user_id');

        $q = User::whereIn('id', $ids);

        if ($s = trim((string) $request->query('q', ''))) {
            $q->where(fn ($w) => $w->where('f_name', 'like', "%{$s}%")
                ->orWhere('l_name', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%"));
        }

        $rows = $q->limit(200)->get()->map(function (User $m) {
            $locationIds = MerchantLocation::where('merchant_user_id', $m->id)->pluck('id');

            // **المخزونُ السالب أوّلُ ما يُعرض**: هو الإشارةُ التي كانت
            // تُمحى قبل المرحلة ٠ (يُقصّ إلى صفرٍ صامتاً).
            $negative = ProductStock::whereIn('location_id', $locationIds)
                ->where('on_hand', '<', 0)->count();

            $neverCounted = ProductStock::whereIn('location_id', $locationIds)
                ->whereNull('last_counted_at')->count();

            return [
                'id' => (int) $m->id,
                'name' => trim(($m->f_name ?? '') . ' ' . ($m->l_name ?? '')) ?: '—',
                'phone' => $m->phone,
                'locations' => $locationIds->count(),
                'products' => MerchantProduct::where('merchant_user_id', $m->id)->count(),
                'negative_stock_rows' => $negative,
                // **«لم يُجرَد قطّ» ليست «صفرَ فرق»** (القاعدة ٧).
                'never_counted_rows' => $neverCounted,
                'in_transit' => StockTransfer::where('merchant_user_id', $m->id)
                    ->where('status', StockTransfer::SHIPPED)->count(),
                'pending_wastes' => StockWaste::where('merchant_user_id', $m->id)
                    ->where('status', 'pending')->count(),
                'counts_in_review' => StockCount::where('merchant_user_id', $m->id)
                    ->where('status', StockCount::REVIEW)->count(),
                'held_reservations' => StockReservation::where('merchant_user_id', $m->id)
                    ->where('status', StockReservation::HELD)->count(),
            ];
        })->all();

        return $this->ok(['merchants' => $rows]);
    }

    /** تفصيلُ متجرٍ واحد. */
    public function merchant(Request $request, int $id): JsonResponse
    {
        $merchant = User::findOrFail($id);
        $locations = MerchantLocation::where('merchant_user_id', $id)->get();

        return $this->ok([
            'merchant' => [
                'id' => $merchant->id,
                'name' => trim(($merchant->f_name ?? '') . ' ' . ($merchant->l_name ?? '')) ?: '—',
                'phone' => $merchant->phone,
            ],
            'locations' => $locations->map(fn (MerchantLocation $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'code' => $l->code,
                'kind' => $l->kind,
                'products' => ProductStock::where('location_id', $l->id)->count(),
                'negative' => ProductStock::where('location_id', $l->id)
                    ->where('on_hand', '<', 0)->count(),
                'never_counted' => ProductStock::where('location_id', $l->id)
                    ->whereNull('last_counted_at')->count(),
            ])->all(),

            // التحويلاتُ **بلا أصنافها** — والعالقُ في الطريق إشارةُ انضباط.
            'transfers' => StockTransfer::where('merchant_user_id', $id)
                ->with(['fromLocation:id,name', 'toLocation:id,name'])
                ->orderByDesc('id')->limit(20)->get()
                ->map(fn (StockTransfer $t) => [
                    'code' => $t->code,
                    'from' => $t->fromLocation->name ?? '—',
                    'to' => $t->toLocation->name ?? '—',
                    'status' => $t->statusAr(),
                    'shipped_at' => $t->shipped_at?->format('Y-m-d H:i'),
                    'days_in_transit' => $t->isInTransit() && $t->shipped_at
                        ? $t->shipped_at->diffInDays(now()) : null,
                ])->all(),

            'counts' => StockCount::where('merchant_user_id', $id)
                ->with('location:id,name')->orderByDesc('id')->limit(20)->get()
                ->map(fn (StockCount $c) => [
                    'code' => $c->code,
                    'location' => $c->location->name ?? '—',
                    'kind' => $c->kindAr(),
                    'status' => $c->statusAr(),
                    'lines' => $c->items()->count(),
                    'not_counted' => $c->items()->whereNull('counted_quantity')->count(),
                    'approved_at' => $c->approved_at?->format('Y-m-d H:i'),
                ])->all(),

            // AMIAL-MERCHANT-CENTER-001 — **بلا أسماء أصناف**: أميال ترى
            // أنّ هالكاً بقيمة كذا اعتُمد، ولا تحتاج «انتهت صلاحية اللبن».
            'wastes_summary' => [
                'pending' => StockWaste::where('merchant_user_id', $id)
                    ->where('status', 'pending')->count(),
                'approved_30d' => StockWaste::where('merchant_user_id', $id)
                    ->where('status', 'approved')
                    ->where('created_at', '>=', now()->subDays(30))->count(),
                'cost_30d' => (string) StockWaste::where('merchant_user_id', $id)
                    ->where('status', 'approved')
                    ->where('created_at', '>=', now()->subDays(30))->sum('total_cost'),
            ],

            'returns' => SaleReturn::where('merchant_user_id', $id)
                ->withCount('items')->orderByDesc('id')->limit(20)->get()
                ->map(fn (SaleReturn $r) => [
                    'sale_ulid' => substr($r->sale_ulid, -8),
                    'total' => (string) $r->total_amount,
                    'lines' => $r->items_count,
                    'status' => $r->status,
                    'created_at' => $r->created_at?->format('Y-m-d'),
                ])->all(),

            // **تسعيرُ التاجر شأنُه** — يُعرض عدداً لا تفصيلاً.
            'prices_summary' => [
                'proposed' => ProductPriceVersion::where('merchant_user_id', $id)
                    ->where('status', ProductPriceVersion::PROPOSED)->count(),
                'changes_30d' => ProductPriceVersion::where('merchant_user_id', $id)
                    ->where('created_at', '>=', now()->subDays(30))->count(),
            ],
        ]);
    }

    /**
     * **المخزونُ السالبُ عبر المنصّة** — والإشارةُ التي كانت تُمحى.
     *
     * وقبل المرحلة ٠ كان بيعُ خمسٍ وفي النظام ثلاثٌ يُقصّ إلى صفرٍ صامتاً،
     * فيُقفَل الفرقُ قبل أن يُرى. وصار السالبُ يبقى ظاهراً — **وهذه
     * الشاشةُ هي التي تراه**.
     */
    public function negativeStock(): JsonResponse
    {
        // **إشارةٌ لا كشفُ أصناف**: المخزون السالب مؤشّرُ انضباطٍ يخصّ
        // أميال (بيعٌ يتجاوز المسجَّل)، **واسمُ الصنف يخصّ التاجر**.
        // فيُعرض العددُ لكلّ تاجر، ومن أراد التفصيل يفتح إذناً في مركز التاجر.
        $rows = ProductStock::where('on_hand', '<', 0)
            ->with('location:id,merchant_user_id')
            ->get()
            ->groupBy(fn (ProductStock $s) => (int) ($s->location->merchant_user_id ?? 0))
            ->map(fn ($group, $mid) => [
                'merchant_user_id' => (int) $mid,
                'rows' => $group->count(),
                'never_counted' => $group->whereNull('last_counted_at')->count(),
            ])->values()->all();

        return $this->ok(['rows' => $rows, 'count' => count($rows)]);
    }

    /**
     * **تحويلاتٌ طال طريقُها** — بضاعةٌ خرجت ولم تصل ولم يسأل أحد.
     */
    public function stuckTransfers(Request $request): JsonResponse
    {
        $days = max(1, min(90, (int) $request->query('days', 3)));

        $rows = StockTransfer::where('status', StockTransfer::SHIPPED)
            ->where('shipped_at', '<', now()->subDays($days))
            ->with(['fromLocation:id,name', 'toLocation:id,name'])
            ->orderBy('shipped_at')->limit(200)->get()
            ->map(fn (StockTransfer $t) => [
                'code' => $t->code,
                'merchant_user_id' => (int) $t->merchant_user_id,
                'from' => $t->fromLocation->name ?? '—',
                'to' => $t->toLocation->name ?? '—',
                'shipped_at' => $t->shipped_at?->format('Y-m-d'),
                'days' => $t->shipped_at ? $t->shipped_at->diffInDays(now()) : null,
                'quantity' => (string) $t->items()->sum('shipped_quantity'),
            ])->all();

        return $this->ok(['rows' => $rows, 'threshold_days' => $days]);
    }

    /**
     * **مؤشّراتُ المنصّة كلِّها** — تُقرأ منها الأنماط.
     */
    public function overview(): JsonResponse
    {
        $wasteByReason = StockWaste::where('status', 'approved')
            ->where('created_at', '>=', now()->subDays(30))
            ->select('reason', DB::raw('SUM(total_cost) as cost'), DB::raw('COUNT(*) as n'))
            ->groupBy('reason')->get()
            ->map(fn ($r) => [
                'reason' => $r->reason,
                'cost' => (string) ($r->cost ?? '0'),
                'count' => (int) $r->n,
            ])->all();

        return $this->ok([
            'merchants_with_stock' => MerchantLocation::query()
                ->distinct('merchant_user_id')->count('merchant_user_id'),
            'locations' => MerchantLocation::count(),
            'warehouses' => MerchantLocation::where('kind', 'warehouse')->count(),
            'stock_rows' => ProductStock::count(),
            'negative_rows' => ProductStock::where('on_hand', '<', 0)->count(),
            'never_counted_rows' => ProductStock::whereNull('last_counted_at')->count(),
            'movements_30d' => StockMovement::where('created_at', '>=', now()->subDays(30))->count(),
            'in_transit' => StockTransfer::where('status', StockTransfer::SHIPPED)->count(),
            'counts_in_review' => StockCount::where('status', StockCount::REVIEW)->count(),
            'pending_wastes' => StockWaste::where('status', 'pending')->count(),
            'pending_returns' => SaleReturn::where('status', 'pending')->count(),
            // **حجزٌ منتهٍ ولم يُفرَج عنه** — الأمرُ المجدول لم يعمل.
            'expired_holds' => StockReservation::where('status', StockReservation::HELD)
                ->whereNotNull('expires_at')->where('expires_at', '<', now())->count(),
            'waste_by_reason_30d' => $wasteByReason,
        ]);
    }
}
