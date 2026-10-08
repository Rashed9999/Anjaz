<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\AgentSettlement;
use App\Services\AgentNetworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-AGENT-NETWORK-001 (v2.4) — agent network API.
 */
class AgentNetworkController extends AmialApiController // AMIAL-FIX-007
{
    public function __construct(
        private readonly AgentNetworkService $network,
    ) {}

    /** GET /api/v1/amial/agent/float-dashboard */
    public function floatDashboard(Request $request): JsonResponse
    {
        $dashboard = $this->network->getFloatDashboard($request->user()->id);
        return $this->ok($dashboard);
    }

    /** GET /api/v1/amial/agent/float-statement?from=&to= — كشف حركة الرصيد */
    public function floatStatement(Request $request): JsonResponse
    {
        $statement = $this->network->getFloatStatement(
            $request->user()->id,
            $request->query('from'),
            $request->query('to'),
        );
        return $this->ok($statement);
    }

    /** POST /api/v1/amial/agent/topup-request */
    public function requestTopup(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'sometimes|nullable|in:bank,cash,internal',
            'payment_reference' => 'sometimes|nullable|string|max:100',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $settlement = $this->network->requestTopup(
                $request->user(),
                (string)$request->input('amount'),
                null,
                $request->input('payment_method', 'cash'),
                $request->input('payment_reference'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('TOPUP_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'settlement_ulid' => $settlement->settlement_ulid,
            'amount' => (string)$settlement->amount,
            'status' => $settlement->status,
        ], 'TOPUP_REQUESTED', 'تم إرسال طلب شراء الرصيد. ينتظر الموافقة.');
    }

    /** POST /api/v1/amial/agent/payout-request — استرداد سيولة الوكيل نقداً. */
    public function requestPayout(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'note' => 'sometimes|nullable|string|max:255',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $settlement = $this->network->requestPayout(
                $request->user(), (string) $request->input('amount'), 'cash', $request->input('note'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('PAYOUT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'settlement_ulid' => $settlement->settlement_ulid,
            'amount' => (string) $settlement->amount,
            'status' => $settlement->status,
        ], 'PAYOUT_REQUESTED', 'أُرسل طلب صرف السيولة وحُجز المبلغ حتى قرار الإدارة.');
    }

    /** GET /api/v1/amial/agent/settlements */
    public function settlements(Request $request): JsonResponse
    {
        $settlements = AgentSettlement::where('agent_user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['settlement_ulid', 'settlement_type', 'amount', 'status', 'payment_method',
                'payment_reference', 'note', 'created_at', 'completed_at']);

        // حالة النقد الورقي جزء من تفاصيل الصرف، لا ملاحظة مخفية في الإدارة.
        $handovers = DB::table('cash_handovers')
            ->whereIn('settlement_ulid', $settlements->pluck('settlement_ulid'))
            ->get(['handover_ulid', 'settlement_ulid', 'status', 'location', 'reference', 'delivered_at', 'received_at'])
            ->keyBy('settlement_ulid');
        $settlements->transform(function (AgentSettlement $s) use ($handovers) {
            $row = $handovers->get($s->settlement_ulid);
            $s->setAttribute('handover', $row ? (array) $row : null);
            return $s;
        });

        return $this->ok(['settlements' => $settlements]);
    }

    /** يؤكد الوكيل استلام النقد المخصص له، لا مجرد اكتمال القيد الإلكتروني. */
    public function confirmHandover(Request $request, string $ulid): JsonResponse
    {
        try {
            $handover = app(\App\Services\CashHandoverService::class)
                ->confirm($ulid, $request->user(), $request->input('note'));
            return $this->ok(['handover' => $handover], 'HANDOVER_CONFIRMED', 'تم تأكيد استلام النقد.');
        } catch (\DomainException $e) {
            return $this->error('HANDOVER_CONFIRM_FAILED', $e->getMessage(), 422);
        }
    }

    /** GET /api/v1/amial/agent/distributor-network (للموزّعين فقط) */
    public function distributorNetwork(Request $request): JsonResponse
    {
        try {
            $data = $this->network->getDistributorNetwork($request->user()->id);
        } catch (\RuntimeException $e) {
            return $this->error('NOT_DISTRIBUTOR', $e->getMessage(), 403);
        }
        return $this->ok($data);
    }

    // ============================================================
    // AMIAL-ADMIN-AGENT-CREDIT-001 — إدارة تسويات الوكلاء (أدمن فقط)
    // ============================================================

    /**
     * GET /api/v1/amial/admin/agent-settlements?status=pending
     * قائمة تسويات الوكلاء لاعتمادها/رفضها من قِبَل الإدارة.
     */
    public function adminSettlements(Request $request): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $q = AgentSettlement::with(['agent:id,f_name,l_name,phone'])
            ->orderByDesc('created_at');

        $status = $request->query('status', 'pending');
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($request->filled('agent_user_id')) {
            $q->where('agent_user_id', (int) $request->query('agent_user_id'));
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', 30)));
        $data = $q->paginate($perPage);

        return $this->ok([
            'settlements' => $data->items(),
            'pagination' => [
                'current_page' => $data->currentPage(),
                'last_page' => $data->lastPage(),
                'total' => $data->total(),
                'per_page' => $data->perPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/amial/admin/agent-settlements/{ulid}/approve
     * الإدارة تعتمد طلب شحن رصيد الوكيل → يُضاف الرصيد فوراً + قيد ledger.
     */
    public function adminApproveSettlement(Request $request, string $ulid): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $settlement = AgentSettlement::where('settlement_ulid', $ulid)->first();
        if (!$settlement) {
            return $this->error('NOT_FOUND', 'التسوية غير موجودة', 404);
        }

        try {
            $settlement = $this->network->approveSettlement($settlement, $request->user());
        } catch (\RuntimeException $e) {
            return $this->error('APPROVE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'settlement_ulid' => $settlement->settlement_ulid,
            'amount' => (string) $settlement->amount,
            'status' => $settlement->status,
            'completed_at' => optional($settlement->completed_at)->toIso8601String(),
        ], 'SETTLEMENT_APPROVED', 'تم اعتماد التسوية وإضافة الرصيد للوكيل.');
    }

    /**
     * POST /api/v1/amial/admin/agent-settlements/{ulid}/reject
     * الإدارة ترفض طلب شحن رصيد الوكيل (بلا تحريك رصيد).
     */
    public function adminRejectSettlement(Request $request, string $ulid): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $settlement = AgentSettlement::where('settlement_ulid', $ulid)->first();
        if (!$settlement) {
            return $this->error('NOT_FOUND', 'التسوية غير موجودة', 404);
        }

        try {
            $settlement = $this->network->rejectSettlement(
                $settlement, $request->user(), $request->input('reason')
            );
        } catch (\RuntimeException $e) {
            return $this->error('REJECT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'settlement_ulid' => $settlement->settlement_ulid,
            'status' => $settlement->status,
        ], 'SETTLEMENT_REJECTED', 'تم رفض التسوية.');
    }

    /**
     * POST /api/v1/amial/admin/agents/{id}/credit
     * تحويل رصيد مباشر من الإدارة إلى الوكيل (تمويل مباشر — خطوة واحدة).
     * body: { amount, reference? }
     */
    public function adminCreditAgent(Request $request, int $id): JsonResponse
    {
        if (!$this->isAdmin($request->user())) {
            return $this->error('FORBIDDEN', 'صلاحية إدارية مطلوبة', 403);
        }

        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'reference' => 'sometimes|nullable|string|max:100',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $agent = \App\Models\User::find($id);
        if (!$agent) {
            return $this->error('AGENT_NOT_FOUND', 'الوكيل غير موجود', 404);
        }

        try {
            $settlement = $this->network->adminCreditAgent(
                $agent,
                (string) $request->input('amount'),
                $request->user(),
                $request->input('reference'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('CREDIT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'settlement_ulid' => $settlement->settlement_ulid,
            'agent_user_id' => $agent->id,
            'amount' => (string) $settlement->amount,
            'status' => $settlement->status,
        ], 'AGENT_CREDITED', 'تم تحويل الرصيد إلى الوكيل بنجاح.');
    }

    // ============================================================
}
