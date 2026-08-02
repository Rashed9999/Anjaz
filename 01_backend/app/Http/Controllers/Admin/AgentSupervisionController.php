<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AgentSupervisionService;
use Illuminate\Http\JsonResponse;
use App\Models\Agent\AgentDailySettlement;
use App\Models\User;
use App\Services\AgentDailySettlementService;
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

    // ══════════════════════════════════════════════════════════════════
    // AMIAL-DAILY-SETTLEMENT-001 — إقفال يوم الشبكة
    // ══════════════════════════════════════════════════════════════════

    /** لوحةُ يومٍ للشبكة كلّها — ومن لم يرفع في صدرها. */
    public function dailyBoard(Request $request): JsonResponse
    {
        $date = (string) $request->query('date', now()->toDateString());

        return response()->json(array_merge(
            app(AgentDailySettlementService::class)->networkDay($date),
            ['window' => app(AgentDailySettlementService::class)->windowState($date)],
        ));
    }

    /** تفاصيلُ تسويةِ وكيلٍ ليوم — بكلّ ما فيها. */
    public function dailyOne(Request $request, string $ulid): JsonResponse
    {
        $row = AgentDailySettlement::where('settlement_ulid', $ulid)->firstOrFail();
        $agent = User::find($row->agent_user_id);

        return response()->json([
            'settlement' => array_merge($row->toArray(), [
                'status_label' => AgentDailySettlement::STATUS_LABELS[$row->status] ?? $row->status,
                'conversion_label' => AgentDailySettlement::CONVERSION_LABELS[$row->conversion] ?? '',
                'window_label' => $row->window_state
                    ? (AgentDailySettlement::WINDOW_LABELS[$row->window_state] ?? '') : null,
                'agent_name' => $agent
                    ? (trim(($agent->f_name ?? '') . ' ' . ($agent->l_name ?? '')) ?: ('#' . $agent->id))
                    : '—',
            ]),
        ]);
    }

    /** قبولٌ — وهنا يتحوّل الورق إلى رصيد أو العكس. */
    public function dailyAccept(Request $request, string $ulid): JsonResponse
    {
        $row = AgentDailySettlement::where('settlement_ulid', $ulid)->firstOrFail();

        try {
            $out = app(AgentDailySettlementService::class)
                ->accept($row, $request->user(), (string) $request->input('note', ''));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $out->conversion === 'none'
                ? 'قُبلت التسوية — اليوم متعادل ولا تحويل'
                : 'قُبلت التسوية ونُفّذ التحويل: ' . $out->conversion_amount,
        ]);
    }

    public function dailyReject(Request $request, string $ulid): JsonResponse
    {
        $request->validate(['note' => 'required|string|min:10|max:500']);
        $row = AgentDailySettlement::where('settlement_ulid', $ulid)->firstOrFail();

        try {
            app(AgentDailySettlementService::class)
                ->reject($row, $request->user(), (string) $request->input('note'));
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'رُفضت التسوية — أُبلغ الوكيل بالسبب']);
    }

    /**
     * فكُّ يومٍ انقضت نافذته.
     *
     * **تدخّلُ إدارة المشروع** الذي طلبه صاحبه: من تأخّر لا يُفتح له الباب
     * تلقائياً، ولا يُغلق في وجهه إلى الأبد. يُفتح بقرارٍ باسمٍ وسبب.
     */
    public function dailyUnlock(Request $request): JsonResponse
    {
        $request->validate([
            'agent_user_id' => 'required|integer',
            'date' => 'required|date',
            'reason' => 'required|string|min:10|max:500',
        ]);

        $agent = User::where('id', $request->input('agent_user_id'))
            ->where('type', AGENT_TYPE)->first();

        if (!$agent) {
            return response()->json(['message' => 'الوكيل غير موجود'], 404);
        }

        try {
            app(AgentDailySettlementService::class)->unlock(
                $agent, (string) $request->input('date'),
                $request->user(), (string) $request->input('reason'),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'فُكّ اليوم — يستطيع الوكيل الرفع الآن، وسيُسجَّل «بفكٍّ من الإدارة» لا «في وقته»',
        ]);
    }
}
