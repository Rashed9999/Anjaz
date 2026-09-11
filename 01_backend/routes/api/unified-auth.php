<?php

use App\Http\Controllers\Api\V1\Auth\EmailOtpController;
use App\Http\Controllers\Api\V1\Auth\EmailRegistrationController;
use App\Http\Controllers\Api\V1\Auth\UnifiedAuthController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-UNIFIED-AUTH-001 + AMIAL-EMAIL-OTP-001
 *
 * Unified public authentication surface. Email OTP is the pilot channel while
 * the legacy phone/SMS endpoints remain available in routes/api/v1/api.php.
 */

Route::prefix('auth')->name('amial.auth.')->middleware(['amial.rate-limit:auth_login,10,1,true'])->group(function () {
    Route::post('/login', [UnifiedAuthController::class, 'login'])->name('login');
    Route::post('/agent/verify-otp', [UnifiedAuthController::class, 'agentVerifyOtp'])->name('agent.verify-otp');

    // Pilot email OTP — all codes are short-lived, single-use and rate-limited.
    Route::post('/email-otp/request', [EmailOtpController::class, 'requestCode'])
        ->name('email-otp.request');
    Route::post('/email-otp/verify', [EmailOtpController::class, 'verifyCode'])
        ->name('email-otp.verify');
    Route::post('/register/email', [EmailRegistrationController::class, 'register'])
        ->name('register.email');
    Route::post('/password-reset/email', [EmailOtpController::class, 'resetPassword'])
        ->name('password-reset.email');
    Route::post('/pin-recovery/email', [EmailOtpController::class, 'resetPin'])
        ->name('pin-recovery.email');
});

// Resend webhook has its own cryptographic signature verification and must not
// share the user-login limiter because delivery events can arrive in bursts.
Route::post('/auth/email-otp/webhook', [EmailOtpController::class, 'webhook'])
    ->middleware('throttle:60,1')
    ->name('amial.auth.email-otp.webhook');
