<?php

use App\Http\Controllers\Api\V1\Amial\KycBiometricWebhookController;
use Illuminate\Support\Facades\Route;

// لا auth هنا: المزود الخارجي لا يملك جلسة مستخدم. الثقة من توقيع Driver.
Route::post('/kyc/biometric/webhook/{provider}', KycBiometricWebhookController::class)
    ->where('provider', '[A-Za-z0-9_-]{1,64}')
    ->middleware('throttle:300,1')
    ->name('amial.kyc.biometric.webhook');
