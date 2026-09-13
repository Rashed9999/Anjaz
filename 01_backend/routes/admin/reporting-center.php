<?php

use App\Http\Controllers\Admin\ReportingCenterController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-REPORTING-CENTER-001/002 — مسارات التقارير المؤسسية.
 *
 * القراءة خلف صلاحية مستقلة. التصدير يبقى بصلاحية أخرى حتى لا يساوي
 * مجرد مشاهدة الشاشة إخراج بيانات مالية أو رقابية خارج النظام.
 */
Route::middleware(['web', 'admin', 'amial.force-pin-change'])
    ->prefix('admin/amial/reporting-center')
    ->name('admin.amial.reporting-center.')
    ->group(function (): void {
        Route::get('/', [ReportingCenterController::class, 'index'])
            ->middleware('platform:platform.reports.view')
            ->name('index');

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
            'kyc-pipeline' => 'kycPipeline',
            'agent-liquidity' => 'agentLiquidity',
            'audit-sensitive-actions' => 'auditSensitiveActions',
            'rbac-changes' => 'rbacChanges',
        ] as $uri => $method) {
            Route::get('/' . $uri, [ReportingCenterController::class, $method])
                ->middleware('platform:platform.reports.view')
                ->name($uri);
        }
    });
