<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AgentSupervisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-AGENT-SUPERVISION-001 — نوافذ الإدارة على شبكة شركات الصرافة.
 *
 * تُقرأ فقط. كلّ تغييرٍ على فرعٍ أو خزنة يقع من بوّابة الوكيل بيد موظّفه،
 * وله أثرٌ في `agent_cash_movements` باسم فاعله. ولو سمحنا للإدارة بتعديل
 * النقد من هنا لصار في الدرج مالٌ لا يعرف الفرع من حرّكه، وانتهى الجرد.
 */
class AgentSupervisionController extends Controller
{
    public function __construct(private readonly AgentSupervisionService $svc)
    {
    }

    /** GET hub/agents/network.json */
    public function network(): JsonResponse
    {
        return response()->json($this->svc->network());
    }

    /** GET hub/agents/branches.json */
    public function branches(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->svc->branches([
                'agent_id' => $request->query('agent_id'),
                'flag' => $request->query('flag'),
                'search' => $request->query('search'),
            ]),
        ]);
    }

    /** GET hub/agents/movements.json */
    public function movements(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->svc->movements([
                'branch_id' => $request->query('branch_id'),
                'reason' => $request->query('reason'),
                'date' => $request->query('date'),
                'limit' => (int) $request->query('limit', 100),
            ]),
        ]);
    }

    /**
     * GET hub/agents/settlement.json — مسحُ التوازن عبر الشبكة.
     *
     * كلّ رقمٍ هنا محسوبٌ من مصدره: المتوقَّع من الدفتر وسجلّ النقد،
     * والفعليّ من الأعمدة، **والفرق بينهما هو المنتَج**.
     */
    public function settlementScan(): JsonResponse
    {
        return response()->json([
            'data' => app(\App\Services\AgentSettlementEngine::class)->networkScan(),
            'statuses' => \App\Services\AgentSettlementEngine::STATUS_LABELS,
        ]);
    }

    /** GET hub/agents/{id}/settlement.json — وضع وكيلٍ واحدٍ بالتفصيل. */
    public function agentSettlement(Request $request, int $id): JsonResponse
    {
        $agent = \App\Models\User::where('id', $id)->where('type', AGENT_TYPE)->first();

        if (!$agent) {
            return response()->json(['message' => 'الوكيل غير موجود'], 404);
        }

        $engine = app(\App\Services\AgentSettlementEngine::class);

        return response()->json([
            'position' => $engine->position($agent),
            'daily' => $engine->dailySettlement($agent, $request->query('date')),
        ]);
    }
}
