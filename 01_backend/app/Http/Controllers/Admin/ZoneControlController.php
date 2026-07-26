<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Aml\AmlAlert;
use App\Models\User;
use App\Services\KycGeoConsistencyService;
use App\Services\ZoneAssignmentService;
use App\Services\ZonePolicyService;
use App\Support\YemenGovernorates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-ZONE-PANEL-001 — لوحة المناطق.
 *
 * سياسة المناطق أخطر ما في النظام (بنكان مركزيان وعملتان بسعرين)، وكانت
 * كلها غير مرئية: لا الأدمن يعرف كم حساباً عالق بلا منطقة، ولا كم عملية
 * رُفضت، ولا أن وكيلاً زاول من خارج النطاق. كلّها في جداول لا يفتحها أحد.
 *
 * هذه اللوحة تعرض الحالة كاملة من مصادر حقيقية:
 *   zone_assignment_logs — تاريخ إسناد المناطق
 *   audit_decisions      — الرفض بالمنطقة ومخالفات موقع الوكلاء
 *   aml_alerts           — الحسابات المصرف المغلق
 *   users                — التوزيع والعالقون
 */
class ZoneControlController extends Controller
{
    public function index(): View
    {
        return view('admin-views.amial.hub.zones', [
            'operational' => $this->operationalTable(),
            'agentLocationMode' => (string) config('amial.agent_location_mode', 'soft'),
        ]);
    }

    /** جدول المحافظات مع نطاق التشغيل وأعداد الحسابات. */
    private function operationalTable(): array
    {
        $counts = User::query()
            ->whereNotNull('residence_governorate')
            ->selectRaw('residence_governorate AS gov, type, COUNT(*) AS c')
            ->groupBy('residence_governorate', 'type')
            ->get()
            ->groupBy('gov');

        $rows = [];
        foreach (YemenGovernorates::all() as $g) {
            $byType = $counts->get($g['code'], collect());
            $rows[] = [
                'code' => $g['code'],
                'name' => $g['name'],
                'operational' => YemenGovernorates::isOperational($g['code']),
                'customers' => (int) ($byType->firstWhere('type', CUSTOMER_TYPE)->c ?? 0),
                'agents' => (int) ($byType->firstWhere('type', AGENT_TYPE)->c ?? 0),
                'merchants' => (int) ($byType->firstWhere('type', MERCHANT_TYPE)->c ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * GET zones/summary.json — الأرقام الحيّة.
     */
    public function summary(): JsonResponse
    {
        $byZone = User::whereIn('type', [CUSTOMER_TYPE, AGENT_TYPE, MERCHANT_TYPE])
            ->selectRaw('COALESCE(zone_code, \'UNKNOWN\') AS z, COUNT(*) AS c')
            ->groupBy('z')->pluck('c', 'z');

        // الحسابات المعتمدة العالقة بلا منطقة — العطل الذي كان يشلّها.
        $strandedQuery = User::whereIn('type', [CUSTOMER_TYPE, AGENT_TYPE, MERCHANT_TYPE])
            ->where('is_kyc_verified', 1)
            ->where(fn ($q) => $q->whereNull('zone_code')->orWhere('zone_code', 'UNKNOWN'));

        $since = now()->subDays(30);

        return response()->json([
            'zones' => [
                'SOUTH' => (int) ($byZone['SOUTH'] ?? 0),
                'NORTH' => (int) ($byZone['NORTH'] ?? 0),
                'MIDDLE' => (int) ($byZone['MIDDLE'] ?? 0),
                'OTHER' => (int) ($byZone['OTHER'] ?? 0),
                'UNKNOWN' => (int) ($byZone['UNKNOWN'] ?? 0),
            ],
            'stranded' => [
                'count' => $strandedQuery->count(),
                'sample' => $strandedQuery->clone()->latest('id')->limit(20)
                    ->get(['id', 'f_name', 'l_name', 'phone', 'type', 'residence_governorate'])
                    ->map(fn (User $u) => [
                        'id' => $u->id,
                        'name' => trim(($u->f_name ?? '') . ' ' . ($u->l_name ?? '')) ?: '—',
                        'phone' => $u->phone,
                        'role' => $this->roleName((int) $u->type),
                        'governorate' => YemenGovernorates::name($u->residence_governorate),
                        'fixable' => YemenGovernorates::codeFromName((string) $u->residence_governorate) !== null,
                    ])->values(),
            ],
            'blocked_30d' => (int) DB::table('audit_decisions')
                ->whereIn('decision_code', ['TX_ZONE_BLOCKED', 'ACCOUNT_ZONE_UNKNOWN'])
                ->where('created_at', '>=', $since)->count(),
            'agent_violations_30d' => (int) DB::table('audit_decisions')
                ->where('action', 'AGENT_CASH_OUTSIDE_ZONE')
                ->where('created_at', '>=', $since)->count(),
            'sink_alerts_open' => AmlAlert::where('alert_code', 'SINK_ACCOUNT')
                ->where('status', 'open')->count(),
            'agent_location_mode' => (string) config('amial.agent_location_mode', 'soft'),
        ]);
    }

    /**
     * GET zones/events.json?type=blocked|violations|assignments|sink
     */
    public function events(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', 'blocked');
        $limit = min(100, max(10, (int) $request->query('limit', 50)));

        return response()->json(['data' => match ($type) {
            'violations' => $this->auditRows(['AGENT_CASH_OUTSIDE_ZONE'], $limit, byAction: true),
            'assignments' => $this->assignmentRows($limit),
            'sink' => $this->sinkRows($limit),
            default => $this->auditRows(['TX_ZONE_BLOCKED', 'ACCOUNT_ZONE_UNKNOWN'], $limit),
        }]);
    }

    private function auditRows(array $codes, int $limit, bool $byAction = false): array
    {
        $q = DB::table('audit_decisions')
            ->when($byAction, fn ($x) => $x->whereIn('action', $codes))
            ->when(!$byAction, fn ($x) => $x->whereIn('decision_code', $codes))
            ->latest('created_at')->limit($limit)
            ->get(['actor_user_id', 'action', 'decision_code', 'reason', 'context', 'zone_code', 'severity', 'created_at']);

        $names = User::whereIn('id', $q->pluck('actor_user_id')->filter()->unique())
            ->pluck('phone', 'id');

        return $q->map(function ($r) use ($names) {
            $ctx = json_decode((string) $r->context, true) ?: [];
            $gov = $ctx['governorate'] ?? null;

            return [
                'user_id' => $r->actor_user_id,
                'phone' => $names[$r->actor_user_id] ?? '—',
                'action' => $r->action,
                'code' => $r->decision_code,
                'reason' => $r->reason,
                'zone' => $r->zone_code,
                'governorate' => $gov ? (YemenGovernorates::name($gov) ?? $gov) : null,
                'severity' => $r->severity,
                'at' => (string) $r->created_at,
            ];
        })->all();
    }

    private function assignmentRows(int $limit): array
    {
        $rows = DB::table('zone_assignment_logs')
            ->latest('created_at')->limit($limit)
            ->get(['user_id', 'assigned_zone', 'method', 'signals', 'created_at']);

        $names = User::whereIn('id', $rows->pluck('user_id')->unique())->pluck('phone', 'id');

        return $rows->map(fn ($r) => [
            'user_id' => $r->user_id,
            'phone' => $names[$r->user_id] ?? '—',
            'zone' => $r->assigned_zone,
            'method' => $r->method,
            'at' => (string) $r->created_at,
        ])->all();
    }

    private function sinkRows(int $limit): array
    {
        return AmlAlert::where('alert_code', 'SINK_ACCOUNT')
            ->latest('created_at')->limit($limit)->get()
            ->map(fn (AmlAlert $a) => [
                'user_id' => $a->subject_id,
                'title' => $a->title_ar,
                'message' => $a->message_ar,
                'status' => $a->status,
                'at' => optional($a->created_at)->format('Y-m-d H:i'),
            ])->all();
    }

    /**
     * POST zones/users/{id}/reassign — إسناد المنطقة من محافظة السكن.
     *
     * يفكّ عقدة الحسابات التي اعتُمدت قبل إصلاح مسار الاعتماد وبقيت
     * UNKNOWN: معتمدة ولا تستطيع عملية واحدة. إصلاحها بلا هذا الزر يعني
     * فتح كل حساب يدوياً في شاشة أخرى.
     */
    public function reassign(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);
        $source = $request->input('governorate')
            ?: $user->residence_governorate
            ?: $user->origin_governorate;

        $code = YemenGovernorates::codeFromName((string) $source);
        if ($code === null) {
            return response()->json([
                'message' => 'لا توجد محافظة سكن مسجّلة لهذا الحساب — حدّدها أولاً.',
            ], 422);
        }

        $zone = app(ZoneAssignmentService::class)->assignFromKyc(
            $user,
            YemenGovernorates::name($code) ?? '',
            $request->user()?->id
        );

        return response()->json([
            'message' => 'أُسندت المنطقة: ' . ZonePolicyService::zoneNameAr($zone),
            'zone' => $zone,
            'governorate' => YemenGovernorates::name($code),
        ]);
    }

    /** GET zones/users/{id}/geo-check.json — المطابقة الجغرافية لحساب. */
    public function geoCheck(int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        return response()->json(
            app(KycGeoConsistencyService::class)->evaluate($user)
        );
    }

    private function roleName(int $type): string
    {
        return match ($type) {
            AGENT_TYPE => 'وكيل',
            MERCHANT_TYPE => 'تاجر',
            default => 'عميل',
        };
    }
}
