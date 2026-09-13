<?php

namespace App\Services\Reporting;

use App\Support\Money\Currencies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * AMIAL-REPORTING-CENTER-004 — القوائم المالية الرسمية من الدفتر فقط.
 *
 * لا يُجمع ريال مع دولار، ولا يُقارن رصيد فترة بعمود current_balance الحالي.
 * كل عملة تُعرض مستقلة، وميزان المراجعة يعرض افتتاح الفترة وحركتها وإقفالها.
 */
class FinancialStatementsService
{
    public function trialBalance(?string $from = null, ?string $to = null): array
    {
        $this->assertLedgerAvailable();
        $to ??= now()->toDateString();
        $openingAsOf = $from ? Carbon::parse($from)->subDay()->toDateString() : null;

        $opening = $openingAsOf ? $this->balancesAt($openingAsOf) : [];
        $closing = $this->balancesAt($to);
        $activity = $this->activity($from, $to);

        $openingById = collect($opening)->keyBy('id');
        $activityById = collect($activity)->keyBy('id');
        $accounts = collect($closing)
            ->merge($opening)
            ->keyBy('id')
            ->sortBy(fn ($r) => ($r['currency'] ?? Currencies::BASE) . '|' . $r['account_code']);

        $byCurrency = [];
        foreach ($accounts as $id => $meta) {
            $currency = (string) ($meta['currency'] ?: Currencies::BASE);
            $bucket = &$byCurrency[$currency];
            $bucket ??= $this->emptyTrialCurrency($currency);

            $open = (string) ($openingById[$id]['balance'] ?? '0.0000');
            $periodDebit = (string) ($activityById[$id]['debit_total'] ?? '0.0000');
            $periodCredit = (string) ($activityById[$id]['credit_total'] ?? '0.0000');
            $close = (string) ((collect($closing)->keyBy('id')[$id]['balance'] ?? null) ?? '0.0000');

            $bucket['period_debit'] = bcadd($bucket['period_debit'], $periodDebit, 4);
            $bucket['period_credit'] = bcadd($bucket['period_credit'], $periodCredit, 4);

            [$closingDebit, $closingCredit] = $this->closingSides(
                (string) $meta['normal_balance'], $close,
            );
            $bucket['closing_debit'] = bcadd($bucket['closing_debit'], $closingDebit, 4);
            $bucket['closing_credit'] = bcadd($bucket['closing_credit'], $closingCredit, 4);

            $bucket['accounts'][] = [
                'id' => (int) $id,
                'account_code' => (string) $meta['account_code'],
                'name' => (string) $meta['name'],
                'account_type' => (string) $meta['account_type'],
                'normal_balance' => (string) $meta['normal_balance'],
                'currency' => $currency,
                'opening_balance' => $open,
                'period_debit' => $periodDebit,
                'period_credit' => $periodCredit,
                'closing_balance' => $close,
                'closing_debit' => $closingDebit,
                'closing_credit' => $closingCredit,
            ];
        }
        unset($bucket);

        $unbalanced = $this->unbalancedEntries($from, $to);
        $unbalancedByCurrency = collect($unbalanced)->groupBy('currency');

        foreach ($byCurrency as &$bucket) {
            $bucket['period_difference'] = bcsub($bucket['period_debit'], $bucket['period_credit'], 4);
            $bucket['closing_difference'] = bcsub($bucket['closing_debit'], $bucket['closing_credit'], 4);
            $bucket['unbalanced_entries'] = ($unbalancedByCurrency[$bucket['currency']] ?? collect())->values()->all();
            $bucket['balanced'] = bccomp($bucket['period_difference'], '0', 4) === 0
                && bccomp($bucket['closing_difference'], '0', 4) === 0
                && count($bucket['unbalanced_entries']) === 0;
        }
        unset($bucket);
        ksort($byCurrency);

        return [
            'statement' => 'trial_balance',
            'from' => $from,
            'to' => $to,
            'basis' => 'ledger_entry_lines',
            'currency_policy' => 'separate_per_currency_no_conversion',
            'by_currency' => array_values($byCurrency),
            'balanced' => collect($byCurrency)->every(fn ($r) => $r['balanced']),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function incomeStatement(?string $from = null, ?string $to = null): array
    {
        $this->assertLedgerAvailable();
        $from ??= now()->startOfMonth()->toDateString();
        $to ??= now()->toDateString();
        $activity = $this->activity($from, $to);
        $unbalanced = $this->unbalancedEntries($from, $to);
        $unbalancedByCurrency = collect($unbalanced)->groupBy('currency');

        $byCurrency = [];
        foreach ($activity as $account) {
            if (! in_array($account['account_type'], ['revenue', 'expense'], true)) {
                continue;
            }
            $currency = (string) ($account['currency'] ?: Currencies::BASE);
            $bucket = &$byCurrency[$currency];
            $bucket ??= [
                'currency' => $currency,
                'revenue' => '0.0000',
                'expenses' => '0.0000',
                'net_income' => '0.0000',
                'revenue_accounts' => [],
                'expense_accounts' => [],
                'ledger_balanced' => true,
                'unbalanced_entries' => [],
            ];

            $natural = $account['normal_balance'] === 'debit'
                ? bcsub($account['debit_total'], $account['credit_total'], 4)
                : bcsub($account['credit_total'], $account['debit_total'], 4);

            $row = [
                'id' => (int) $account['id'],
                'account_code' => (string) $account['account_code'],
                'name' => (string) $account['name'],
                'normal_balance' => (string) $account['normal_balance'],
                'debit_total' => (string) $account['debit_total'],
                'credit_total' => (string) $account['credit_total'],
                'balance' => $natural,
                'currency' => $currency,
            ];

            if ($account['account_type'] === 'revenue') {
                $bucket['revenue'] = bcadd($bucket['revenue'], $natural, 4);
                $bucket['revenue_accounts'][] = $row;
            } else {
                $bucket['expenses'] = bcadd($bucket['expenses'], $natural, 4);
                $bucket['expense_accounts'][] = $row;
            }
        }
        unset($bucket);

        foreach ($byCurrency as &$bucket) {
            $bucket['net_income'] = bcsub($bucket['revenue'], $bucket['expenses'], 4);
            $bucket['unbalanced_entries'] = ($unbalancedByCurrency[$bucket['currency']] ?? collect())->values()->all();
            $bucket['ledger_balanced'] = count($bucket['unbalanced_entries']) === 0;
        }
        unset($bucket);
        ksort($byCurrency);

        return [
            'statement' => 'income_statement',
            'from' => $from,
            'to' => $to,
            'basis' => 'ledger_entry_lines_period_activity',
            'currency_policy' => 'separate_per_currency_no_conversion',
            'by_currency' => array_values($byCurrency),
            'ledger_balanced' => count($unbalanced) === 0,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * الميزانية كما في التاريخ المحدد. الأرباح غير المقفلة تظهر صراحة داخل
     * حقوق الملكية المعدلة؛ لا نخبئ فرق المعادلة ولا ننشئ قيد إقفال وهمياً.
     */
    public function balanceSheet(?string $asOf = null): array
    {
        $this->assertLedgerAvailable();
        $asOf ??= now()->toDateString();
        $balances = $this->balancesAt($asOf);
        $unbalanced = $this->unbalancedEntries(null, $asOf);
        $unbalancedByCurrency = collect($unbalanced)->groupBy('currency');

        $byCurrency = [];
        foreach ($balances as $account) {
            $currency = (string) ($account['currency'] ?: Currencies::BASE);
            $bucket = &$byCurrency[$currency];
            $bucket ??= [
                'currency' => $currency,
                'assets' => '0.0000',
                'liabilities' => '0.0000',
                'posted_equity' => '0.0000',
                'current_earnings_not_closed' => '0.0000',
                'equity_with_current_earnings' => '0.0000',
                'equation_gap' => '0.0000',
                'balanced' => false,
                'accounts' => ['asset' => [], 'liability' => [], 'equity' => []],
                'unbalanced_entries' => [],
            ];

            $balance = (string) $account['balance'];
            $row = [
                'id' => (int) $account['id'],
                'account_code' => (string) $account['account_code'],
                'name' => (string) $account['name'],
                'normal_balance' => (string) $account['normal_balance'],
                'balance' => $balance,
                'currency' => $currency,
            ];

            switch ($account['account_type']) {
                case 'asset':
                    $bucket['assets'] = bcadd($bucket['assets'], $balance, 4);
                    $bucket['accounts']['asset'][] = $row;
                    break;
                case 'liability':
                    $bucket['liabilities'] = bcadd($bucket['liabilities'], $balance, 4);
                    $bucket['accounts']['liability'][] = $row;
                    break;
                case 'equity':
                    $bucket['posted_equity'] = bcadd($bucket['posted_equity'], $balance, 4);
                    $bucket['accounts']['equity'][] = $row;
                    break;
                case 'revenue':
                    $bucket['current_earnings_not_closed'] = bcadd(
                        $bucket['current_earnings_not_closed'], $balance, 4,
                    );
                    break;
                case 'expense':
                    $bucket['current_earnings_not_closed'] = bcsub(
                        $bucket['current_earnings_not_closed'], $balance, 4,
                    );
                    break;
            }
        }
        unset($bucket);

        foreach ($byCurrency as &$bucket) {
            $bucket['equity_with_current_earnings'] = bcadd(
                $bucket['posted_equity'], $bucket['current_earnings_not_closed'], 4,
            );
            $rhs = bcadd($bucket['liabilities'], $bucket['equity_with_current_earnings'], 4);
            $bucket['equation_gap'] = bcsub($bucket['assets'], $rhs, 4);
            $bucket['unbalanced_entries'] = ($unbalancedByCurrency[$bucket['currency']] ?? collect())->values()->all();
            $bucket['balanced'] = bccomp($bucket['equation_gap'], '0', 4) === 0
                && count($bucket['unbalanced_entries']) === 0;
        }
        unset($bucket);
        ksort($byCurrency);

        return [
            'statement' => 'balance_sheet',
            'as_of' => $asOf,
            'basis' => 'ledger_entry_lines_cumulative',
            'currency_policy' => 'separate_per_currency_no_conversion',
            'by_currency' => array_values($byCurrency),
            'ledger_balanced' => count($unbalanced) === 0,
            'balanced' => collect($byCurrency)->every(fn ($r) => $r['balanced']),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function activity(?string $from, string $to): array
    {
        $q = DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.posted_at', '<=', $to . ' 23:59:59');
        if ($from) {
            $q->where('e.posted_at', '>=', $from . ' 00:00:00');
        }

        return $q->groupBy(
                'a.id', 'a.account_code', 'a.account_type', 'a.name_ar', 'a.normal_balance', 'a.currency'
            )
            ->selectRaw("a.id, a.account_code, a.account_type, a.name_ar, a.normal_balance, a.currency,
                COALESCE(SUM(CASE WHEN l.direction='debit' THEN l.amount ELSE 0 END),0) AS debit_total,
                COALESCE(SUM(CASE WHEN l.direction='credit' THEN l.amount ELSE 0 END),0) AS credit_total")
            ->get()->map(fn ($r) => [
                'id' => (int) $r->id,
                'account_code' => (string) $r->account_code,
                'account_type' => (string) $r->account_type,
                'name' => (string) $r->name_ar,
                'normal_balance' => (string) $r->normal_balance,
                'currency' => (string) ($r->currency ?: Currencies::BASE),
                'debit_total' => (string) $r->debit_total,
                'credit_total' => (string) $r->credit_total,
            ])->all();
    }

    /** @return list<array<string,mixed>> */
    private function balancesAt(string $asOf): array
    {
        return DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.posted_at', '<=', $asOf . ' 23:59:59')
            ->groupBy(
                'a.id', 'a.account_code', 'a.account_type', 'a.name_ar', 'a.normal_balance', 'a.currency'
            )
            ->selectRaw("a.id, a.account_code, a.account_type, a.name_ar, a.normal_balance, a.currency,
                COALESCE(SUM(CASE WHEN l.direction='debit' THEN l.amount ELSE 0 END),0) AS debit_total,
                COALESCE(SUM(CASE WHEN l.direction='credit' THEN l.amount ELSE 0 END),0) AS credit_total")
            ->get()->map(function ($r): array {
                $debit = (string) $r->debit_total;
                $credit = (string) $r->credit_total;
                $balance = $r->normal_balance === 'debit'
                    ? bcsub($debit, $credit, 4)
                    : bcsub($credit, $debit, 4);
                return [
                    'id' => (int) $r->id,
                    'account_code' => (string) $r->account_code,
                    'account_type' => (string) $r->account_type,
                    'name' => (string) $r->name_ar,
                    'normal_balance' => (string) $r->normal_balance,
                    'currency' => (string) ($r->currency ?: Currencies::BASE),
                    'debit_total' => $debit,
                    'credit_total' => $credit,
                    'balance' => $balance,
                ];
            })->all();
    }

    /** @return list<array<string,mixed>> */
    private function unbalancedEntries(?string $from, string $to): array
    {
        $q = DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.posted_at', '<=', $to . ' 23:59:59');
        if ($from) {
            $q->where('e.posted_at', '>=', $from . ' 00:00:00');
        }

        return $q->groupBy('e.id', 'e.entry_ulid', 'e.source_type', 'e.currency', 'e.posted_at')
            ->havingRaw("SUM(CASE WHEN l.direction='debit' THEN l.amount ELSE 0 END)
                <> SUM(CASE WHEN l.direction='credit' THEN l.amount ELSE 0 END)")
            ->selectRaw("e.id, e.entry_ulid, e.source_type, COALESCE(e.currency, ?) AS currency, e.posted_at,
                SUM(CASE WHEN l.direction='debit' THEN l.amount ELSE 0 END) AS debit_total,
                SUM(CASE WHEN l.direction='credit' THEN l.amount ELSE 0 END) AS credit_total", [Currencies::BASE])
            ->limit(100)->get()->map(fn ($r) => [
                'id' => (int) $r->id,
                'entry_ulid' => (string) $r->entry_ulid,
                'source_type' => (string) $r->source_type,
                'currency' => (string) $r->currency,
                'debit' => (string) $r->debit_total,
                'credit' => (string) $r->credit_total,
                'difference' => bcsub((string) $r->debit_total, (string) $r->credit_total, 4),
                'posted_at' => (string) $r->posted_at,
            ])->all();
    }

    private function closingSides(string $normal, string $balance): array
    {
        $negative = bccomp($balance, '0', 4) < 0;
        $absolute = $negative ? bcmul($balance, '-1', 4) : $balance;

        if ($normal === 'debit') {
            return $negative ? ['0.0000', $absolute] : [$absolute, '0.0000'];
        }
        return $negative ? [$absolute, '0.0000'] : ['0.0000', $absolute];
    }

    private function emptyTrialCurrency(string $currency): array
    {
        return [
            'currency' => $currency,
            'period_debit' => '0.0000',
            'period_credit' => '0.0000',
            'period_difference' => '0.0000',
            'closing_debit' => '0.0000',
            'closing_credit' => '0.0000',
            'closing_difference' => '0.0000',
            'balanced' => true,
            'accounts' => [],
            'unbalanced_entries' => [],
        ];
    }

    private function assertLedgerAvailable(): void
    {
        foreach (['ledger_accounts', 'ledger_journal_entries', 'ledger_entry_lines'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Financial data unavailable: missing {$table}");
            }
        }
    }
}
