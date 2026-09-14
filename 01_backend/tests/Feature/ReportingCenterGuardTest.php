<?php

namespace Tests\Feature;

use App\Models\Ledger\LedgerAccount;
use App\Services\LedgerService;
use App\Services\Reporting\FinancialStatementsService;
use App\Services\Reporting\ReportCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ReportingCenterGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function reporting_routes_are_permission_guarded(): void
    {
        foreach ([
            'admin.amial.reporting-center.index',
            'admin.amial.reporting-center.trial-balance',
            'admin.amial.reporting-center.income-statement',
            'admin.amial.reporting-center.balance-sheet',
            'admin.amial.reporting-center.cash-flow',
            'admin.amial.reporting-center.liquidity',
            'admin.amial.reporting-center.safeguarded-funds',
            'admin.amial.reporting-center.transaction-volume',
            'admin.amial.reporting-center.transaction-exceptions',
            'admin.amial.reporting-center.general-ledger',
            'admin.amial.reporting-center.fees-commissions',
            'admin.amial.reporting-center.reconciliation',
            'admin.amial.reporting-center.merchant-portfolio',
            'admin.amial.reporting-center.inventory-control',
            'admin.amial.reporting-center.credit-control',
            'admin.amial.reporting-center.vertical-performance',
            'admin.amial.reporting-center.kyc-pipeline',
            'admin.amial.reporting-center.agent-liquidity',
            'admin.amial.reporting-center.audit-sensitive-actions',
            'admin.amial.reporting-center.rbac-changes',
            'admin.amial.reporting-center.subscriptions',
            'admin.amial.reporting-center.customer-activity',
            'admin.amial.reporting-center.support-operations',
            'admin.amial.reporting-center.system-health-history',
            'admin.amial.reporting-center.queue-operations',
            'admin.amial.reporting-center.email-otp',
            'admin.amial.reporting-center.auth-security',
            'admin.amial.reporting-center.aml-regulatory',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $this->assertContains('platform:platform.reports.view', $route->gatherMiddleware(), $name);
        }
    }

    /** @test */
    public function catalog_marks_implemented_reports_ready_without_claiming_everything_is_complete(): void
    {
        $catalog = (new ReportCatalogService())->catalog();
        $financial = collect($catalog['financial_core']['reports'])->keyBy('code');
        $treasury = collect($catalog['reconciliation_treasury']['reports'])->keyBy('code');
        $transactions = collect($catalog['transactions']['reports'])->keyBy('code');
        $merchants = collect($catalog['merchant']['reports'])->keyBy('code');
        $agents = collect($catalog['customers_agents']['reports'])->keyBy('code');
        $risk = collect($catalog['risk_compliance']['reports'])->keyBy('code');
        $operations = collect($catalog['operations']['reports'])->keyBy('code');

        foreach (['trial_balance', 'income_statement', 'balance_sheet', 'cash_flow', 'general_ledger'] as $code) {
            $this->assertSame('ready', $financial[$code]['status'], $code);
        }
        foreach (['liquidity_position', 'safeguarded_funds'] as $code) {
            $this->assertSame('ready', $treasury[$code]['status'], $code);
        }
        foreach (['transaction_volume', 'failed_reversed_pending', 'fees_commissions'] as $code) {
            $this->assertSame('ready', $transactions[$code]['status'], $code);
        }
        foreach (['merchant_portfolio', 'credit_aging'] as $code) {
            $this->assertSame('ready', $merchants[$code]['status'], $code);
        }
        foreach (['customer_activity', 'agent_float'] as $code) {
            $this->assertSame('ready', $agents[$code]['status'], $code);
        }
        foreach (['kyc_pipeline', 'audit_sensitive_actions', 'rbac_changes'] as $code) {
            $this->assertSame('ready', $risk[$code]['status'], $code);
        }
        foreach (['subscriptions', 'system_health_history', 'jobs_queues', 'email_otp', 'auth_security'] as $code) {
            $this->assertSame('ready', $operations[$code]['status'], $code);
        }

        // لا نعلن ما لم يكتمل: التقييم المالي للمخزون ما زال يحتاج سياسة
        // تكلفة/عملة صريحة، وAML يحتاج PEP/watchlist، والدعم يحتاج هدف SLA رسمي.
        $this->assertSame('partial', $merchants['inventory_valuation']['status']);
        $this->assertSame('partial', $risk['aml_regulatory']['status']);
        $this->assertSame('partial', $operations['support_sla']['status']);
    }

    /** @test */
    public function statements_never_merge_two_currencies_and_keep_decimal_precision(): void
    {
        $this->account('CASH_YER', 'asset', 'debit', 'YER');
        $this->account('CUSTOMER_PAYABLE_YER', 'liability', 'credit', 'YER');
        $this->account('REV_FEES_YER', 'revenue', 'credit', 'YER');
        $this->account('EXP_SMS_YER', 'expense', 'debit', 'YER');
        $this->account('CASH_USD', 'asset', 'debit', 'USD');
        $this->account('CUSTOMER_PAYABLE_USD', 'liability', 'credit', 'USD');

        $ledger = app(LedgerService::class);
        $ledger->post('seed_yer', '1', 'تمويل ريال', [
            ['account' => 'CASH_YER', 'direction' => 'debit', 'amount' => '100.0000'],
            ['account' => 'CUSTOMER_PAYABLE_YER', 'direction' => 'credit', 'amount' => '100.0000'],
        ]);
        $ledger->post('fee_yer', '2', 'إيراد رسوم', [
            ['account' => 'CASH_YER', 'direction' => 'debit', 'amount' => '125.5000'],
            ['account' => 'REV_FEES_YER', 'direction' => 'credit', 'amount' => '125.5000'],
        ]);
        $ledger->post('expense_yer', '3', 'مصروف رسائل', [
            ['account' => 'EXP_SMS_YER', 'direction' => 'debit', 'amount' => '20.1250'],
            ['account' => 'CASH_YER', 'direction' => 'credit', 'amount' => '20.1250'],
        ]);
        $ledger->post('seed_usd', '4', 'تمويل دولار', [
            ['account' => 'CASH_USD', 'direction' => 'debit', 'amount' => '5.7500'],
            ['account' => 'CUSTOMER_PAYABLE_USD', 'direction' => 'credit', 'amount' => '5.7500'],
        ]);

        $service = app(FinancialStatementsService::class);
        $trial = $service->trialBalance(now()->toDateString(), now()->toDateString());
        $trialByCurrency = collect($trial['by_currency'])->keyBy('currency');

        $this->assertCount(2, $trialByCurrency);
        $this->assertTrue($trialByCurrency['YER']['balanced']);
        $this->assertTrue($trialByCurrency['USD']['balanced']);
        $this->assertSame('245.6250', $trialByCurrency['YER']['period_debit']);
        $this->assertSame('245.6250', $trialByCurrency['YER']['period_credit']);
        $this->assertSame('5.7500', $trialByCurrency['USD']['period_debit']);
        $this->assertSame('5.7500', $trialByCurrency['USD']['period_credit']);

        $income = $service->incomeStatement(now()->toDateString(), now()->toDateString());
        $incomeYER = collect($income['by_currency'])->firstWhere('currency', 'YER');
        $this->assertSame('125.5000', $incomeYER['revenue']);
        $this->assertSame('20.1250', $incomeYER['expenses']);
        $this->assertSame('105.3750', $incomeYER['net_income']);

        $balance = $service->balanceSheet(now()->toDateString());
        $balanceByCurrency = collect($balance['by_currency'])->keyBy('currency');
        $this->assertSame('0.0000', $balanceByCurrency['YER']['equation_gap']);
        $this->assertSame('0.0000', $balanceByCurrency['USD']['equation_gap']);
        $this->assertTrue($balanceByCurrency['YER']['balanced']);
        $this->assertTrue($balanceByCurrency['USD']['balanced']);
    }

    private function account(string $code, string $type, string $normal, string $currency): void
    {
        LedgerAccount::create([
            'account_code' => $code,
            'account_type' => $type,
            'name_ar' => $code,
            'owner_user_id' => null,
            'owner_type' => 'system',
            'normal_balance' => $normal,
            'current_balance' => '0.0000',
            'currency' => $currency,
            'zone_code' => 'SOUTH',
            'is_active' => 1,
        ]);
    }
}
