<?php

use App\Http\Controllers\Api\V1\Auth\UnifiedAuthController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-UNIFIED-AUTH-001 (v1.5)
 *
 * Unified login routes — يُسجَّل في bootstrap/app.php أو في routes/api.php
 *
 * Endpoints:
 *   POST /api/v1/auth/login       (موحد لكل الأدوار)
 *   POST /api/v1/auth/agent/verify-otp
 *
 * تُحمي بـ rate limit عام (5 محاولات/دقيقة لكل IP).
 */

Route::prefix('auth')->name('amial.auth.')->middleware(['amial.rate-limit:auth_login,10,1,true'])->group(function () {
    Route::post('/login', [UnifiedAuthController::class, 'login'])->name('login');
    Route::post('/agent/verify-otp', [UnifiedAuthController::class, 'agentVerifyOtp'])->name('agent.verify-otp');
});
