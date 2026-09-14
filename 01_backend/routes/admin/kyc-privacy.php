<?php

use App\Http\Controllers\Admin\KycForensicController;
use App\Http\Controllers\Admin\KycPrivacyAdminController;
use App\Http\Controllers\Admin\KycRestrictedReviewController;
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

// AMIAL-KYC-RESTRICTED-QUEUE-001 — الحالات التي طلب أصحابها خصوصية إضافية
// لا تمر بالطابور العام. العرض والقرار مفتاحان منفصلان.
Route::prefix('kyc/restricted')
    ->name('kyc.restricted.')
    ->middleware([
        'platform:platform.customers.kyc.view',
        'platform:platform.customers.kyc.restricted.view',
    ])
    ->group(function () {
        Route::get('/queue', [KycRestrictedReviewController::class, 'queue'])->name('queue');
        Route::get('/users/{userId}', [KycRestrictedReviewController::class, 'show'])
            ->where('userId', '[0-9]+')->name('show');
        Route::post('/users/{userId}/in-person-verify', [KycRestrictedReviewController::class, 'verifyInPerson'])
            ->where('userId', '[0-9]+')
            ->middleware('platform:platform.customers.kyc.restricted.decide')
            ->name('in-person-verify');
    });
