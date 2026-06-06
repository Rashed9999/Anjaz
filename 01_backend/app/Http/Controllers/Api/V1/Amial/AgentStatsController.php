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
        $startOfDay = Carbon::now()->startOfDay();
        $wallet = $this->ledger->getOrCreateUserWallet($agent->id);

        // Cash-In: الوكيل يخصم من محفظته (debit) لإيداع للعميل
        $todayCashIn = (string) DB::table('ledger_entry_lines as lel')
            ->join('ledger_journal_entries as lje', 'lel.journal_entry_id', '=', 'lje.id')
            ->where('lel.account_id', $wallet->id)
            ->where('lel.direction', 'debit')
            ->whereIn('lje.source_type', ['agent_cash_in', 'cash_in', 'send_money'])
            ->where('lel.created_at', '>=', $startOfDay)
            ->sum('lel.amount');

        // Cash-Out: الوكيل يستلم في محفظته (credit)
        $todayCashOut = (string) DB::table('ledger_entry_lines as lel')
            ->join('ledger_journal_entries as lje', 'lel.journal_entry_id', '=', 'lje.id')
            ->where('lel.account_id', $wallet->id)
            ->where('lel.direction', 'credit')
            ->whereIn('lje.source_type', ['agent_cash_out', 'cash_out', 'request_money'])
            ->where('lel.created_at', '>=', $startOfDay)
            ->sum('lel.amount');

        $todayCount = DB::table('ledger_entry_lines')
            ->where('account_id', $wallet->id)
            ->where('created_at', '>=', $startOfDay)
            ->count();

        // العمولة: من earned_charge أو حساب commission منفصل
        $todayCommission = (string) DB::table('ledger_entry_lines as lel')
            ->join('ledger_accounts as la', 'lel.account_id', '=', 'la.id')
            ->join('ledger_journal_entries as lje', 'lel.journal_entry_id', '=', 'lje.id')
            ->where('la.account_code', "AGENT_COMMISSION_{$agent->id}")
            ->where('lel.direction', 'credit')
            ->where('lel.created_at', '>=', $startOfDay)
            ->sum('lel.amount');

        return new JsonResponse([
            'success' => true,
            'code' => 'OK',
            'message' => 'OK',
            'errors' => (object)[],
            'meta' => [
                'today_cash_in' => $todayCashIn ?: '0',
                'today_cash_out' => $todayCashOut ?: '0',
                'today_commission' => $todayCommission ?: '0',
                'today_count' => $todayCount,
                'current_balance' => (string) $wallet->current_balance,
            ],
        ]);
    }
}
