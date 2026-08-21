<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AgentProfile;
use App\Models\AgentSettlement;
use App\Models\User;
use App\Services\AgentNetworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-AGENT-NETWORK-001 (v2.4) — admin management of agent network.
 */
class AdminAgentNetworkController extends Controller
{
    public function __construct(
        private readonly AgentNetworkService $network,
    ) {}

    /** GET /admin/amial/agents — قائمة الوكلاء */
    public function index(Request $request): JsonResponse
    {
        $query = AgentProfile::with('user:id,f_name,l_name,phone')
            ->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($city = $request->query('city')) {
            $query->where('location_city', $city);
        }

        $agents = $query->limit(100)->get()->map(fn($a) => [
            'user_id' => $a->user_id,
            'business_name' => $a->business_name,
            'agent_level' => $a->agent_level,
            'city' => $a->location_city,
            'status' => $a->status,
            'float' => (string)(\App\Models\EMoney::where('user_id', $a->user_id)->value('current_balance') ?? '0'),
            'daily_cash_in_limit' => (string)$a->daily_cash_in_limit,
        ]);

        return $this->ok(['agents' => $agents]);
    }

    /** POST /admin/amial/agents/{userId}/approve — موافقة تسجيل وكيل */
    public function approve(Request $request, int $userId): JsonResponse
    {
        $profile = AgentProfile::where('user_id', $userId)->first();
        if (!$profile) return $this->error('NOT_FOUND', 'الوكيل غير موجود', 404);

        $profile->update(['status' => 'active']);
        return $this->ok(['user_id' => $userId, 'status' => 'active'], 'APPROVED', 'تم تفعيل الوكيل');
    }

    /** POST /admin/amial/agents/{userId}/suspend */
    public function suspend(Request $request, int $userId): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => 'required|string|min:5|max:500']);
        if ($v->fails()) return $this->validationError($v);

        $profile = AgentProfile::where('user_id', $userId)->first();
        if (!$profile) return $this->error('NOT_FOUND', 'الوكيل غير موجود', 404);

        $profile->update(['status' => 'suspended']);
        return $this->ok(['user_id' => $userId, 'status' => 'suspended'], 'SUSPENDED', 'تم إيقاف الوكيل');
    }

    /** PUT /admin/amial/agents/{userId}/limits — تعديل الحدود */
    public function updateLimits(Request $request, int $userId): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'daily_cash_in_limit' => 'sometimes|numeric|min:0',
            'daily_cash_out_limit' => 'sometimes|numeric|min:0',
            'single_transaction_limit' => 'sometimes|numeric|min:0',
            'min_float_balance' => 'sometimes|numeric|min:0',
            'commission_rate' => 'sometimes|numeric|min:0|max:100',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $profile = AgentProfile::where('user_id', $userId)->first();
        if (!$profile) return $this->error('NOT_FOUND', 'الوكيل غير موجود', 404);

        $profile->update($request->only([
            'daily_cash_in_limit', 'daily_cash_out_limit',
            'single_transaction_limit', 'min_float_balance', 'commission_rate',
        ]));

        return $this->ok(['user_id' => $userId], 'UPDATED', 'تم تحديث الحدود');
    }

    /** GET /admin/amial/settlements/pending — تسويات بانتظار الموافقة */
    public function pendingSettlements(): JsonResponse
    {
        $settlements = AgentSettlement::pending()
            ->with('agent:id,f_name,l_name,phone')
            ->orderBy('created_at')
            ->limit(50)
            ->get();

        return $this->ok(['settlements' => $settlements]);
    }

    /** POST /admin/amial/settlements/{ulid}/approve */
    public function approveSettlement(Request $request, string $ulid): JsonResponse
    {
        $settlement = AgentSettlement::where('settlement_ulid', $ulid)->first();
        if (!$settlement) return $this->error('NOT_FOUND', 'التسوية غير موجودة', 404);

        try {
            $result = $this->network->approveSettlement($settlement, $request->user());
        } catch (\RuntimeException $e) {
            return $this->error('APPROVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'settlement_ulid' => $result->settlement_ulid,
            'status' => $result->status,
            'ledger_entry' => $result->ledger_entry_ulid,
        ], 'APPROVED', 'تم اعتماد التسوية وإضافة الرصيد');
    }

    /** GET /admin/amial/agents/network-stats — إحصاءات الشبكة */
    public function networkStats(): JsonResponse
    {
        $byStatus = AgentProfile::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')->get();
        $byLevel = AgentProfile::select('agent_level', DB::raw('count(*) as total'))
            ->groupBy('agent_level')->get();
        $byCity = AgentProfile::select('location_city', DB::raw('count(*) as total'))
            ->whereNotNull('location_city')
            ->groupBy('location_city')->orderByDesc('total')->limit(10)->get();

        $pendingSettlements = AgentSettlement::pending()->count();

        return $this->ok([
            'by_status' => $byStatus,
            'by_level' => $byLevel,
            'top_cities' => $byCity,
            'pending_settlements' => $pendingSettlements,
        ]);
    }

    // ============================================================
    private function ok(array $meta, string $code = 'OK', string $message = 'OK'): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }

    // ══════════════════════════════════════════════════════════════════
    // AMIAL-CASH-HANDOVER-001 — **الساقُ الورقيّةُ للتسوية.**
    //
    // قيدٌ في الدفتر لا يُثبت أنّ حقيبةً انتقلت. ورصيدٌ رقميٌّ سُلّم مقابل
    // نقدٍ لم يؤكّد أحدٌ استلامَه هو **مالٌ في الطريق** — وقد يكون في جيب.
    // ══════════════════════════════════════════════════════════════════

    /** تسليماتٌ لم يؤكّدها مستلِمُها بعد — الأقدمُ أوّلاً. */
    public function pendingHandovers(): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => 'OK', 'message' => 'OK', 'errors' => (object) [],
            'meta' => [
                'items' => app(\App\Services\CashHandoverService::class)->unconfirmed(),
                'directions' => \App\Services\CashHandoverService::DIRECTIONS,
            ],
        ]);
    }

    /** **المستلِمُ يؤكّد** — ولا يؤكّد المُسلِّمُ تسليمَ نفسِه. */
    public function confirmHandover(Request $request, string $ulid): JsonResponse
    {
        try {
            $row = app(\App\Services\CashHandoverService::class)
                ->confirm($ulid, $request->user(), $request->input('note'));
        } catch (\DomainException $e) {
            return $this->error('HANDOVER_REJECTED', $e->getMessage(), 422);
        }

        return new JsonResponse([
            'success' => true, 'code' => 'OK',
            'message' => 'أُكّد استلامُ النقد', 'errors' => (object) [],
            'meta' => ['handover' => $row],
        ]);
    }

    /** خلافٌ في العدّ — **يُرفَع ولا يُحذَف**. */
    public function disputeHandover(Request $request, string $ulid): JsonResponse
    {
        try {
            $row = app(\App\Services\CashHandoverService::class)
                ->dispute($ulid, $request->user(), (string) $request->input('reason', ''));
        } catch (\DomainException $e) {
            return $this->error('HANDOVER_DISPUTE_REJECTED', $e->getMessage(), 422);
        }

        return new JsonResponse([
            'success' => true, 'code' => 'OK',
            'message' => 'رُفع خلافٌ على التسليم', 'errors' => (object) [],
            'meta' => ['handover' => $row],
        ]);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[],
        ], 422);
    }
}
