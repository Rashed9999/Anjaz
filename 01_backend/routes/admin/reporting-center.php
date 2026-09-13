<?php

use App\Http\Controllers\Admin\ReportingCenterController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-REPORTING-CENTER-001 — مسارات التقارير المؤسسية.
 *
 * القراءة خلف صلاحية مستقلة. التصدير سيبقى بصلاحية أخرى عند ربط XLSX/PDF
 * حتى لا يساوي مجرد مشاهدة الشاشة إخراج بيانات مالية خارج النظام.
 */
Route::middleware(['web', 'admin', 'amial.force-pin-change'])
    ->prefix('admin/amial/reporting-center')
    ->name('admin.amial.reporting-center.')
    ->group(function (): void {
        Route::get('/', [ReportingCenterController::class, 'index'])
            ->middleware('platform:platform.reports.view')
            ->name('index');

        Route::get('/trial-balance', [ReportingCenterController::class, 'trialBalance'])
            ->middleware('platform:platform.reports.view')
            ->name('trial-balance');

        Route::get('/income-statement', [ReportingCenterController::class, 'incomeStatement'])
            ->middleware('platform:platform.reports.view')
            ->name('income-statement');

        Route::get('/balance-sheet', [ReportingCenterController::class, 'balanceSheet'])
            ->middleware('platform:platform.reports.view')
            ->name('balance-sheet');

        Route::get('/reconciliation', [ReportingCenterController::class, 'reconciliation'])
            ->middleware('platform:platform.reports.view')
            ->name('reconciliation');
    });
