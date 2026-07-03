<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InstallController;

Route::get('/', [InstallController::class, 'step0'])->name('step0');
Route::get('/step1', [InstallController::class, 'step1'])->name('step1');
Route::get('/step2', [InstallController::class, 'step2'])->name('step2');
Route::get('/step3/{error?}', [InstallController::class, 'step3'])->name('step3')->middleware('installation-check');
Route::get('/step4', [InstallController::class, 'step4'])->name('step4')->middleware('installation-check');
Route::get('/step5', [InstallController::class, 'step5'])->name('step5')->middleware('installation-check');

Route::post('/database_installation', [InstallController::class, 'databaseInstallation'])
    ->name('install.db')
    ->middleware('installation-check');

Route::get('import_sql', [InstallController::class, 'importSql'])
    ->name('import_sql')
    ->middleware('installation-check');

Route::get('force-import-sql', [InstallController::class, 'forceImportSql'])
    ->name('force-import-sql')
    ->middleware('installation-check');

Route::post('system_settings', [InstallController::class, 'systemSettings'])->name('system_settings');
Route::post('purchase_code', [InstallController::class, 'purchaseCode'])->name('purchase.code');

Route::fallback(function () {
    return redirect('/');
});
