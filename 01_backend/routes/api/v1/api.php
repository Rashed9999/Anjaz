<?php

use App\Http\Controllers\Api\V1\Agent\AgentController;
use App\Http\Controllers\Api\V1\Customer\Auth\PasswordResetController;
use App\Http\Controllers\Api\V1\Customer\WithdrawController;
use App\Http\Controllers\Api\V1\Agent\AgentWithdrawController;
use App\Http\Controllers\Api\V1\Agent\Auth\PasswordResetController as AgentPasswordResetController;
use App\Http\Controllers\Api\V1\Agent\TransactionController as AgentTransactionController;
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
use App\Http\Controllers\Api\V1\RegisterController;
use App\Http\Controllers\Api\V1\Agent\Auth\AgentAuthController;
use App\Http\Controllers\Api\V1\Agent\DisputeController as AgentDisputeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['throttle:100,1'])->group(function () {
    Route::group(['middleware' => ['deviceVerify']], function () {
        Route::group(['middleware' => ['inactiveAuthCheck', 'trackLastActiveAt', 'auth:api']], function () {
            Route::post('check-customer', [GeneralController::class, 'checkCustomer']);
            Route::post('check-agent', [GeneralController::class, 'checkAgent']);
        });

        Route::group(['prefix' => 'customer', 'namespace' => 'Auth'], function () {
            Route::group(['prefix' => 'auth', 'namespace' => 'Auth'], function () {
                Route::post('register', [RegisterController::class, 'customerRegistration']);
                Route::post('login', [LoginController::class, 'customerLogin']);

                Route::post('check-phone', [CustomerAuthController::class, 'checkPhone']);
                Route::post('verify-phone', [CustomerAuthController::class, 'verifyPhone']);
                Route::post('resend-otp', [CustomerAuthController::class, 'resendOTP']);

                Route::post('forgot-password', [PasswordResetController::class, 'resetPasswordRequest']);
                Route::post('verify-token', [PasswordResetController::class, 'verifyToken']);
                Route::put('reset-password', [PasswordResetController::class, 'resetPasswordSubmit']);
            });

            Route::group(['middleware' => ['inactiveAuthCheck', 'trackLastActiveAt', 'auth:api', 'customerAuth', 'checkDeviceId']], function () {
                Route::get('get-customer', [CustomerAuthController::class, 'getCustomer']);
                Route::get('get-purpose', [CustomerAuthController::class, 'getPurpose']);
                Route::get('get-banner', [BannerController::class, 'getCustomerBanner']);
                Route::get('linked-website', [CustomerAuthController::class, 'linkedWebsite']);
                Route::get('get-notification', [NotificationController::class, 'getCustomerNotification']);
                Route::get('get-requested-money', [CustomerAuthController::class, 'getRequestedMoney']);
                Route::get('get-own-requested-money', [CustomerAuthController::class, 'getOwnRequestedMoney']);
                Route::delete('remove-account', [CustomerAuthController::class, 'removeAccount']);
                Route::put('update-kyc-information', [CustomerAuthController::class, 'updateKycInformation']);

                Route::post('check-otp', [OTPController::class, 'checkOtp']);
                Route::post('verify-otp', [OTPController::class, 'verifyOtp']);

                Route::post('verify-pin', [CustomerAuthController::class, 'verifyPin']);
                Route::post('change-pin', [CustomerAuthController::class, 'changePin']);

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
                Route::post('add-money', [TransactionController::class, 'addMoney'])->middleware('amial.zone:add_money');
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

        Route::group(['prefix' => 'agent', 'namespace' => 'Auth'], function () {
            Route::group(['prefix' => 'auth', 'namespace' => 'Auth'], function () {
                Route::post('register', [RegisterController::class, 'agentRegistration']);
                Route::post('login', [LoginController::class, 'agentLogin']);

                Route::post('check-phone', [AgentAuthController::class, 'checkPhone']);
                Route::post('verify-phone', [AgentAuthController::class, 'verifyPhone']);
                Route::post('resend-otp', [AgentAuthController::class, 'resendOtp']);

                Route::post('forgot-password', [AgentPasswordResetController::class, 'resetPasswordRequest']);
                Route::post('verify-token', [AgentPasswordResetController::class, 'verifyToken']);
                Route::put('reset-password', [AgentPasswordResetController::class, 'resetPasswordSubmit']);
            });

            Route::group(['middleware' => ['inactiveAuthCheck', 'trackLastActiveAt', 'auth:api', 'agentAuth', 'checkDeviceId']], function () {
                Route::get('get-agent', [AgentController::class, 'getAgent']);
                Route::get('get-notification', [NotificationController::class, 'getAgentNotification']);
                Route::get('get-banner', [BannerController::class, 'getAgentBanner']);
                Route::get('linked-website', [AgentController::class, 'linkedWebsite']);
                Route::get('get-requested-money', [AgentController::class, 'getRequestedMoney']);
                Route::put('update-kyc-information', [CustomerAuthController::class, 'updateKycInformation']);

                Route::post('check-otp', [OTPController::class, 'checkOtp']);
                Route::post('verify-otp', [OTPController::class, 'verifyOtp']);

                Route::post('verify-pin', [AgentController::class, 'verifyPin']);
                Route::post('change-pin', [AgentController::class, 'changePin']);

                Route::put('update-profile', [AgentController::class, 'updateProfile']);
                Route::post('update-two-factor', [AgentController::class, 'updateTwoFactor']);
                Route::put('update-fcm-token', [AgentController::class, 'updateFcmToken']);
                Route::post('logout', [AgentController::class, 'logout']);
                Route::delete('remove-account', [AgentController::class, 'removeAccount']);

                // AMIAL-ZONE-BOUNDARY-001: الوكيل يستلم نقداً هنا (إيداع)
                Route::post('send-money', [AgentTransactionController::class, 'cashIn'])->middleware(['amial.zone:cash_in', 'amial.agent-location', 'amial.idempotency']);
                Route::post('request-money', [AgentTransactionController::class, 'requestMoney'])->middleware(['amial.zone:request_money', 'amial.idempotency']);
                Route::post('add-money', [AgentTransactionController::class, 'addMoney'])->middleware(['amial.zone:add_money', 'amial.agent-location']);
                Route::post('withdraw', [AgentTransactionController::class, 'withdraw'])->middleware(['amial.zone:withdraw', 'amial.agent-location', 'amial.idempotency']);
                Route::get('transaction-history', [AgentTransactionController::class, 'transactionHistory']);
                Route::get('transaction/download-pdf', [AgentTransactionController::class, 'downloadTransaction']);

                Route::get('withdrawal-methods', [AgentTransactionController::class, 'withdrawalMethods']);
                Route::get('withdrawal-requests', [AgentWithdrawController::class, 'list']);

                Route::group(['prefix' => 'dispute'], function () {
                    Route::get('reason/list', [AgentDisputeController::class, 'reasonList']);
                    Route::post('create', [AgentDisputeController::class, 'create']);
                });
            });
        });

        Route::get('/config', [ConfigController::class, 'configuration']);
        Route::get('/faq', [GeneralController::class, 'faq']);
        Route::get('/faq/category', [GeneralController::class, 'faqCategory']);
    });

    // AMIAL-FIX: مسارات بوّابات الدفع الخارجية محذوفة (PaymentOrderController مُزال).
    // نموذج أميال باي يعتمد شحن الرصيد عبر الوكلاء لا بوّابات عالمية.
});
