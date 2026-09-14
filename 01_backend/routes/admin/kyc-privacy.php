<?php

use App\Http\Controllers\Admin\KycForensicController;
use App\Http\Controllers\Admin\KycPrivacyAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('kyc/privacy')
    ->name('kyc.privacy.')
    ->middleware('platform:platform.audit.view')
    ->group(function () {
        Route::get('/', [KycForensicController::class, 'page'])->name('page');
        Route::get('/trace', [KycForensicController::class, 'lookup'])->name('trace');
        Route::get('/cases', [KycPrivacyAdminController::class, 'cases'])->name('cases');
        Route::get('/cases/{userId}', [KycPrivacyAdminController::class, 'show'])
            ->where('userId', '[0-9]+')->name('cases.show');
    });
