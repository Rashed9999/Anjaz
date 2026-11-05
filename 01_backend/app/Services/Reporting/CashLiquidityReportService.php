<?php

namespace App\Services\Reporting;

use App\Support\Money\Currencies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * AMIAL-REPORTING-CENTER-002 — السيولة والغطاء والتدفق النقدي من الدفتر.
 *
 * لا يقرأ هذا التقرير أرصدة الواجهات ولا current_balance المخزّن كمصدر
 * للحقيقة. كل رصيد تاريخي مشتق من سطور القيود، وكل عملة تبقى مستقلة.
 * لا يوجد جمع ريال + دولار ولا تحويل صامت.
 */
class CashLiquidityReportService
{
    /**
     * مركز السيولة كما في تاريخ معيّن.
     *
     * @return array<string,mixed>
     */
    public function liquidityPosition(?string $asOf = null): array
    {
        $this->assertLedgerAvailable();
        $asOf ??= now()->toDateString();
        $rows = $this->balancesAt($asOf);

        $ownerIds = collect($rows)->pluck('owner_user_id')->filter()->unique()->values()->all();
        $ownerTypes = $ownerIds === []
            ? []
            : DB::table('users')->whereIn('id', $ownerIds)->pluck('type', 'id')->all();

        $byCurrency = [];
        foreach ($rows as $row) {
            $currency = (string) ($row['currency'] ?: Currencies::BASE);
            $bucket = &$byCurrency[$currency];
            $bucket ??= $this->emptyLiquidityCurrency($currency);
            $balance = (string) $row['balance'];

            if ($this->isCashEquivalent($row)) {
                $bucket['liquid_assets'] = bcadd($bucket['liquid_assets'], $balance, 4);
                $bucket['liquid_accounts'][] = $this->accountEvidence($row);
            }

            if ($row['account_type'] === 'liability') {
                $bucket['total_liabilities'] = bcadd($bucket['total_liabilities'], $balance, 4);

                if (str_starts_with((string) $row['account_code'], 'USER_WALLET_')) {
                    $userType = isset($row['owner_user_id'])
                        ? (int) ($ownerTypes[(int) $row['owner_user_id']] ?? -1)
                        : -1;
                    $key = match ($userType) {
                        1 => 'agent_wallets',
                        2 => 'customer_wallets',
                        3 => 'merchant_wallets',
                        0 => 'internal_wallets',
                        default => 'other_wallets',
                    };
                    $bucket[$key] = bcadd($bucket[$key], $balance, 4);
                } else {
                    $bucket['other_liabilities'] = bcadd($bucket['other_liabilities'], $balance, 4);
                }
            }
        }
        unset($bucket);

        foreach ($byCurrency as &$bucket) {
            $bucket['external_wallet_obligations'] = bcadd(
                bcadd($bucket['customer_wallets'], $bucket['merchant_wallets'], 4),
                $bucket['agent_wallets'],
                4,
            );
            $bucket['liquid_surplus_after_customer_funds'] = bcsub(
                $bucket['liquid_assets'], $bucket['customer_wallets'], 4,
            );
            $bucket['liquid_surplus_after_external_wallets'] = bcsub(
                $bucket['liquid_assets'], $bucket['external_wallet_obligations'], 4,
            );
            $bucket['liquid_surplus_after_all_liabilities'] = bcsub(
                $bucket['liquid_assets'], $bucket['total_liabilities'], 4,
            );
            $bucket['customer_coverage_ratio'] = $this->ratio(
                $bucket['liquid_assets'], $bucket['customer_wallets'],
            );
            $bucket['external_wallet_coverage_ratio'] = $this->ratio(
                $bucket['liquid_assets'], $bucket['external_wallet_obligations'],
            );
            $bucket['customer_funds_covered'] = bccomp(
                $bucket['liquid_assets'], $bucket['customer_wallets'], 4,
            ) >= 0;
            $bucket['external_wallets_covered'] = bccomp(
                $bucket['liquid_assets'], $bucket['external_wallet_obligations'], 4,
            ) >= 0;
        }
        unset($bucket);

        ksort($byCurrency);

        return [
            'report' => 'liquidity_position',
            'as_of' => $asOf,
            'basis' => 'ledger_entry_lines',
            'currency_policy' => 'no_silent_conversion',
            'cash_equivalent_rule' => 'asset accounts with TREASURY/BANK/CASH/RESERVE codes, excluding suspense/hold/escrow/pending/fx-position',
            'by_currency' => array_values($byCurrency),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * تقرير الغطاء: أموال العملاء مقابل الأصول السائلة الموثقة.
     * لا يخلط التزامات التجار والوكلاء بأموال العملاء، لكنه يعرضها أيضاً
     * حتى لا تبدو نسبة غطاء العميل وكأنها تغطي كل التزامات المنصة.
     */
    public function safeguardedFunds(?string $asOf = null): array
    {
        $liquidity = $this->liquidityPosition($asOf);
        $rows = [];

        foreach ($liquidity['by_currency'] as $currency) {
            $rows[] = [
                'currency' => $currency['currency'],
                'customer_funds' => $currency['customer_wallets'],
                'merchant_funds' => $currency['merchant_wallets'],
                'agent_funds' => $currency['agent_wallets'],
                'liquid_cover' => $currency['liquid_assets'],
                'customer_surplus_or_shortfall' => $currency['liquid_surplus_after_customer_funds'],
                'external_wallet_surplus_or_shortfall' => $currency['liquid_surplus_after_external_wallets'],
                'customer_coverage_ratio' => $currency['customer_coverage_ratio'],
                'external_wallet_coverage_ratio' => $currency['external_wallet_coverage_ratio'],
                'customer_status' => $currency['customer_funds_covered'] ? 'covered' : 'shortfall',
                'external_wallet_status' => $currency['external_wallets_covered'] ? 'covered' : 'shortfall',
                'cover_accounts' => $currency['liquid_accounts'],
            ];
        }

        return [
            'report' => 'safeguarded_funds',
            'as_of' => $liquidity['as_of'],
            'basis' => $liquidity['basis'],
            'currency_policy' => $liquidity['currency_policy'],
            'by_currency' => $rows,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * قائمة التدفق النقدي على أساس الحركة الفعلية للحسابات السائلة.
     * المصدر غير المصنف لا يُدفع قسراً إلى التشغيل؛ يبقى ظاهراً حتى تُضاف
     * له قاعدة تصنيف موثقة، وبذلك لا ننتج قائمة نقدية جميلة لكنها خاطئة.
     */
    public function cashFlow(?string $from = null, ?string $to = null): array
    {
        $this->assertLedgerAvailable();
        $from ??= now()->startOfMonth()->toDateString();
        $to ??= now()->toDateString();

        $openingAsOf = Carbon::parse($from)->subDay()->toDateString();
        $opening = $this->cashBalancesByCurrency($openingAsOf);
        $closing = $this->cashBalancesByCurrency($to);

        $lines = DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('e.status', ['posted', 'reversed'])
            ->whereBetween('e.posted_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->select([
                'e.id as entry_id', 'e.entry_ulid', 'e.source_type', 'e.currency as entry_currency',
                'e.is_reversal', 'e.posted_at', 'a.account_code', 'a.account_type',
                'a.currency as account_currency', 'l.direction', 'l.amount',
            ])->orderBy('e.posted_at')->orderBy('e.id')->get();

        $entries = [];
        foreach ($lines as $line) {
            $account = [
                'account_code' => (string) $line->account_code,
                'account_type' => (string) $line->account_type,
            ];
            if (! $this->isCashEquivalent($account)) {
                continue;
            }

            $currency = (string) ($line->account_currency ?: $line->entry_currency ?: Currencies::BASE);
            $key = (string) $line->entry_id;
            $entries[$key] ??= [
                'entry_ulid' => (string) $line->entry_ulid,
                'source_type' => (string) $line->source_type,
                'currency' => $currency,
                'is_reversal' => (bool) $line->is_reversal,
                'posted_at' => (string) $line->posted_at,
                'impact' => '0.0000',
            ];
            $impact = $line->direction === 'debit' ? (string) $line->amount : bcmul((string) $line->amount, '-1', 4);
            $entries[$key]['impact'] = bcadd($entries[$key]['impact'], $impact, 4);
        }

        $byCurrency = [];
        foreach ($entries as $entry) {
            $currency = $entry['currency'];
            $bucket = &$byCurrency[$currency];
            $bucket ??= [
                'currency' => $currency,
                'opening_cash' => $opening[$currency] ?? '0.0000',
                'operating' => '0.0000',
                'investing' => '0.0000',
                'financing' => '0.0000',
                'unclassified' => '0.0000',
                'internal_cash_transfers' => 0,
                'classified_entries' => 0,
                'unclassified_entries' => 0,
                'movements' => [],
            ];

            if (bccomp($entry['impact'], '0', 4) === 0) {
                $bucket['internal_cash_transfers']++;
                continue;
            }

            $category = $this->cashFlowCategory($entry['source_type']);
            $bucket[$category] = bcadd($bucket[$category], $entry['impact'], 4);
            if ($category === 'unclassified') {
                $bucket['unclassified_entries']++;
            } else {
                $bucket['classified_entries']++;
            }
            $bucket['movements'][] = [
                'entry_ulid' => $entry['entry_ulid'],
                'source_type' => $entry['source_type'],
                'category' => $category,
                'impact' => $entry['impact'],
                'is_reversal' => $entry['is_reversal'],
                'posted_at' => $entry['posted_at'],
            ];
        }
        unset($bucket);

        foreach (array_unique(array_merge(array_keys($opening), array_keys($closing), array_keys($byCurrency))) as $currency) {
            $byCurrency[$currency] ??= [
                'currency' => $currency,
                'opening_cash' => $opening[$currency] ?? '0.0000',
                'operating' => '0.0000', 'investing' => '0.0000', 'financing' => '0.0000',
                'unclassified' => '0.0000', 'internal_cash_transfers' => 0,
                'classified_entries' => 0, 'unclassified_entries' => 0, 'movements' => [],
            ];
            $bucket = &$byCurrency[$currency];
            $bucket['opening_cash'] = $opening[$currency] ?? '0.0000';
            $bucket['closing_cash'] = $closing[$currency] ?? '0.0000';
            $bucket['net_change'] = bcadd(
                bcadd($bucket['operating'], $bucket['investing'], 4),
                bcadd($bucket['financing'], $bucket['unclassified'], 4),
                4,
            );
            $bucket['calculated_closing_cash'] = bcadd($bucket['opening_cash'], $bucket['net_change'], 4);
            $bucket['closing_difference'] = bcsub($bucket['closing_cash'], $bucket['calculated_closing_cash'], 4);
            $totalEntries = $bucket['classified_entries'] + $bucket['unclassified_entries'];
            $bucket['classification_coverage_pct'] = $totalEntries > 0
                ? round(($bucket['classified_entries'] / $totalEntries) * 100, 1)
                : 100.0;
            $bucket['reconciled_to_cash_accounts'] = bccomp($bucket['closing_difference'], '0', 4) === 0;
            unset($bucket['movements']); // التفاصيل الكاملة تُعرض عبر drill-down للدفتر؛ لا نضخم الـAPI.
        }
        unset($bucket);

        ksort($byCurrency);

        return [
            'report' => 'cash_flow',
            'from' => $from,
            'to' => $to,
            'basis' => 'cash-equivalent ledger entry lines',
            'currency_policy' => 'separate_per_currency_no_conversion',
            'classification_policy' => 'explicit_patterns_with_unclassified_bucket',
            'by_currency' => array_values($byCurrency),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** @return array<string,string> */
    private function cashBalancesByCurrency(string $asOf): array
    {
        $result = [];
        foreach ($this->balancesAt($asOf) as $row) {
            if (! $this->isCashEquivalent($row)) {
                continue;
            }
            $currency = (string) ($row['currency'] ?: Currencies::BASE);
            $result[$currency] = bcadd($result[$currency] ?? '0.0000', (string) $row['balance'], 4);
        }
        return $result;
    }

    /**
     * الرصيد التاريخي من السطور لا من current_balance.
     *
     * @return list<array<string,mixed>>
     */
    private function balancesAt(string $asOf): array
    {
        $rows = DB::table('ledger_entry_lines as l')
            ->join('ledger_journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('e.status', ['posted', 'reversed'])
            ->where('e.posted_at', '<=', $asOf . ' 23:59:59')
            ->groupBy(
                'a.id', 'a.account_code', 'a.account_type', 'a.name_ar', 'a.normal_balance',
                'a.currency', 'a.owner_user_id', 'a.owner_type'
            )
            ->selectRaw("a.id, a.account_code, a.account_type, a.name_ar, a.normal_balance,
                a.currency, a.owner_user_id, a.owner_type,
                COALESCE(SUM(CASE WHEN l.direction='debit' THEN l.amount ELSE 0 END),0) AS debit_total,
                COALESCE(SUM(CASE WHEN l.direction='credit' THEN l.amount ELSE 0 END),0) AS credit_total")
            ->get();

        return $rows->map(function ($row): array {
            $debit = (string) $row->debit_total;
            $credit = (string) $row->credit_total;
            $balance = $row->normal_balance === 'debit'
                ? bcsub($debit, $credit, 4)
                : bcsub($credit, $debit, 4);
            return [
                'id' => (int) $row->id,
                'account_code' => (string) $row->account_code,
                'account_type' => (string) $row->account_type,
                'name' => (string) $row->name_ar,
                'normal_balance' => (string) $row->normal_balance,
                'currency' => (string) ($row->currency ?: Currencies::BASE),
                'owner_user_id' => $row->owner_user_id === null ? null : (int) $row->owner_user_id,
                'owner_type' => $row->owner_type,
                'debit_total' => $debit,
                'credit_total' => $credit,
                'balance' => $balance,
            ];
        })->all();
    }

    private function isCashEquivalent(array $account): bool
    {
        if (($account['account_type'] ?? null) !== 'asset') {
            return false;
        }
        $code = strtoupper((string) ($account['account_code'] ?? ''));
        foreach (['SUSPENSE', 'HOLD', 'ESCROW', 'PENDING', 'FX_POSITION'] as $forbidden) {
            if (str_contains($code, $forbidden)) {
                return false;
            }
        }
        foreach (['TREASURY', 'BANK', 'CASH', 'RESERVE'] as $allowed) {
            if (str_contains($code, $allowed)) {
                return true;
            }
        }
        return false;
    }

    private function cashFlowCategory(string $sourceType): string
    {
        $source = strtolower(preg_replace('/_reversal$/', '', trim($sourceType)) ?? '');

        foreach (['capital', 'treasury_issuance', 'float_issue', 'funding', 'issuance'] as $needle) {
            if (str_contains($source, $needle)) {
                return 'financing';
            }
        }
        foreach (['asset_purchase', 'asset_sale', 'investment', 'fixed_asset'] as $needle) {
            if (str_contains($source, $needle)) {
                return 'investing';
            }
        }
        foreach ([
            'send_money', 'cash_in', 'cash_out', 'payment', 'merchant', 'refund', 'fee',
            'commission', 'settlement', 'withdraw', 'deposit', 'debt', 'invoice', 'pos',
            'expense', 'revenue', 'donation', 'charity', 'opening_balance', 'external_adjustment',
        ] as $needle) {
            if (str_contains($source, $needle)) {
                return 'operating';
            }
        }
        return 'unclassified';
    }

    private function emptyLiquidityCurrency(string $currency): array
    {
        return [
            'currency' => $currency,
            'liquid_assets' => '0.0000',
            'customer_wallets' => '0.0000',
            'merchant_wallets' => '0.0000',
            'agent_wallets' => '0.0000',
            'internal_wallets' => '0.0000',
            'other_wallets' => '0.0000',
            'other_liabilities' => '0.0000',
            'total_liabilities' => '0.0000',
            'liquid_accounts' => [],
        ];
    }

    private function accountEvidence(array $row): array
    {
        return [
            'account_id' => (int) $row['id'],
            'account_code' => (string) $row['account_code'],
            'name' => (string) $row['name'],
            'balance' => (string) $row['balance'],
            'currency' => (string) $row['currency'],
        ];
    }

    private function ratio(string $numerator, string $denominator): ?string
    {
        if (bccomp($denominator, '0', 4) === 0) {
            return null;
        }
        return bcmul(bcdiv($numerator, $denominator, 8), '100', 2);
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
