<?php

use App\Http\Controllers\Api\V1\Auth\EmailOtpController;
use App\Http\Controllers\Api\V1\Auth\EmailRegistrationController;
use App\Http\Controllers\Api\V1\Auth\ProgressiveRegistrationController;
use App\Http\Controllers\Api\V1\Auth\UnifiedAuthController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-UNIFIED-AUTH-001 + AMIAL-PROGRESSIVE-KYC-001
 *
 * الحساب الأساسي له باب سريع مستقل؛ التسجيل القديم يبقى للتوافق ومسارات
 * التاجر/الوكيل بينما لا يُجبر عميل المحفظة على KYC كامل قبل رؤية حسابه.
 */
Route::prefix('auth')->name('amial.auth.')->middleware(['amial.rate-limit:auth_login,10,1,true'])->group(function () {
    Route::post('/login', [UnifiedAuthController::class, 'login'])->name('login');
    Route::post('/agent/verify-otp', [UnifiedAuthController::class, 'agentVerifyOtp'])->name('agent.verify-otp');

    Route::post('/email-otp/request', [EmailOtpController::class, 'requestCode'])
        ->name('email-otp.request');
    Route::post('/email-otp/verify', [EmailOtpController::class, 'verifyCode'])
        ->name('email-otp.verify');

    Route::post('/register/quick', [ProgressiveRegistrationController::class, 'register'])
        ->middleware('amial.rate-limit:quick_registration,5,10,true')
        ->name('register.quick');

    // باب التسجيل الكامل القديم باقٍ للتوافق أثناء الانتقال.
    Route::post('/register/email', [EmailRegistrationController::class, 'register'])
        ->name('register.email');
    Route::post('/password-reset/email', [EmailOtpController::class, 'resetPassword'])
        ->name('password-reset.email');
    Route::post('/pin-recovery/email', [EmailOtpController::class, 'resetPin'])
        ->name('pin-recovery.email');
});

Route::prefix('auth')->name('amial.auth.')->middleware(['auth:api', 'amial.pos-device', 'throttle:5,1'])->group(function () {
    Route::post('/email-change/request', [EmailOtpController::class, 'requestEmailChange'])
        ->name('email-change.request');
    Route::post('/email-change/confirm', [EmailOtpController::class, 'confirmEmailChange'])
        ->name('email-change.confirm');
});

Route::post('/auth/email-otp/webhook', [EmailOtpController::class, 'webhook'])
    ->middleware('throttle:60,1')
    ->name('amial.auth.email-otp.webhook');
