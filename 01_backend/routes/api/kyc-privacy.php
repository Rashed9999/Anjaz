<?php

use App\Http\Controllers\Api\V1\Amial\KycPrivacyController;
use App\Http\Controllers\Api\V1\Amial\KycResidenceController;
use Illuminate\Support\Facades\Route;

Route::prefix('me/kyc/privacy')->name('amial.me.kyc.privacy.')->group(function () {
    Route::get('/', [KycPrivacyController::class, 'show'])->name('show');
    Route::post('/', [KycPrivacyController::class, 'update'])
        ->middleware('amial.rate-limit:kyc_privacy_update,10,10')
        ->name('update');

    Route::post('/biometric/start', [KycPrivacyController::class, 'startBiometric'])
        ->middleware('amial.rate-limit:kyc_biometric_start,3,10')
        ->name('biometric.start');
});

// AMIAL-RESIDENCE-API-001 — الإقامة الموثقة هي مفتاح النطاق التشغيلي.
Route::prefix('me/kyc/residence')->name('amial.me.kyc.residence.')->group(function () {
    Route::get('/', [KycResidenceController::class, 'show'])->name('show');
    Route::post('/', [KycResidenceController::class, 'submit'])
        ->middleware('amial.rate-limit:kyc_residence_submit,5,60')
        ->name('submit');
});
