<?php

use App\Http\Controllers\Api\V1\Customer\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Customer\WithdrawController;
use App\Http\Controllers\Api\V1\BannerController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\Customer\Auth\CustomerAuthController;
use App\Http\Controllers\Api\V1\Customer\TransactionController;
use App\Http\Controllers\Api\V1\Customer\FavouriteNumberController;
use App\Http\Controllers\Api\V1\Customer\DisputeController;
use App\Http\Controllers\Api\V1\GeneralController;
use App\Http\Controllers\Api\V1\LoginController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OTPController;
use App\Http\Controllers\Api\V1\Auth\AccountOpeningBoundaryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:100,1'])->group(function () {
    Route::group(['middleware' => ['deviceVerify']], function () {
        Route::group(['middleware' => ['inactiveAuthCheck', 'trackLastActiveAt', 'auth:api', 'amial.pos-device']], function () {
            Route::post('check-customer', [GeneralController::class, 'checkCustomer']);
            Route::post('check-agent', [GeneralController::class, 'checkAgent']);
        });

        Route::group(['prefix' => 'customer', 'namespace' => 'Auth'], function () {
            Route::group(['prefix' => 'auth', 'namespace' => 'Auth', 'middleware' => 'throttle:auth-sensitive'], function () {
                Route::post('register', [AccountOpeningBoundaryController::class, 'customer']);
                Route::post('login', [LoginController::class, 'customerLogin']);

                Route::post('check-phone', [CustomerAuthController::class, 'checkPhone']);
                Route::post('verify-phone', [CustomerAuthController::class, 'verifyPhone']);
                Route::post('resend-otp', [CustomerAuthController::class, 'resendOTP']);

                Route::post('forgot-password', [PasswordResetController::class, 'resetPasswordRequest']);
                Route::post('verify-token', [PasswordResetController::class, 'verifyToken']);
                Route::put('reset-password', [PasswordResetController::class, 'resetPasswordSubmit']);
            });

            Route::group(['middleware' => ['inactiveAuthCheck', 'trackLastActiveAt', 'auth:api', 'customerAuth', 'checkDeviceId', 'amial.pos-device']], function () {
                Route::get('get-customer', [CustomerAuthController::class, 'getCustomer']);
                Route::get('get-purpose', [CustomerAuthController::class, 'getPurpose']);
                Route::get('get-banner', [BannerController::class, 'getCustomerBanner']);
                Route::get('linked-website', [CustomerAuthController::class, 'linkedWebsite']);
                Route::get('get-notification', [NotificationController::class, 'getCustomerNotification']);
                Route::get('get-requested-money', [CustomerAuthController::class, 'getRequestedMoney']);
                Route::get('get-own-requested-money', [CustomerAuthController::class, 'getOwnRequestedMoney']);
                Route::delete('remove-account', [CustomerAuthController::class, 'removeAccount']);
                Route::put('update-kyc-information', [CustomerAuthController::class, 'updateKycInformation']);

                // AMIAL-OTP-BRUTEFORCE-001 — **حدٌّ خاصٌّ لا العامّ.**
                //
                // كان المساران تحت `throttle:100,1` العامّ: مئةُ محاولةٍ
                // في الدقيقة على أربعة أرقام تستنفد المساحة كلَّها
                // (١٠٠٠٠ احتمال) في ساعةٍ ونصف. والعدّادُ في الجدول
                // يحمي رقماً واحداً، وهذا يحمي من يجرّب أرقاماً كثيرة.
                Route::post('check-otp', [OTPController::class, 'checkOtp'])
                    ->middleware('throttle:5,1');
                Route::post('verify-otp', [OTPController::class, 'verifyOtp'])
                    ->middleware('throttle:10,1');

                // ══════════════════════════════════════════════════
                // AMIAL-PIN-BRUTEFORCE-001 — **حدٌّ على بابَي الرمز.**
                //
                // كانا وحدَهما بلا `throttle` بين جيرانٍ كلُّهم محدود
                // (`check-otp` ٥/د · `verify-otp` ١٠/د)، ورمزُ العمليّات
                // أربعةُ أرقام ⇒ ١٠٠٠٠ احتمال.
                //
                // **والقفلُ على الحساب لا يُغني عن الحدّ**: ذاك يحمي
                // رقماً واحداً، وهذا يمنع مسحاً عريضاً على آلاف الحسابات
                // من جلسةٍ واحدة. (الدرسُ نفسُه في `EscalatingLockoutGuardTest`.)
                // ══════════════════════════════════════════════════
                Route::post('verify-pin', [CustomerAuthController::class, 'verifyPin'])
                    ->middleware('throttle:10,1');
                Route::post('change-pin', [CustomerAuthController::class, 'changePin'])
                    ->middleware('throttle:6,1');

                Route::put( 'update-profile', [CustomerAuthController::class, 'updateProfile']);
                Route::post('update-two-factor', [CustomerAuthController::class, 'updateTwoFactor']);
                Route::put('update-fcm-token', [CustomerAuthController::class, 'updateFcmToken']);
                Route::post('logout', [CustomerAuthController::class, 'logout']);

                // AMIAL-PILOT-IDEM-001 — **مفتاحُ التفرّد على كلّ ما يُحرّك مالاً.**
                //
                // قِيس لا افتُرض: أُرسل الطلبُ نفسُه مرّتين بمفتاحٍ واحدٍ إلى
                // `customer/send-money` فوصل المستلِمَ **٢٠٠٠ والمبلغُ ١٠٠٠**،
                // وسُجّلت أربعُ حركاتٍ بدل اثنتين، وردّ الخادمُ `200 success`
                // في المرّتين. فانقطاعُ شبكةٍ بعد وصول الطلب وقبل وصول الردّ
                // يُضاعف التحويل، والمرسِلُ لا يعلم.
                //
                // وكان الوسيطُ مبنيّاً ومستعملاً في مسارات `amial/*` وحدها،
                // **وهذه المسارات — وهي التي يناديها التطبيق يوميّاً — بلا
                // حماية.** (مبنيٌّ ولا يُوصَل إليه، في صورته المالية.)
                Route::post('send-money', [TransactionController::class, 'sendMoney'])->middleware(['amial.zone:send_money', 'amial.idempotency']);
                Route::post('cash-out', [TransactionController::class, 'cashOut'])->middleware(['amial.zone:cash_out', 'amial.idempotency']);
                Route::post('request-money', [TransactionController::class, 'requestMoney'])->middleware(['amial.zone:request_money', 'amial.idempotency']);
                Route::post('request-money/{slug}', [TransactionController::class, 'requestMoneyStatus'])->middleware('amial.idempotency');
                Route::post('add-money', [TransactionController::class, 'addMoney'])->middleware(['amial.zone:add_money', 'amial.idempotency']);
                Route::post('withdraw', [TransactionController::class, 'withdraw'])->middleware(['amial.zone:withdraw', 'amial.idempotency']);
                Route::get('transaction-history', [TransactionController::class, 'transactionHistory']);
                Route::get('transaction/download-pdf', [TransactionController::class, 'downloadTransaction']);

                Route::get('withdrawal-methods', [TransactionController::class, 'withdrawalMethods']);
                Route::get('withdrawal-requests', [WithdrawController::class, 'list']);

                Route::group(['prefix' => 'favourite-number'], function () {
                    Route::get('list', [FavouriteNumberController::class, 'index']);
                    Route::post('store', [FavouriteNumberController::class, 'store']);
                    Route::post('update/{id}', [FavouriteNumberController::class, 'update']);
                    Route::delete('delete/{id}', [FavouriteNumberController::class, 'destroy']);
                });

                Route::group(['prefix' => 'dispute'], function () {
                    Route::get('reason/list', [DisputeController::class, 'reasonList']);
                    Route::post('create', [DisputeController::class, 'create']);
                });
            });
        });

        // AMIAL-AGENT-LEGACY-RETIRED-001 — كان هنا الجيل القديم من حساب الوكيل الفردي بمحفظة شخصية
        // (تسجيل/دخول/إرسال/سحب/اعتراض عبر هذا المسار). التسجيل كان مغلقاً أصلاً (`retired`)، وأُزيل الباقي
        // بقرار صاحب المشروع: البيانات تجريبية، ووظيفة الوكلاء الحيّة اليوم هي شركة الصرافة/الفروع/الصرّافين
        // (`/agent/*` بوّابة الويب) وشبكة الوكلاء (`/api/v1/amial/agent/*` — AgentNetworkController، لم تُمسّ).

        Route::get('/config', [ConfigController::class, 'configuration']);
        Route::get('/faq', [GeneralController::class, 'faq']);
        Route::get('/faq/category', [GeneralController::class, 'faqCategory']);
    });

    // AMIAL-FIX: مسارات بوّابات الدفع الخارجية محذوفة (PaymentOrderController مُزال).
    // نموذج أميال باي يعتمد شحن الرصيد عبر الوكلاء لا بوّابات عالمية.
});
