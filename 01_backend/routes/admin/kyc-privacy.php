<?php

use App\Http\Controllers\Admin\KycForensicController;
use Illuminate\Support\Facades\Route;

Route::prefix('kyc/privacy')
    ->name('kyc.privacy.')
    ->middleware('platform:platform.audit.view')
    ->group(function () {
        Route::get('/', [KycForensicController::class, 'page'])->name('page');
        Route::get('/trace', [KycForensicController::class, 'lookup'])->name('trace');
    });
