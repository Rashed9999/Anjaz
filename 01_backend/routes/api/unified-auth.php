<?php

use App\Http\Controllers\Api\V1\Auth\AccountOpeningContractController;
use App\Http\Controllers\Api\V1\Auth\AccountOpeningProgressController;
use App\Http\Controllers\Api\V1\Auth\CustomerOpeningResumeController;
use App\Http\Controllers\Api\V1\Auth\EmailOtpController;
use App\Http\Controllers\Api\V1\Auth\EmailRegistrationController;
use App\Http\Controllers\Api\V1\Auth\MerchantAccountOpeningController;
use App\Http\Controllers\Api\V1\Auth\ProgressiveRegistrationController;
use App\Http\Controllers\Api\V1\Auth\UnifiedAuthController;
use App\Http\Controllers\Api\Internal\MuwathaqDecisionController;
use App\Http\Controllers\Api\Internal\MuwathaqEvidenceDocumentController;
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

    Route::get('/account-opening/contract', [AccountOpeningContractController::class, 'show'])
        ->middleware('amial.rate-limit:registration_contract,30,1,true')
        ->name('account-opening.contract');

    // A verified applicant can resume the ORIGINAL pending case after 24h.
    // Only a scoped evidence token is returned; no wallet or login authorization.
    Route::post('/account-opening/resume', CustomerOpeningResumeController::class)
        ->middleware('throttle:5,1')->name('account-opening.resume');

    Route::get('/account-opening/{applicationUlid}/status', [AccountOpeningProgressController::class, 'show'])
        ->middleware('amial.rate-limit:opening_progress,30,1,true')
        ->whereUlid('applicationUlid')
        ->name('account-opening.status');
    Route::post('/account-opening/{applicationUlid}/phone/confirm', [AccountOpeningProgressController::class, 'confirmPhone'])
        ->middleware('amial.rate-limit:opening_progress,8,5,true')
        ->whereUlid('applicationUlid')
        ->name('account-opening.phone.confirm');
    Route::post('/account-opening/{applicationUlid}/evidence/{kind}', [AccountOpeningProgressController::class, 'submitEvidence'])
        ->middleware('amial.rate-limit:opening_progress,8,5,true')
        ->whereUlid('applicationUlid')->where('kind', '[a-z_]{3,40}')
        ->name('account-opening.evidence.submit');
    Route::post('/account-opening/{applicationUlid}/residence', [AccountOpeningProgressController::class, 'submitResidence'])
        ->middleware('amial.rate-limit:opening_progress,8,5,true')
        ->whereUlid('applicationUlid')
        ->name('account-opening.residence.submit');

    Route::post('/register/quick', [ProgressiveRegistrationController::class, 'register'])
        ->middleware('amial.rate-limit:quick_registration,5,10,true')
        ->name('register.quick');

    Route::post('/register/merchant', [MerchantAccountOpeningController::class, 'register'])
        ->middleware('amial.rate-limit:merchant_registration,5,10,true')
        ->name('register.merchant');

    // باب التسجيل الكامل القديم باقٍ لتوافق العملاء التاريخيين فقط.
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

// ليس API للتطبيق أو المتصفح. موثّق يوقّع الجسم الخام ووقت الإرسال بـ HMAC.
Route::post('/internal/muwathaq/applications/{applicationUlid}/decision', [MuwathaqDecisionController::class, 'store'])
    ->middleware(['muwathaq.signature', 'throttle:30,1'])
    ->name('amial.internal.muwathaq.application.decision');

Route::get('/internal/muwathaq/applications/{applicationUlid}/evidences/{evidenceId}/document', [MuwathaqEvidenceDocumentController::class, 'show'])
    ->middleware(['muwathaq.signature', 'throttle:60,1'])
    ->whereUlid('applicationUlid')
    ->whereNumber('evidenceId')
    ->name('amial.internal.muwathaq.evidence.document');

// دليل الحسابات للتشغيل داخل موثّق فقط، وسجل تدقيقه append-only في أميال باي.
Route::get('/internal/muwathaq/account-directory', [MuwathaqOperationsController::class, 'directory'])
    ->middleware(['muwathaq.signature', 'throttle:60,1'])
    ->name('amial.internal.muwathaq.account-directory');

Route::post('/internal/muwathaq/reviewer-audit-events', [MuwathaqOperationsController::class, 'storeAuditEvent'])
    ->middleware(['muwathaq.signature', 'throttle:120,1'])
    ->name('amial.internal.muwathaq.reviewer-audit-events');
