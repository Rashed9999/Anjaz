<?php

use App\Http\Controllers\Api\V1\Amial\KycFullProfileController;
use App\Http\Controllers\Api\V1\Amial\KycIdentityUpgradeController;
use App\Http\Controllers\Api\V1\Amial\KycOwnershipEvidenceController;
use App\Http\Controllers\Api\V1\Amial\KycPrivacyController;
use App\Http\Controllers\Api\V1\Amial\KycResidenceController;
use App\Http\Controllers\Api\V1\Amial\VerificationStatusController;
use Illuminate\Support\Facades\Route;

Route::get('me/verification-status', [VerificationStatusController::class, 'show'])
    ->name('amial.me.verification-status');

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

// AMIAL-PROGRESSIVE-KYC-UPGRADE-001 — Tier 2 يثبت الهوية القانونية فقط:
// رقم مهيكل + وجه الوثيقة + ظهرها. لا selfie ولا نسخة في التخزين القديم.
Route::post('me/kyc/identity', [KycIdentityUpgradeController::class, 'submit'])
    ->middleware('amial.rate-limit:kyc_identity_submit,5,60')
    ->name('amial.me.kyc.identity.submit');

// AMIAL-KYC-COMPLETE-ACCOUNT-001 — بيانات «إكمال حسابي» للمستوى الكامل.
Route::post('me/kyc/profile', [KycFullProfileController::class, 'update'])
    ->middleware('amial.rate-limit:kyc_profile_update,10,10')
    ->name('amial.me.kyc.profile.update');

// AMIAL-KYC-COMPLETE-ACCOUNT-002 — صورة الملكية للمسار اليدوي/المقيد.
Route::post('me/kyc/ownership/selfie', [KycOwnershipEvidenceController::class, 'selfie'])
    ->middleware('amial.rate-limit:kyc_selfie_submit,3,60')
    ->name('amial.me.kyc.ownership.selfie');
