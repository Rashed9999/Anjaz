<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\CentralLogics\Helpers;
use App\Models\EMoney;
use App\Models\FeeChangeLog;
use App\Models\FeeScheme;
use App\Models\Transaction;
use App\Services\FeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

/**
 * AMIAL-FEE-ENGINE-001 — لوحة تحكم نسب الأرباح/الرسوم.
 *
 *   GET  /admin/amial/fees                 — النسب النشطة لكل عملية
 *   GET  /admin/amial/fees/create          — نموذج إنشاء نسخة جديدة
 *   POST /admin/amial/fees                 — حفظ نسخة جديدة (تُلغي السابقة)
 *   GET  /admin/amial/fees/history/{code}  — تاريخ نسخ عملية
 *   POST /admin/amial/fees/{id}/deactivate — تعطيل نسخة
 *   POST /admin/amial/fees/simulate        — محاكي حيّ (JSON)
 *
 * يُغلّف بـ middleware 'admin' (في bootstrap/app.php).
 * RBAC أدق (permission: manage_fees) يُضاف لاحقاً ضمن AMIAL-RBAC.
 */
class FeeSchemeController extends Controller
{
    public function __construct(
        private readonly FeeService $feeService,
    ) {}

    public function webIndex(Request $request)
    {
        $zone = $request->query('zone', 'SOUTH');

        $active = FeeScheme::query()
            ->where('zone_code', $zone)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        return view('admin-views.amial.fees.index', [
            'active' => $active,
            'zone' => $zone,
            'allCodes' => FeeScheme::CODES,
        ]);
    }

    public function webCreate(Request $request)
    {
        $code = $request->query('code');
        $current = null;
        if ($code) {
            $current = $this->feeService->activeScheme(
                $code,
                $request->query('zone', 'SOUTH'),
                $request->query('applies_to', 'customer')
            );
        }

        return view('admin-views.amial.fees.create', [
            'codes' => FeeScheme::CODES,
            'feeTypes' => FeeScheme::FEE_TYPES,
            'appliesTo' => FeeScheme::APPLIES_TO,
            'bearers' => FeeScheme::BEARERS,
            'prefillCode' => $code,
            'current' => $current,
        ]);
    }

    public function webStore(Request $request)
    {
        $data = $this->validateInput($request);

        try {
            $scheme = $this->feeService->createVersion(
                $data,
                $request->user()?->id,
                $request->ip()
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['fee' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.amial.fees.index', ['zone' => $scheme->zone_code])
            ->with('success', translate('Fee scheme saved') . " — {$scheme->code} v{$scheme->version}");
    }

    public function webHistory(Request $request, string $code)
    {
        $versions = FeeScheme::query()
            ->where('code', $code)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        $logs = FeeChangeLog::query()
            ->where('code', $code)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('admin-views.amial.fees.history', [
            'code' => $code,
            'versions' => $versions,
            'logs' => $logs,
        ]);
    }

    /** GET /admin/amial/fees/profit — تقرير الأرباح من بيانات الرسوم */
    public function webProfit(Request $request)
    {
        // النطاق الزمني: افتراضياً هذا الشهر
        $from = $request->filled('from')
            ? \Carbon\Carbon::parse($request->query('from'))->startOfDay()
            : now()->startOfMonth();
        $to = $request->filled('to')
            ? \Carbon\Carbon::parse($request->query('to'))->endOfDay()
            : now()->endOfDay();

        // إجمالي الرسوم المحصّلة (gross) لكل نوع عملية — من صفوف الخصم (charge > 0)
        $grossByType = Transaction::query()
            ->where('charge', '>', 0)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('transaction_type, SUM(charge) as gross, COUNT(*) as cnt')
            ->groupBy('transaction_type')
            ->orderByDesc('gross')
            ->get();

        $periodGross = (string) $grossByType->sum(fn ($r) => (float) $r->gross);

        // ربح المنصّة الصافي خلال الفترة = مجموع قيود ADMIN_CHARGE (بعد حصة الوكلاء)
        $periodNet = (string) Transaction::query()
            ->where('transaction_type', ADMIN_CHARGE)
            ->whereBetween('created_at', [$from, $to])
            ->sum('credit');

        // عمولات الوكلاء خلال الفترة = الإجمالي − الصافي
        $periodAgentCommissions = bcsub(
            number_format((float) $periodGross, 4, '.', ''),
            number_format((float) $periodNet, 4, '.', ''),
            4
        );

        // الربح التراكمي الكلي = charge_earned للأدمن + الرسوم غير المسوّاة بعد
        // (AMIAL-SCALE-FEES-001: الرسوم تُجمع في دفتر وتُسوّى دورياً)
        $adminId = Helpers::get_admin_id();
        $reconciled = (string) (EMoney::where('user_id', $adminId)->value('charge_earned') ?? '0');
        $pending = (string) (\App\Models\PlatformFeeEntry::where('admin_user_id', $adminId)
            ->where('reconciled', false)->sum('amount'));
        $lifetimeNet = bcadd(
            number_format((float) $reconciled, 4, '.', ''),
            number_format((float) $pending, 4, '.', ''),
            4
        );

        // ربح اليوم
        $todayNet = (string) Transaction::query()
            ->where('transaction_type', ADMIN_CHARGE)
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->sum('credit');

        return view('admin-views.amial.fees.profit', [
            'from' => $from,
            'to' => $to,
            'grossByType' => $grossByType,
            'periodGross' => $periodGross,
            'periodNet' => $periodNet,
            'periodAgentCommissions' => $periodAgentCommissions,
            'lifetimeNet' => $lifetimeNet,
            'todayNet' => $todayNet,
        ]);
    }

    /** POST /admin/amial/fees/{id}/deactivate — تعطيل نسخة */
    public function webDeactivate(Request $request, int $id)
    {
        $this->feeService->deactivate($id, $request->user()?->id, $request->ip());

        return back()->with('success', translate('Fee scheme deactivated'));
    }

    /** محاكي حيّ — يستقبل نسخة افتراضية + مبلغ ويرجّع التفصيل دون حفظ. */
    public function simulate(Request $request): JsonResponse
    {
        try {
            $data = [
                'code' => $request->input('code', 'SEND_MONEY'),
                'zone_code' => $request->input('zone_code', 'SOUTH'),
                'applies_to' => $request->input('applies_to', 'customer'),
                'fee_type' => $request->input('fee_type', 'percent'),
                'percent_rate' => $request->input('percent_rate', 0),
                'fixed_amount' => $request->input('fixed_amount', 0),
                'min_fee' => $request->input('min_fee'),
                'max_fee' => $request->input('max_fee'),
                'agent_commission_percent' => $request->input('agent_commission_percent', 0),
                'agent_commission_fixed' => $request->input('agent_commission_fixed', 0),
                'bearer' => $request->input('bearer', 'sender'),
            ];

            $result = $this->feeService->simulate($data, $request->input('amount', 0));

            return response()->json(['success' => true, 'result' => $result]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    private function validateInput(Request $request): array
    {
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'in:' . implode(',', FeeScheme::CODES)],
            'label' => ['nullable', 'string', 'max:120'],
            'zone_code' => ['required', 'string', 'max:16'],
            'applies_to' => ['required', 'in:' . implode(',', FeeScheme::APPLIES_TO)],
            'fee_type' => ['required', 'in:' . implode(',', FeeScheme::FEE_TYPES)],
            'percent_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'fixed_amount' => ['required', 'numeric', 'min:0'],
            'min_fee' => ['nullable', 'numeric', 'min:0'],
            'max_fee' => ['nullable', 'numeric', 'min:0'],
            'agent_commission_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'agent_commission_fixed' => ['required', 'numeric', 'min:0'],
            'bearer' => ['required', 'in:' . implode(',', FeeScheme::BEARERS)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $validator->validate();

        return $validator->validated();
    }
}
