<?php

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PageController;
use App\Http\Controllers\NewsLetterController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\Web\RegistrationController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/


Route::get('/', [LandingPageController::class, 'landingPageHome'])->name('landing-page-home');
Route::post('newsletter/subscribe', [NewsLetterController::class, 'newsLetterSubscribe'])->name('newsletter.subscribe');
Route::post('send-message', [LandingPageController::class, 'contactUsMessage'])->name('send-message');
Route::get('contact-us', [LandingPageController::class, 'contactUs'])->name('contact-us');
Route::get('blog', [LandingPageController::class, 'blog'])->name('blog');
Route::get('faq', [LandingPageController::class, 'faq'])->name('faq');
Route::get('blog/details/{slug}', [LandingPageController::class, 'blogDetails'])->name('blog.details');
Route::get('popular-blog', [LandingPageController::class, 'popularBlogs'])->name('popular-blog');


Route::group(['prefix' => 'agent', 'as' => 'agent.'], function () {
    Route::get('registration', [RegistrationController::class, 'agentSelfRegistration'])->name('agent-self-registration');
    Route::post('store-registration', [RegistrationController::class, 'storeAgentData'])->name('store-registration');
    Route::post('phone-number-check', [RegistrationController::class, 'phoneNumberCheck'])->name('phone-number-check');
    Route::post('otp-verify', [RegistrationController::class, 'agentVerifyOtp'])->name('verify-otp');
    Route::post('resend-otp', [RegistrationController::class, 'resendOtp'])->name('resend-otp');
});


Route::get('/home', function() {
    return redirect(\route('admin.auth.login'));
});

Route::get('authentication-failed', function () {
    $errors = [];
    array_push($errors, ['code' => 'auth-001', 'message' => 'Unauthorized.']);
    return response()->json([
        'errors' => $errors
    ], 401);
})->name('authentication-failed');

Route::group(['prefix' => 'pages', 'as' => 'pages.'], function () {
    Route::get('terms-conditions', [PageController::class, 'getTermsAndConditions'])->name('terms-conditions');
    Route::get('privacy-policy', [PageController::class, 'getPrivacyPolicy'])->name('privacy-policy');
    Route::get('about-us', [PageController::class, 'getAboutUs'])->name('about-us');
});

Route::get('test', function () {
    //
});

// ============================================================
// AMIAL-WA-001 — WhatsApp Bot Routes
// ============================================================

// Webhook (بدون CSRF — Meta لا يرسل CSRF token)
Route::get('/wa/webhook',
    [\App\Http\Controllers\Api\V1\Amial\WhatsappWebhookController::class, 'verify']
)->name('wa.webhook.verify');

Route::post('/wa/webhook',
    [\App\Http\Controllers\Api\V1\Amial\WhatsappWebhookController::class, 'receive']
)->name('wa.webhook.receive')
 // AMIAL-WA-STRESS-001: كان 120/د فأسقط 4949 من 5000 رسالة في اختبار الضغط —
 // Meta تعيد المحاولة ثم تُسقط الرسائل نهائياً وقت الذروة. الحارس الفعلي هو توقيع
 // HMAC (fail-closed)؛ الـ throttle سقف إغراق ثانوي فقط: 6000/د = 100/ث.
 ->middleware('throttle:6000,1')
 ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

// صفحة PIN الآمنة — throttle لمنع تخمين PIN عبر الرابط
Route::get('/wa/pin/{token}',
    [\App\Http\Controllers\WhatsappPinController::class, 'show']
)->name('wa.pin.show')->where('token', '[a-zA-Z0-9]+')->middleware('throttle:20,1');

Route::post('/wa/pin/{token}',
    [\App\Http\Controllers\WhatsappPinController::class, 'submit']
)->name('wa.pin.submit')->where('token', '[a-zA-Z0-9]+')->middleware('throttle:10,1');
