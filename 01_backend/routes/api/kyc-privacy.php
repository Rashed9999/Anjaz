<?php

use App\Http\Controllers\Api\V1\Amial\KycCompletionController;
use App\Http\Controllers\Api\V1\Amial\KycFullProfileController;
use App\Http\Controllers\Api\V1\Amial\KycIdentityUpgradeController;
use App\Http\Controllers\Api\V1\Amial\KycOwnershipEvidenceController;
use App\Http\Controllers\Api\V1\Amial\KycPrivacyController;
use App\Http\Controllers\Api\V1\Amial\KycResidenceController;
use App\Http\Controllers\Api\V1\Amial\VerificationStatusController;
use App\Http\Controllers\Api\V1\Amial\YemenRegionsController;
use Illuminate\Support\Facades\Route;

Route::get('me/verification-status', [VerificationStatusController::class, 'show'])
    ->name('amial.me.verification-status');
Route::get('me/kyc/completion', [KycCompletionController::class, 'show'])
    ->name('amial.me.kyc.completion');

Route::prefix('geo/yemen')->name('amial.geo.yemen.')->group(function () {
    Route::get('/districts', [YemenRegionsController::class, 'districts'])->name('districts');
});

Route::prefix('me/kyc/privacy')->name('amial.me.kyc.privacy.')->group(function () {
    Route::get('/', [KycPrivacyController::class, 'show'])->name('show');
    Route::post('/', [KycPrivacyController::class, 'update'])
        ->middleware('amial.rate-limit:kyc_privacy_update,10,10')
        ->name('update');

    Route::post('/biometric/start', [KycPrivacyController::class, 'startBiometric'])
        ->middleware('amial.rate-limit:kyc_biometric_start,3,10')
        ->name('biometric.start');
});

Route::prefix('me/kyc/residence')->name('amial.me.kyc.residence.')->group(function () {
    Route::get('/', [KycResidenceController::class, 'show'])->name('show');
    Route::post('/', [KycResidenceController::class, 'submit'])
        ->middleware('amial.rate-limit:kyc_residence_submit,5,60')
        ->name('submit');
});

Route::post('me/kyc/identity', [KycIdentityUpgradeController::class, 'submit'])
    ->middleware('amial.rate-limit:kyc_identity_submit,5,60')
    ->name('amial.me.kyc.identity.submit');

Route::post('me/kyc/profile', [KycFullProfileController::class, 'update'])
    ->middleware('amial.rate-limit:kyc_profile_update,10,10')
    ->name('amial.me.kyc.profile.update');

Route::post('me/kyc/ownership/selfie', [KycOwnershipEvidenceController::class, 'selfie'])
    ->middleware('amial.rate-limit:kyc_selfie_submit,3,60')
    ->name('amial.me.kyc.ownership.selfie');
