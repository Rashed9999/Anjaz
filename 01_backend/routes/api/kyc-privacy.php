<?php

use App\Http\Controllers\Api\V1\Amial\KycPrivacyController;
use App\Http\Controllers\Api\V1\Amial\KycResidenceController;
use App\Http\Controllers\Api\V1\Amial\ProgressiveCustomerMoneyController;
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

// AMIAL-PROGRESSIVE-MONEY-001 — العميل التدريجي لا يمر من شرط
// is_kyc_verified القديم. كل خصم/استلام يمر من KycTierService + الإقامة
// الموثقة، مع بقاء idempotency على الأبواب التي قد تحرك المال.
Route::prefix('customer/money')
    ->middleware(['customerAuth', 'checkDeviceId'])
    ->name('amial.customer.money.')
    ->group(function () {
        Route::post('/send', [ProgressiveCustomerMoneyController::class, 'sendMoney'])
            ->middleware(['amial.zone:send_money', 'amial.idempotency'])
            ->name('send');
        Route::post('/cash-out', [ProgressiveCustomerMoneyController::class, 'cashOut'])
            ->middleware(['amial.zone:cash_out', 'amial.idempotency'])
            ->name('cash-out');
        Route::post('/request', [ProgressiveCustomerMoneyController::class, 'requestMoney'])
            ->middleware(['amial.zone:request_money', 'amial.idempotency'])
            ->name('request');
        Route::post('/request/{slug}', [ProgressiveCustomerMoneyController::class, 'requestMoneyStatus'])
            ->middleware('amial.idempotency')
            ->whereIn('slug', ['approve', 'deny'])
            ->name('request-status');
    });
