<?php

use App\Http\Controllers\Admin\ReportingCenterController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-REPORTING-CENTER-001 — مسارات التقارير المؤسسية.
 *
 * القراءة خلف صلاحية مستقلة. التصدير يبقى بصلاحية أخرى حتى لا يساوي
 * مجرد مشاهدة الشاشة إخراج بيانات مالية خارج النظام.
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
            'reconciliation' => 'reconciliation',
        ] as $uri => $method) {
            Route::get('/' . $uri, [ReportingCenterController::class, $method])
                ->middleware('platform:platform.reports.view')
                ->name($uri);
        }
    });
