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


Route::post('newsletter/subscribe', [NewsLetterController::class, 'newsLetterSubscribe'])->name('newsletter.subscribe');


Route::group(['prefix' => 'agent', 'as' => 'agent.'], function () {
});


// AMIAL-ROOT-DOOR-001 — الجذر كان بلا مسارٍ إطلاقاً.
//
// فمن كتب `amialpay.com` رأى «404 NOT FOUND» بالإنجليزيّة على صفحةٍ
// بيضاء. وكان هنا `/home` وحده — ومن يكتب اسم نطاقٍ لا يكتب `/home`.
//
// وقالبُ `landing/` الموروث من 6cash محذوفٌ منذ تنظيفٍ سابق، فمتحكّمه
// (`LandingPageController`) ميّتٌ لا يُوصَل — وتوصيلُه اليوم يُنتج 500
// لا صفحة. فصفحةٌ خاصّةٌ بنا: ثلاثة أبوابٍ صريحة، بلا قاعدة بياناتٍ ولا
// شبكةٍ خارجيّة، فتعمل حتّى والقاعدة متوقّفة.
Route::view('/', 'home')->name('root');

Route::get('/home', function () {
    return redirect()->route('root');
});

Route::get('authentication-failed', function () {
    $errors = [];
    array_push($errors, ['code' => 'auth-001', 'message' => 'Unauthorized.']);
    return response()->json([
        'errors' => $errors
    ], 401);
})->name('authentication-failed');

Route::group(['prefix' => 'pages', 'as' => 'pages.'], function () {
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
