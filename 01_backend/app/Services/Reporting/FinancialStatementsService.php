<?php

namespace App\Services\Reporting;

use App\Services\LedgerReportService;

/**
 * AMIAL-REPORTING-CENTER-001 — القوائم المالية من الدفتر نفسه.
 *
 * لا يوجد هنا جدول مالي موازٍ ولا إعادة تفسير لحركات المحفظة. كل رقم يبدأ
 * من LedgerReportService الذي يجمع سطور القيود المرحّلة posted. لذلك تبقى
 * لوحة التقارير قارئاً للحقيقة المالية لا مصدراً جديداً لها.
 */
class FinancialStatementsService
{
    public function __construct(private readonly LedgerReportService $ledger)
    {
    }

    public function incomeStatement(?string $from = null, ?string $to = null): array
    {
        $trial = $this->ledger->trialBalance($from, $to);
        $revenue = '0';
        $expenses = '0';
        $revenueAccounts = [];
        $expenseAccounts = [];

        foreach ($trial['accounts'] as $account) {
            if ($account['account_type'] === 'revenue') {
                $revenue = bcadd($revenue, (string) $account['computed_balance'], 4);
                $revenueAccounts[] = $this->accountRow($account);
            } elseif ($account['account_type'] === 'expense') {
                $expenses = bcadd($expenses, (string) $account['computed_balance'], 4);
                $expenseAccounts[] = $this->accountRow($account);
            }
        }

        return [
            'statement' => 'income_statement',
            'from' => $from,
            'to' => $to,
            'basis' => 'posted_ledger_entry_lines',
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_income' => bcsub($revenue, $expenses, 4),
            'revenue_accounts' => $revenueAccounts,
            'expense_accounts' => $expenseAccounts,
            'ledger_balanced' => (bool) $trial['balanced'],
            'unbalanced_entries' => $trial['unbalanced_entries'],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * ميزانية كما في التاريخ المحدد. لأن حسابات الإيراد والمصروف قد لا تكون
     * أُقفلت بعد، نعرض أرباح الفترة الجارية صراحةً ضمن حقوق الملكية المعدّلة
     * بدلاً من إخفاء فرق المعادلة.
     */
    public function balanceSheet(?string $asOf = null): array
    {
        $trial = $this->ledger->trialBalance(null, $asOf);
        $assets = '0';
        $liabilities = '0';
        $equity = '0';
        $revenue = '0';
        $expenses = '0';
        $groups = [
            'asset' => [],
            'liability' => [],
            'equity' => [],
        ];

        foreach ($trial['accounts'] as $account) {
            $balance = (string) $account['computed_balance'];
            switch ($account['account_type']) {
                case 'asset':
                    $assets = bcadd($assets, $balance, 4);
                    $groups['asset'][] = $this->accountRow($account);
                    break;
                case 'liability':
                    $liabilities = bcadd($liabilities, $balance, 4);
                    $groups['liability'][] = $this->accountRow($account);
                    break;
                case 'equity':
                    $equity = bcadd($equity, $balance, 4);
                    $groups['equity'][] = $this->accountRow($account);
                    break;
                case 'revenue':
                    $revenue = bcadd($revenue, $balance, 4);
                    break;
                case 'expense':
                    $expenses = bcadd($expenses, $balance, 4);
                    break;
            }
        }

        $currentEarnings = bcsub($revenue, $expenses, 4);
        $equityWithCurrentEarnings = bcadd($equity, $currentEarnings, 4);
        $rhs = bcadd($liabilities, $equityWithCurrentEarnings, 4);
        $equationGap = bcsub($assets, $rhs, 4);

        return [
            'statement' => 'balance_sheet',
            'as_of' => $asOf,
            'basis' => 'posted_ledger_entry_lines',
            'assets' => $assets,
            'liabilities' => $liabilities,
            'posted_equity' => $equity,
            'current_earnings_not_closed' => $currentEarnings,
            'equity_with_current_earnings' => $equityWithCurrentEarnings,
            'equation_gap' => $equationGap,
            'balanced' => bccomp($equationGap, '0', 4) === 0 && (bool) $trial['balanced'],
            'accounts' => $groups,
            'ledger_balanced' => (bool) $trial['balanced'],
            'unbalanced_entries' => $trial['unbalanced_entries'],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function accountRow(array $account): array
    {
        return [
            'id' => (int) $account['id'],
            'account_code' => (string) $account['account_code'],
            'name' => (string) $account['name'],
            'normal_balance' => (string) $account['normal_balance'],
            'debit_total' => (string) $account['debit_total'],
            'credit_total' => (string) $account['credit_total'],
            'balance' => (string) $account['computed_balance'],
            'has_drift' => (bool) $account['has_drift'],
        ];
    }
}
