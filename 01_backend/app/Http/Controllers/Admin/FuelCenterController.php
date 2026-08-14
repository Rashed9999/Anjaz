<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fuel\FuelDelivery;
use App\Models\Fuel\FuelNozzle;
use App\Models\Fuel\FuelStockReconciliation;
use App\Models\Fuel\FuelTank;
use App\Models\FuelSale;
use App\Models\FuelShift;
use App\Models\FuelStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-FUEL-VERTICAL-001 · المرحلة ٩ — **مركزُ محطّات الوقود في اللوحة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * القاعدةُ الثانيةَ عشرة: **لا يُبنى في التطبيق ما لا تراه اللوحة.**
 *
 * وبُنيت في هذه الجولة سبعُ طبقاتٍ كاملة — خزّاناتٌ ومسدساتٌ وتوريداتٌ
 * وقياساتٌ ومصالحةٌ وأسعارٌ ونقدُ ورديّة — **ولو بقيت بلا شاشةٍ هنا لكانت
 * «مبنيّةً ولا يُوصَل إليها»**، وهو نمطُ العطل الأكثر تكراراً في أميال.
 *
 * **وما تراه الإدارةُ هنا رقابةٌ لا إدارة:** لا تُدير موظّفي المحطّة ولا
 * تعتمد أسعارَها — ذاك للتاجر. تراه، وتستعلم عنه، وتكتشف نمطاً.
 */
class FuelCenterController extends Controller
{
    public function page()
    {
        return view('admin-views.amial.fuel.index');
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data]);
    }

    /** المحطّاتُ كلُّها بمؤشّراتها — الصفحةُ الأولى. */
    public function stations(Request $request): JsonResponse
    {
        $q = FuelStation::query()->with('merchant:id,f_name,l_name,phone');

        if ($s = trim((string) $request->query('q', ''))) {
            $q->where(fn ($w) => $w->where('station_name', 'like', "%{$s}%")
                ->orWhere('city', 'like', "%{$s}%"));
        }

        // AMIAL-MERCHANT-360-DRILL-001 — **الفتحُ على تاجرٍ بعينه.**
        // يأتي من زرّ «محطّاتُه» في ملفّ التاجر ٣٦٠. وبلا هذا المرشّح
        // يفتح الزرُّ قائمةَ كلّ المحطّات فيعود المحقّقُ يبحث بالاسم —
        // وهو بعينه ما اشتُكي منه.
        if ($mid = (int) $request->query('merchant_id', 0)) {
            $q->where('merchant_user_id', $mid);
        }

        $rows = $q->orderBy('station_name')->limit(200)->get()
            ->map(function (FuelStation $st) {
                $tanks = FuelTank::where('station_id', $st->id)->get();

                // **اللترات غير المنسوبة أوّلُ ما يُعرض**: مسدسٌ بلا خزّان
                // يُخرج لتراتِه من المصالحة كلِّها، فيظهر فائضٌ يُقرأ ربحاً.
                $unlinked = FuelNozzle::whereHas('pump',
                    fn ($w) => $w->where('station_id', $st->id))
                    ->whereNull('tank_id')->count();

                return [
                    'id' => (int) $st->id,
                    'name' => $st->station_name,
                    'city' => $st->city,
                    'merchant' => trim(($st->merchant->f_name ?? '') . ' '
                        . ($st->merchant->l_name ?? '')) ?: '—',
                    'merchant_phone' => $st->merchant->phone ?? null,
                    'is_active' => (bool) $st->is_active,
                    'tanks' => $tanks->count(),
                    'unlinked_nozzles' => $unlinked,
                    'open_shift' => FuelShift::where('station_id', $st->id)
                        ->where('status', 'open')->exists(),
                    'open_variances' => FuelStockReconciliation::where('station_id', $st->id)
                        ->where('status', 'investigating')->count(),
                    'pending_deliveries' => FuelDelivery::where('station_id', $st->id)
                        ->whereIn('status', ['received', 'verified'])->count(),
                ];
            })->all();

        return $this->ok(['stations' => $rows]);
    }

    /** ملفُّ محطّةٍ واحدة — كلُّ طبقاتها في نداء. */
    public function station(int $id): JsonResponse
    {
        $st = FuelStation::with('merchant:id,f_name,l_name,phone')->findOrFail($id);

        $tanks = FuelTank::where('station_id', $st->id)->with('product:id,name')
            ->orderBy('tank_number')->get()
            ->map(fn (FuelTank $t) => [
                'id' => (int) $t->id,
                'number' => (int) $t->tank_number,
                'name' => $t->name,
                'product' => $t->product->name ?? '—',
                'capacity' => (string) $t->capacity_liters,
                'book' => (string) $t->book_liters,
                'fill_percent' => $t->fillPercent(),
                'is_low' => $t->isLow(),
            ])->all();

        $recons = FuelStockReconciliation::where('station_id', $st->id)
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (FuelStockReconciliation $r) => [
                'id' => (int) $r->id,
                'tank_id' => (int) $r->tank_id,
                'period_end' => $r->period_end?->toDateTimeString(),
                'expected' => (string) $r->expected_closing_liters,
                'actual' => (string) $r->actual_closing_liters,
                'variance' => (string) $r->variance_liters,
                'percent' => (string) $r->variance_percent,
                'status' => $r->status,
                'note' => $r->investigation_note,
            ])->all();

        $deliveries = FuelDelivery::where('station_id', $st->id)
            ->with('supplier:id,name')->orderByDesc('id')->limit(50)->get()
            ->map(fn (FuelDelivery $d) => [
                'id' => (int) $d->id,
                'supplier' => $d->supplier->name ?? '—',
                'liters' => (string) $d->quantity_liters,
                'status' => $d->status,
                'measured_variance' => $d->measuredVariance(),
                'posted_at' => $d->posted_at?->toDateTimeString(),
            ])->all();

        $shifts = FuelShift::where('station_id', $st->id)
            ->orderByDesc('id')->limit(20)->get()
            ->map(fn (FuelShift $s) => [
                'id' => (int) $s->id,
                'opened_at' => $s->opened_at?->toDateTimeString(),
                'closed_at' => $s->closed_at?->toDateTimeString(),
                'status' => $s->status,
                'expected_cash' => (string) $s->expected_cash,
                'actual_cash' => (string) $s->actual_cash,
                'variance' => (string) $s->variance,
                'needs_review' => (bool) $s->requires_admin_review,
            ])->all();

        return $this->ok([
            'station' => [
                'id' => (int) $st->id,
                'name' => $st->station_name,
                'city' => $st->city,
                'license' => $st->license_number,
                'merchant' => trim(($st->merchant->f_name ?? '') . ' '
                    . ($st->merchant->l_name ?? '')) ?: '—',
            ],
            'tanks' => $tanks,
            'reconciliations' => $recons,
            'deliveries' => $deliveries,
            'shifts' => $shifts,
            // **الرقمُ يُحسب من مصدره** — من المبيعات لا من عمودٍ مخزَّن.
            'totals' => $this->stationTotals($st),
        ]);
    }

    /**
     * فروقاتُ المخزون المفتوحةُ عبر كلّ المحطّات — **شاشةُ الرقابة**.
     *
     * وهذه ما لم يكن يراه أحد: فقدُ لتراتٍ في محطّةٍ نائيةٍ لا يظهر في
     * أيّ تقريرٍ ماليّ لأنّ النقد مطابق. **الوقودُ هو الذي نقص.**
     */
    public function openVariances(Request $request): JsonResponse
    {
        $rows = FuelStockReconciliation::whereIn('status', ['investigating'])
            ->with(['station:id,station_name,city', 'tank:id,tank_number,name'])
            ->orderByDesc('id')->limit(200)->get()
            ->map(fn (FuelStockReconciliation $r) => [
                'id' => (int) $r->id,
                'station' => $r->station->station_name ?? '—',
                'city' => $r->station->city ?? null,
                'tank' => $r->tank->name ?? "خزان {$r->tank?->tank_number}",
                'variance_liters' => (string) $r->variance_liters,
                'variance_percent' => (string) $r->variance_percent,
                'is_loss' => $r->isLoss(),
                'period_end' => $r->period_end?->toDateTimeString(),
                'age_days' => $r->created_at ? $r->created_at->diffInDays(now()) : null,
            ])->all();

        return $this->ok([
            'variances' => $rows,
            'total_loss_liters' => collect($rows)->reduce(
                static fn ($c, $r) => bccomp($r['variance_liters'], '0', 3) < 0
                    ? bcadd($c, ltrim($r['variance_liters'], '-'), 3) : $c, '0'),
        ]);
    }

    /** أرقامُ المحطّة — من المبيعات مباشرةً. */
    private function stationTotals(FuelStation $st): array
    {
        $row = FuelSale::where('station_id', $st->id)
            ->where('status', 'completed')
            ->selectRaw('COUNT(*) c, COALESCE(SUM(liters),0) l, COALESCE(SUM(total_amount),0) a')
            ->first();

        $byMethod = FuelSale::where('station_id', $st->id)
            ->where('status', 'completed')
            ->selectRaw('payment_method, COALESCE(SUM(total_amount),0) s')
            ->groupBy('payment_method')->pluck('s', 'payment_method');

        // **اللتراتُ غيرُ المنسوبة تُقال صراحةً** — لا تُطوى في المجموع.
        $unattributed = FuelSale::where('station_id', $st->id)
            ->where('status', 'completed')->whereNull('tank_id')
            ->sum('liters');

        return [
            'sales_count' => (int) ($row->c ?? 0),
            'total_liters' => bcadd((string) ($row->l ?? '0'), '0', 3),
            'total_amount' => bcadd((string) ($row->a ?? '0'), '0', 4),
            'by_method' => [
                'cash' => bcadd((string) ($byMethod['cash'] ?? '0'), '0', 4),
                'amial_pay' => bcadd((string) ($byMethod['amial_pay'] ?? '0'), '0', 4),
                'company_card' => bcadd((string) ($byMethod['company_card'] ?? '0'), '0', 4),
            ],
            'unattributed_liters' => bcadd((string) $unattributed, '0', 3),
        ];
    }
}
