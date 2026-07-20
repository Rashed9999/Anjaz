<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AGENT-STATS-001 (v1.7)
 *
 * إحصاءات الوكيل اليومية — تكمل الـ agent dashboard.
 */
class AgentStatsController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
    ) {}

    /** GET /api/v1/amial/agent/daily-stats */
    public function dailyStats(Request $request): JsonResponse
    {
        $agent = $request->user();
        $agentId = $agent->id;
        $startOfDay = Carbon::now()->startOfDay();

        // AMIAL-FIX(AGENT-STATS): كانت الإحصاءات تقرأ من دفتر ledger_journal_entries
        // بينما العمليات تُسجَّل فعلياً في جدول transactions + withdrawal_requests
        // (عبر TransactionTrait/guard). النتيجة: أصفار دائمة رغم تنفيذ العمليات.
        // الآن نقرأ من مصدر السجلّ الفعلي.

        // سحبات العملاء المكتملة اليوم (المصدر الموثوق: طلبات السحب)
        $wd = DB::table('withdrawal_requests')
            ->where('agent_user_id', $agentId)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $startOfDay);
        $todayCashOut = (string) ((clone $wd)->sum('amount') ?: '0');
        $todayCommission = (string) ((clone $wd)->sum('agent_commission') ?: '0');
        $cashOutCount = (clone $wd)->count();

        // إيداعات الوكيل للعملاء اليوم (صفوف transactions من نوع cash_in للوكيل)
        $cashInQ = DB::table('transactions')
            ->where('user_id', $agentId)
            ->where('transaction_type', 'cash_in')
            ->where('created_at', '>=', $startOfDay);
        $todayCashIn = (string) ((clone $cashInQ)->sum('debit') ?: '0');
        $cashInCount = (clone $cashInQ)->count();

        // رصيد المحفظة من EMoney (نظام الأرصدة الفعلي الذي تحرّكه guard)
        $emoney = \App\Models\EMoney::where('user_id', $agentId)->first();
        $balance = $emoney ? (string) $emoney->current_balance : '0';

        return new JsonResponse([
            'success' => true,
            'code' => 'OK',
            'message' => 'OK',
            'errors' => (object)[],
            'meta' => [
                'today_cash_in' => $todayCashIn,
                'today_cash_out' => $todayCashOut,
                'today_commission' => $todayCommission,
                'today_count' => $cashOutCount + $cashInCount,
                'current_balance' => $balance,
            ],
        ]);
    }
}
