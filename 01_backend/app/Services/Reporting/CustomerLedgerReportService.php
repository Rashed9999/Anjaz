<?php

namespace App\Services\Reporting;

use App\Support\Money\Currencies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * AMIAL-CUSTOMER-REPORTS-003 — تقرير العميل من دفتره لا من أول 500 عملية.
 *
 * التقرير لا يجمع داخل Flutter ولا يستخدم float. كل عملة مستقلة، والرصيد
 * الافتتاحي/الختامي مشتق من سطور الدفتر. التعديلات الافتتاحية/التصحيحية
 * تظهر منفصلة عن نشاط العميل حتى لا تبدو دخلاً عادياً.
 */
class CustomerLedgerReportService
{
    public function summary(int $userId, ?string $from = null, ?string $to = null): array
    {
        $this->assertAvailable();
        $to ??= now()->toDateString();
        $wallets = DB::table('ledger_accounts')
            ->where('owner_user_id', $userId)
            ->where('account_code', 'like', 'USER_WALLET_%')
            ->get(['id', 'account_code', 'normal_balance', 'currency']);

        if ($wallets->isEmpty()) {
            return [
                'report' => 'customer_ledger_summary',
                'from' => $from,
                'to' => $to,
                'basis' => 'ledger_entry_lines',
                'currency_policy' => 'separate_per_currency_no_conversion',
                'by_currency' => [],
                'generated_at' => now()->toIso8601String(),
            ];
        }

        $accountIds = $wallets->pluck('id')->map(fn ($id) => (int) $id)->all();
        $openingAsOf = $from ? Carbon::parse($from)->subDay()->toDateString() : null;
        $opening = $openingAsOf ? $this->balances($accountIds, $openingAsOf) : [];
        $closing = $this->balances($accountIds, $to);
        $openingById = collect($opening)->keyBy('account_id');
        $closingById = collect($closing)->keyBy('account_id');

        $activity = $this->activity($accountIds, $from, $to);
        $byCurrency = [];

        foreach ($wallets as $wallet) {
            $currency = (string) ($wallet->currency ?: Currencies::BASE);
            $bucket = &$byCurrency[$currency];
            $bucket ??= $this->emptyCurrency($currency);
            $open = (string) ($openingById[(int) $wallet->id]['balance'] ?? '0.0000');
            $close = (string) ($closingById[(int) $wallet->id]['balance'] ?? '0.0000');
            $bucket['opening_balance'] = bcadd($bucket['opening_balance'], $open, 4);
            $bucket['closing_balance'] = bcadd($bucket['closing_balance'], $close, 4);
        }
        unset($bucket);

        foreach ($activity as $row) {
            $currency = (string) ($row['currency'] ?: Currencies::BASE);
            $bucket = &$byCurrency[$currency];
            $bucket ??= $this->emptyCurrency($currency);

            $signed = $this->signedMovement(
                (string) $row['normal_balance'],
                (string) $row['direction'],
                (string) $row['amount'],
            );
            $isAdjustment = $this->isAdjustment((string) $row['source_type']);
            $isReversal = (bool) $row['is_reversal'];
            $positive = bccomp($signed, '0', 4) >= 0;
            $absolute = $positive ? $signed : bcmul($signed, '-1', 4);

            if ($isAdjustment) {
                $key = $positive ? 'adjustments_in' : 'adjustments_out';
                $bucket[$key] = bcadd($bucket[$key], $absolute, 4);
                $bucket['adjustment_count']++;
            } else {
                $key = $positive ? 'inflows' : 'outflows';
                $bucket[$key] = bcadd($bucket[$key], $absolute, 4);
                $bucket[$positive ? 'inflow_count' : 'outflow_count']++;
                if ($isReversal) {
                    $revKey = $positive ? 'reversal_inflows' : 'reversal_outflows';
                    $bucket[$revKey] = bcadd($bucket[$revKey], $absolute, 4);
                    $bucket['reversal_count']++;
                }
            }

            $source = preg_replace('/_reversal$/', '', (string) $row['source_type']) ?: (string) $row['source_type'];
            $bucket['breakdown'][$source] ??= [
                'source_type' => $source,
                'inflows' => '0.0000',
                'outflows' => '0.0000',
                'entries' => 0,
                'reversals' => 0,
                'adjustments' => 0,
            ];
            $bucket['breakdown'][$source][$positive ? 'inflows' : 'outflows'] = bcadd(
                $bucket['breakdown'][$source][$positive ? 'inflows' : 'outflows'], $absolute, 4,
            );
            $bucket['breakdown'][$source]['entries']++;
            if ($isReversal) {
                $bucket['breakdown'][$source]['reversals']++;
            }
            if ($isAdjustment) {
                $bucket['breakdown'][$source]['adjustments']++;
            }
        }
        unset($bucket);

        $operational = $to === now()->toDateString() ? $this->operationalBalances($userId) : [];
        foreach ($byCurrency as &$bucket) {
            $bucket['net_activity'] = bcsub($bucket['inflows'], $bucket['outflows'], 4);
            $bucket['net_adjustments'] = bcsub($bucket['adjustments_in'], $bucket['adjustments_out'], 4);
            $bucket['net_movement'] = bcadd($bucket['net_activity'], $bucket['net_adjustments'], 4);
            $bucket['calculated_closing'] = bcadd($bucket['opening_balance'], $bucket['net_movement'], 4);
            $bucket['closing_difference'] = bcsub($bucket['closing_balance'], $bucket['calculated_closing'], 4);
            $bucket['reconciled'] = bccomp($bucket['closing_difference'], '0', 4) === 0;
            $bucket['breakdown'] = array_values($bucket['breakdown']);
            usort($bucket['breakdown'], fn ($a, $b) => strcmp($a['source_type'], $b['source_type']));

            $op = $operational[$bucket['currency']] ?? null;
            $bucket['operational_balance'] = $op;
            $bucket['ledger_operational_gap'] = $op === null
                ? null
                : bcsub((string) $op, $bucket['closing_balance'], 4);
            $bucket['operational_reconciled'] = $op === null
                ? null
                : bccomp($bucket['ledger_operational_gap'], '0', 4) === 0;
        }
        unset($bucket);
        ksort($byCurrency);

        return [
            'report' => 'customer_ledger_summary',
            'from' => $from,
            'to' => $to,
            'basis' => 'ledger_entry_lines',
            'currency_policy' => 'separate_per_currency_no_conversion',
            'pagination_independent' => true,
            'by_currency' => array_values($byCurrency),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return list<array{account_id:int,balance:string}> */
    private function balances(array $accountIds, string $asOf): array
    {
        return DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.account_id', $accountIds)
            ->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.posted_at', '<=', $asOf . ' 23:59:59')
            ->groupBy('a.id', 'a.normal_balance')
            ->selectRaw("a.id AS account_id, a.normal_balance,
                COALESCE(SUM(CASE WHEN l.direction='debit' THEN l.amount ELSE 0 END),0) AS debit_total,
                COALESCE(SUM(CASE WHEN l.direction='credit' THEN l.amount ELSE 0 END),0) AS credit_total")
            ->get()->map(function ($row): array {
                $debit = (string) $row->debit_total;
                $credit = (string) $row->credit_total;
                return [
                    'account_id' => (int) $row->account_id,
                    'balance' => $row->normal_balance === 'debit'
                        ? bcsub($debit, $credit, 4)
                        : bcsub($credit, $debit, 4),
                ];
            })->all();
    }

    /** @return list<array<string,mixed>> */
    private function activity(array $accountIds, ?string $from, string $to): array
    {
        $q = DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('l.account_id', $accountIds)
            ->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.posted_at', '<=', $to . ' 23:59:59');
        if ($from) {
            $q->where('e.posted_at', '>=', $from . ' 00:00:00');
        }

        return $q->groupBy(
                'e.id', 'e.source_type', 'e.is_reversal', 'e.posted_at',
                'a.id', 'a.normal_balance', 'a.currency', 'l.direction'
            )
            ->selectRaw("e.id AS entry_id, e.source_type, e.is_reversal, e.posted_at,
                a.id AS account_id, a.normal_balance, a.currency, l.direction,
                COALESCE(SUM(l.amount),0) AS amount")
            ->orderBy('e.posted_at')->orderBy('e.id')->get()
            ->map(fn ($r) => [
                'entry_id' => (int) $r->entry_id,
                'source_type' => (string) $r->source_type,
                'is_reversal' => (bool) $r->is_reversal,
                'posted_at' => (string) $r->posted_at,
                'account_id' => (int) $r->account_id,
                'normal_balance' => (string) $r->normal_balance,
                'currency' => (string) ($r->currency ?: Currencies::BASE),
                'direction' => (string) $r->direction,
                'amount' => (string) $r->amount,
            ])->all();
    }

    /** @return array<string,string> */
    private function operationalBalances(int $userId): array
    {
        if (! Schema::hasTable('e_money')) {
            return [];
        }
        $hasCurrency = Schema::hasColumn('e_money', 'currency');
        $rows = DB::table('e_money')->where('user_id', $userId)
            ->get($hasCurrency ? ['currency', 'current_balance'] : ['current_balance']);

        $result = [];
        foreach ($rows as $row) {
            $currency = $hasCurrency ? (string) ($row->currency ?: Currencies::BASE) : Currencies::BASE;
            $result[$currency] = (string) $row->current_balance;
        }
        return $result;
    }

    private function signedMovement(string $normal, string $direction, string $amount): string
    {
        $increase = ($normal === 'debit' && $direction === 'debit')
            || ($normal === 'credit' && $direction === 'credit');
        return $increase ? $amount : bcmul($amount, '-1', 4);
    }

    private function isAdjustment(string $sourceType): bool
    {
        $source = strtolower(preg_replace('/_reversal$/', '', $sourceType) ?? $sourceType);
        foreach (['opening_balance', 'external_adjustment', 'reconcile', 'correction', 'backfill'] as $needle) {
            if (str_contains($source, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function emptyCurrency(string $currency): array
    {
        return [
            'currency' => $currency,
            'opening_balance' => '0.0000',
            'closing_balance' => '0.0000',
            'inflows' => '0.0000',
            'outflows' => '0.0000',
            'adjustments_in' => '0.0000',
            'adjustments_out' => '0.0000',
            'reversal_inflows' => '0.0000',
            'reversal_outflows' => '0.0000',
            'inflow_count' => 0,
            'outflow_count' => 0,
            'adjustment_count' => 0,
            'reversal_count' => 0,
            'breakdown' => [],
        ];
    }

    private function assertAvailable(): void
    {
        foreach (['ledger_accounts', 'ledger_journal_entries', 'ledger_entry_lines'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Customer financial report unavailable: missing {$table}");
            }
        }
    }
}
