<?php

use App\Http\Controllers\Admin\ReportingCenterController;
use Illuminate\Support\Facades\Route;

/** AMIAL-REPORTING-CENTER — كل قراءة محمية بصلاحية تقارير مستقلة. */
Route::middleware(['web', 'admin', 'amial.force-pin-change'])
    ->prefix('admin/amial/reporting-center')
    ->name('admin.amial.reporting-center.')
    ->group(function (): void {
        Route::get('/', [ReportingCenterController::class, 'index'])
            ->middleware('platform:platform.reports.view')->name('index');

        foreach ([
            'trial-balance' => 'trialBalance',
            'income-statement' => 'incomeStatement',
            'balance-sheet' => 'balanceSheet',
            'cash-flow' => 'cashFlow',
            'liquidity' => 'liquidity',
            'safeguarded-funds' => 'safeguardedFunds',
            'transaction-volume' => 'transactionVolume',
            'transaction-exceptions' => 'transactionExceptions',
            'general-ledger' => 'generalLedger',
            'fees-commissions' => 'feesCommissions',
            'reconciliation' => 'reconciliation',
            'merchant-portfolio' => 'merchantPortfolio',
            'inventory-control' => 'inventoryControl',
            'credit-control' => 'creditControl',
            'vertical-performance' => 'verticalPerformance',
            'kyc-pipeline' => 'kycPipeline',
            'agent-liquidity' => 'agentLiquidity',
            'audit-sensitive-actions' => 'auditSensitiveActions',
            'rbac-changes' => 'rbacChanges',
            'subscriptions' => 'subscriptions',
            'customer-activity' => 'customerActivity',
            'support-operations' => 'supportOperations',
            'system-health-history' => 'healthHistory',
            'queue-operations' => 'queueOperations',
            'aml-regulatory' => 'amlRegulatory',
        ] as $uri => $method) {
            Route::get('/' . $uri, [ReportingCenterController::class, $method])
                ->middleware('platform:platform.reports.view')->name($uri);
        }
    });
