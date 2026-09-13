<?php

namespace Tests\Feature;

use App\Services\LedgerReportService;
use App\Services\Reporting\FinancialStatementsService;
use App\Services\Reporting\ReportCatalogService;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

class ReportingCenterGuardTest extends TestCase
{
    /** @test */
    public function reporting_routes_are_permission_guarded(): void
    {
        foreach ([
            'admin.amial.reporting-center.index',
            'admin.amial.reporting-center.trial-balance',
            'admin.amial.reporting-center.income-statement',
            'admin.amial.reporting-center.balance-sheet',
            'admin.amial.reporting-center.reconciliation',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('platform:platform.reports.view', $route->gatherMiddleware(), $name);
        }
    }

    /** @test */
    public function catalog_does_not_claim_missing_p0_reports_are_ready(): void
    {
        $catalog = (new ReportCatalogService())->catalog();
        $financial = collect($catalog['financial_core']['reports'])->keyBy('code');

        $this->assertSame('ready', $financial['trial_balance']['status']);
        $this->assertSame('ready', $financial['income_statement']['status']);
        $this->assertSame('ready', $financial['balance_sheet']['status']);
        $this->assertSame('missing', $financial['cash_flow']['status']);
    }

    /** @test */
    public function income_statement_uses_ledger_balances_and_decimal_math(): void
    {
        $ledger = Mockery::mock(LedgerReportService::class);
        $ledger->shouldReceive('trialBalance')->once()->with('2026-09-01', '2026-09-30')->andReturn([
            'accounts' => [
                $this->account(1, 'REV_FEES', 'إيراد الرسوم', 'revenue', 'credit', '125.5000'),
                $this->account(2, 'EXP_SMS', 'مصروف الرسائل', 'expense', 'debit', '20.1250'),
            ],
            'balanced' => true,
            'unbalanced_entries' => [],
        ]);

        $report = (new FinancialStatementsService($ledger))->incomeStatement('2026-09-01', '2026-09-30');

        $this->assertSame('125.5000', $report['revenue']);
        $this->assertSame('20.1250', $report['expenses']);
        $this->assertSame('105.3750', $report['net_income']);
        $this->assertTrue($report['ledger_balanced']);
    }

    /** @test */
    public function balance_sheet_exposes_current_earnings_instead_of_hiding_the_equation(): void
    {
        $ledger = Mockery::mock(LedgerReportService::class);
        $ledger->shouldReceive('trialBalance')->once()->with(null, '2026-09-30')->andReturn([
            'accounts' => [
                $this->account(1, 'CASH', 'النقد', 'asset', 'debit', '150.0000'),
                $this->account(2, 'PAYABLE', 'التزامات', 'liability', 'credit', '60.0000'),
                $this->account(3, 'CAPITAL', 'رأس المال', 'equity', 'credit', '50.0000'),
                $this->account(4, 'REV', 'إيراد', 'revenue', 'credit', '45.0000'),
                $this->account(5, 'EXP', 'مصروف', 'expense', 'debit', '5.0000'),
            ],
            'balanced' => true,
            'unbalanced_entries' => [],
        ]);

        $report = (new FinancialStatementsService($ledger))->balanceSheet('2026-09-30');

        $this->assertSame('40.0000', $report['current_earnings_not_closed']);
        $this->assertSame('90.0000', $report['equity_with_current_earnings']);
        $this->assertSame('0.0000', $report['equation_gap']);
        $this->assertTrue($report['balanced']);
    }

    private function account(
        int $id,
        string $code,
        string $name,
        string $type,
        string $normal,
        string $balance,
    ): array {
        return [
            'id' => $id,
            'account_code' => $code,
            'name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'debit_total' => $normal === 'debit' ? $balance : '0.0000',
            'credit_total' => $normal === 'credit' ? $balance : '0.0000',
            'computed_balance' => $balance,
            'stored_balance' => $balance,
            'drift' => '0.0000',
            'has_drift' => false,
        ];
    }
}
