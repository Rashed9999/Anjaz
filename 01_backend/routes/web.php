<?php

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PageController;
use App\Http\Controllers\NewsLetterController;
use App\Http\Controllers\LandingPageController;
use App\Http\Controllers\Web\RegistrationController;
use App\Http\Controllers\Api\V1\Amial\ReceiptController;

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


// ══════════════════════════════════════════════════════════════════
// AMIAL-SITE-001 — الموقع العامّ.
//
// الجذر كان بلا مسارٍ إطلاقاً، فمن كتب `amialpay.com` رأى «404 NOT
// FOUND». ثمّ صار صفحةَ أبوابٍ مؤقّتة، وهو الآن موقعٌ كامل.
//
// وقالبُ `landing/` الموروث من 6cash محذوفٌ منذ تنظيفٍ سابق، فمتحكّمه
// (`LandingPageController`) ميّتٌ لا يُوصَل — وتوصيلُه يُنتج 500 لا صفحة.
//
// **ولا شيء هنا يمسّ قاعدة البيانات.** الموقع أوّلُ ما يراه الزائر،
// فيعمل والقاعدة متوقّفة — وإلّا صار عطلٌ داخليّ «الشركة كلّها لا تعمل»
// في نظر من يمرّ. ولذلك `Route::view` لا متحكّمٌ يستعلم.
//
// واسمُ القالب هو اسمُ المسار بعد `site.` — فلا جدولُ ترجمةٍ بينهما
// يُنسى تحديثُه فيُشير مسارٌ إلى قالبٍ آخر بلا خطأٍ ظاهر.
// ══════════════════════════════════════════════════════════════════
foreach (['home', 'personal', 'business', 'exchange', 'about',
          'security', 'faq', 'contact', 'terms', 'privacy'] as $page) {
    Route::view($page === 'home' ? '/' : "/{$page}", "site.{$page}")
        ->name("site.{$page}");
}

// AMIAL-UNIFIED-WEB-LOGIN-001 — بابٌ واحد يفتح على مسار صاحبه.
// والبابان القديمان يبقيان: التطبيق والاختبارات وروابط الناس تعتمد عليهما.
Route::get('/login', [\App\Http\Controllers\Web\UnifiedLoginController::class, 'show'])
    ->name('login');
Route::post('/login', [\App\Http\Controllers\Web\UnifiedLoginController::class, 'submit'])
    ->name('login.submit');
Route::get('/login/captcha', [\App\Http\Controllers\Web\UnifiedLoginController::class, 'captcha'])
    ->name('login.captcha');

Route::get('/home', function () {
    return redirect()->route('site.home');
});

// رابط التحقق الذي يشارك من سند العميل. مستقل عن API المحمي: لا يتطلب
// تسجيل دخول ولا يعرض الأطراف أو أي بيانات شخصية.
//
// AMIAL-DOC-VERIFY-001 — **وصار له بابٌ يُدخَل منه.**
//
// كان `/v/{code}` وحدَه: من مسح QR وصل، **ومن يحمل ورقةً ويريد كتابة
// رمزها لا بابَ له إطلاقاً**. فأُضيفت `/verify` — الصفحةُ الثابتة.
//
// **والمدى `8` لا `16`**: سندُ الوقود يطبع رمزاً من ثمانية محارف تحت
// لافتة «رمز التحقّق» — وكان المسارُ يرفضه بصيغته قبل أن يُسأل عنه
// أحد، فيقرأ صاحبُ السند الصحيحِ أنّ سندَه غيرُ صالح.
Route::get('/verify', [\App\Http\Controllers\PublicVerificationController::class, 'page'])
    ->middleware('throttle:30,1')
    ->name('public.verify');

Route::get('/v/{code}', [\App\Http\Controllers\PublicVerificationController::class, 'show'])
    ->where('code', '[A-Za-z0-9 \-]{8,40}')
    ->middleware('throttle:20,1')
    ->name('receipt.verify.public-page');

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
