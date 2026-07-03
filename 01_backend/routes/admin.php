<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PurposeController;
use App\Http\Controllers\Admin\PageController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\BonusController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\EMoneyController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\LanguageController;
use App\Http\Controllers\Admin\MerchantController;
use App\Http\Controllers\Admin\TransferController;
use App\Http\Controllers\Admin\WithdrawController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\HelpTopicController;
use App\Http\Controllers\Admin\SMSModuleController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\WithdrawalController;
use App\Http\Controllers\Admin\SocialMediaController;
use App\Http\Controllers\Admin\SystemAddonController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\NotificationController;
use App\Http\Controllers\Admin\ContactMessageController;
use App\Http\Controllers\Admin\BusinessSettingsController;
use App\Http\Controllers\Admin\LandingPageSettingsController;
use App\Http\Controllers\Admin\BlogController;
use App\Http\Controllers\Admin\FAQController;
use App\Http\Controllers\Admin\BlogCategoryController;
use App\Http\Controllers\Admin\FAQCategoryController;
use App\Http\Controllers\Admin\DisputeController;

Route::group(['as' => 'admin.'], function () {
    Route::get('lang/{locale}', [LanguageController::class, 'lang'])->name('lang');

    Route::group(['namespace' => 'Auth', 'prefix' => 'auth', 'as' => 'auth.'], function () {
        Route::get('/code/captcha/{tmp}', [LoginController::class, 'captcha'])->name('default-captcha');
        Route::get('login', [LoginController::class, 'login'])->name('login');
        Route::post('login', [LoginController::class, 'submit']);
        Route::get('logout', [LoginController::class, 'logout'])->name('logout');
    });

    Route::group(['middleware' => ['admin']], function () {
        Route::get('/', [DashboardController::class, 'dashboard'])->name('dashboard');
        Route::get('settings', [DashboardController::class, 'settings'])->name('settings');
        Route::post('settings', [DashboardController::class, 'settingsUpdate'])->name('settings.update');
        Route::post('settings-password', [DashboardController::class, 'settingsPasswordUpdate'])->name('settings-password');

        Route::group(['prefix' => 'pages', 'as' => 'pages.'], function () {
            Route::get('terms-and-conditions', [PageController::class, 'termsAndConditions'])->name('terms-and-conditions');
            Route::post('terms-and-conditions', [PageController::class, 'termsAndConditionsUpdate'])->name('terms-and-conditions.update');

            Route::get('privacy-policy', [PageController::class, 'privacyPolicy'])->name('privacy-policy');
            Route::post('privacy-policy', [PageController::class, 'privacyPolicyUpdate'])->name('privacy-policy.update');

            Route::get('about-us', [PageController::class, 'aboutUs'])->name('about-us');
            Route::post('about-us', [PageController::class, 'aboutUsUpdate'])->name('about-us.update');

            Route::get('social-media/fetch', [SocialMediaController::class, 'fetch'])->name('social-media.fetch');
            Route::get('social-media/status-update', [SocialMediaController::class, 'socialMediaStatusUpdate'])->name('social-media.status-update');
            Route::resource('social-media', SocialMediaController::class);
        });

        Route::group(['prefix' => 'contact', 'as' => 'contact.'], function () {
            Route::get('list', [ContactMessageController::class, 'list'])->name('list');
            Route::post('delete', [ContactMessageController::class, 'destroy'])->name('delete');
            Route::get('view/{id}', [ContactMessageController::class, 'view'])->name('view');
            Route::post('update/{id}', [ContactMessageController::class, 'update'])->name('update');
            Route::post('send-mail/{id}', [ContactMessageController::class, 'sendMail'])->name('send-mail');
        });
        Route::get('subscribed-emails', [ContactMessageController::class, 'subscribedEmails'])->name('subscribed-emails');
        Route::get('export-subscribed-emails', [ContactMessageController::class, 'subscribedEmailsExport'])->name('export-subscribed-emails');

        Route::group(['prefix' => 'landing-settings', 'as' => 'landing-settings.'], function () {
              Route::get('get-landing-information', [LandingPageSettingsController::class, 'getLandingPageInformation'])->name('get-landing-information');
              Route::put('set-landing-information', [LandingPageSettingsController::class, 'updateLandingPageInformation'])->name('set-landing-information');
              Route::delete('delete-landing-information/{page}/{id}', [LandingPageSettingsController::class, 'landingPageInformationDelete'])->name('delete-landing-information');
              Route::get('status-landing-information/{page}/{id}', [LandingPageSettingsController::class, 'landingPageStatusUpdate'])->name('landing-status-change');
              Route::put('set-landing-title-status', [LandingPageSettingsController::class, 'landingPageTitleAndStatus'])->name('set-landing-title-status');
        });

        Route::group(['prefix' => 'business-settings', 'as' => 'business-settings.'], function () {
            Route::get('update-business-setting-status', [BusinessSettingsController::class, 'updateBusinessSettingStatus'])->name('update-business-setting-status');
            Route::post('update-business-setting-data', [BusinessSettingsController::class, 'updateBusinessSettingData'])->name('update-business-setting-data');

            Route::get('business-setup', [BusinessSettingsController::class, 'businessIndex'])->name('business-setup');
            Route::post('update-setup', [BusinessSettingsController::class, 'businessSetup'])->name('update-setup');

            Route::get('payment-method', [BusinessSettingsController::class, 'paymentIndex'])->name('payment-method');
            Route::post('payment-method-update', [BusinessSettingsController::class, 'paymentConfigUpdate'])->name('payment-method-update');

            Route::get('sms-module', [SMSModuleController::class, 'smsIndex'])->name('sms-module');
            Route::post('sms-module-update', [SMSModuleController::class, 'smsConfigUpdate'])->name('sms-module-update');

            Route::get('mail-config', [BusinessSettingsController::class, 'mailConfigIndex'])->name('mail_config');
            Route::get('send-mail-index', [BusinessSettingsController::class, 'testMailIndex'])->name('send_mail_index');
            Route::post('mail-config-update', [BusinessSettingsController::class, 'mailConfigUpdate'])->name('mail_config_update');
            Route::post('mail-config-status', [BusinessSettingsController::class, 'mailConfigStatus'])->name('mail_config_status');
            Route::get('mail-send', [BusinessSettingsController::class, 'sendMail'])->name('send_mail');

            Route::get('charge-setup', [BusinessSettingsController::class, 'chargeSetupIndex'])->name('charge-setup');
            Route::put('charge-setup', [BusinessSettingsController::class, 'chargeSetupUpdate']);

            Route::get('report-disputes', [DisputeController::class, 'disputesReasonIndex'])->name('report-disputes');
            Route::post('report-disputes-status', [DisputeController::class, 'disputesReasonSettingsStatus'])->name('report-disputes-status');
            Route::post('disputes-reason-store', [DisputeController::class, 'disputesReasonStore'])->name('disputes-reason-store');
            Route::get('disputes-reason-status/{id}', [DisputeController::class, 'disputesReasonStatus'])->name('disputes-reason-status');
            Route::get('disputes-reason-delete/{id}', [DisputeController::class, 'disputesReasonDelete'])->name('disputes-reason-delete');
            Route::post('disputes-reason-update', [DisputeController::class, 'disputesReasonUpdate'])->name('disputes-reason-update');
            Route::post('disputes-reason-time-update', [DisputeController::class, 'disputesReasonTimeUpdate'])->name('disputes-reason-time-update');

            Route::get('app-settings', [BusinessSettingsController::class, 'appSettings'])->name('app_settings');
            Route::get('app-setting-update', [BusinessSettingsController::class, 'appSettingUpdate'])->name('app_setting_update');

            Route::get('recaptcha', [BusinessSettingsController::class, 'recaptchaIndex'])->name('recaptcha_index');
            Route::post('recaptcha-update', [BusinessSettingsController::class, 'recaptchaUpdate'])->name('recaptcha_update');

            Route::get('fcm-index', [BusinessSettingsController::class, 'fcmIndex'])->name('fcm-index');
            Route::post('update-fcm', [BusinessSettingsController::class, 'updateFcm'])->name('update-fcm');
            Route::post('update-fcm-messages', [BusinessSettingsController::class, 'updateFcmMessages'])->name('update-fcm-messages');

            Route::group(['prefix' => 'language', 'as' => 'language.', 'middleware' => []], function () {
                Route::get('', [LanguageController::class, 'index'])->name('index');
                Route::post('add-new', [LanguageController::class, 'store'])->name('add-new');
                Route::get('update-status', [LanguageController::class, 'updateStatus'])->name('update-status');
                Route::get('update-default-status', [LanguageController::class, 'updateDefaultStatus'])->name('update-default-status');
                Route::post('update', [LanguageController::class, 'update'])->name('update');
                Route::get('translate/{lang}', [LanguageController::class, 'translate'])->name('translate');
                Route::post('translate-submit/{lang}', [LanguageController::class, 'translateSubmit'])->name('translate-submit');
                Route::post('remove-key/{lang}', [LanguageController::class, 'translateKeyRemove'])->name('remove-key');
                Route::get('delete/{lang}', [LanguageController::class, 'delete'])->name('delete');
            });

            Route::get('otp-setup', [BusinessSettingsController::class, 'otpSetup'])->name('otp_setup_index');
            Route::post('otp-setup-update', [BusinessSettingsController::class, 'otpSetupUpdate'])->name('otp_setup_update');

            Route::get('system-feature', [BusinessSettingsController::class, 'systemFeature'])->name('system_feature');
            Route::post('system-feature-update', [BusinessSettingsController::class, 'systemFeatureUpdate'])->name('system_feature_update');

            Route::get('customer-transaction-limits', [BusinessSettingsController::class, 'customerTransactionLimitsIndex'])->name('customer_transaction_limits');
            Route::get('agent-transaction-limits', [BusinessSettingsController::class, 'agentTransactionLimitsIndex'])->name('agent_transaction_limits');
            Route::post('transaction-limits/{transaction_type}', [BusinessSettingsController::class, 'transactionLimitsUpdate'])->name('transaction_limits_update');
        });

        Route::group(['prefix' => 'addon', 'as' => 'addon.'], function () {
            Route::get('/', [SystemAddonController::class, 'index'])->name('index');
            Route::post('publish', [SystemAddonController::class, 'publish'])->name('publish');
            Route::post('activation', [SystemAddonController::class, 'activation'])->name('activation');
            Route::post('upload', [SystemAddonController::class, 'upload'])->name('upload');
            Route::post('delete', [SystemAddonController::class, 'deleteAddon'])->name('delete');
        });

        Route::group(['prefix' => 'merchant-config', 'as' => 'merchant-config.'], function () {
            Route::get('/', [BusinessSettingsController::class, 'merchantConfigIndex'])->name('index');
            Route::post('merchant-payment-otp-verification-update', [BusinessSettingsController::class, 'merchantPaymentOtpUpdate'])->name('merchant-payment-otp-verification-update');
            Route::post('settings-update', [BusinessSettingsController::class, 'merchantSettingUpdate'])->name('settings-update');
        });

        Route::get('linked-website', [BusinessSettingsController::class, 'linkedWebsite'])->name('linked-website');
        Route::post('linked-website', [BusinessSettingsController::class, 'linkedWebsiteAdd'])->name('linked-website.add');
        Route::get('linked-website/update/{id}', [BusinessSettingsController::class, 'linkedWebsiteEdit'])->name('linked-website-edit');
        Route::put('linked-website', [BusinessSettingsController::class, 'linkedWebsiteUpdate'])->name('linked-website.update');
        Route::get('linked-website/status/{id}', [BusinessSettingsController::class, 'linkedWebsiteStatus'])->name('linked-website-status');
        Route::get('linked-website-delete', [BusinessSettingsController::class, 'linkedWebsiteDelete'])->name('linked-website-delete');

        Route::group(['prefix' => 'notification', 'as' => 'notification.'], function () {
            Route::get('add-new', [NotificationController::class, 'index'])->name('add-new');
            Route::post('store', [NotificationController::class, 'store'])->name('store');
            Route::get('edit/{id}', [NotificationController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [NotificationController::class, 'update'])->name('update');
            Route::get('status/{id}/{status}', [NotificationController::class, 'status'])->name('status');
            Route::delete('delete/{id}', [NotificationController::class, 'delete'])->name('delete');
        });

        Route::group(['prefix' => 'banner', 'as' => 'banner.'], function () {
            Route::get('add-new', [BannerController::class, 'index'])->name('index');
            Route::post('store', [BannerController::class, 'store'])->name('store');
            Route::get('edit/{id}', [BannerController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [BannerController::class, 'update'])->name('update');
            Route::get('status/{id}', [BannerController::class, 'status'])->name('status');
            Route::get('delete/{id}', [BannerController::class, 'delete'])->name('delete');
        });

        Route::group(['prefix' => 'bonus', 'as' => 'bonus.'], function () {
            Route::get('add-new', [BonusController::class, 'index'])->name('index');
            Route::post('store', [BonusController::class, 'store'])->name('store');
            Route::get('edit/{id}', [BonusController::class, 'edit'])->name('edit');
            Route::put('update/{id}', [BonusController::class, 'update'])->name('update');
            Route::get('status/{id}', [BonusController::class, 'status'])->name('status');
            Route::post('delete', [BonusController::class, 'delete'])->name('delete');
        });

        Route::group(['prefix' => 'helpTopic', 'as' => 'helpTopic.'], function () {
            Route::get('list', [HelpTopicController::class, 'list'])->name('list');
            Route::post('add-new', [HelpTopicController::class, 'store'])->name('add-new');
            Route::get('status/{id}', [HelpTopicController::class, 'status'])->name('status');
            Route::get('edit/{id}', [HelpTopicController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [HelpTopicController::class, 'update'])->name('update');
            Route::post('delete', [HelpTopicController::class, 'destroy'])->name('delete');
        });

        Route::group(['prefix' => 'customer', 'as' => 'customer.', 'middleware' => []], function () {
            Route::get('add', [CustomerController::class, 'index'])->name('add');
            Route::post('store', [CustomerController::class, 'store'])->name('store');
            Route::get('list', [CustomerController::class, 'customerList'])->name('list');
            Route::get('view/{user_id}', [CustomerController::class, 'view'])->name('view');
            Route::get('edit/{id}', [CustomerController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [CustomerController::class, 'update'])->name('update');
            Route::get('transaction/{user_id}', [CustomerController::class, 'transaction'])->name('transaction');
            Route::get('log/{user_id}', [CustomerController::class, 'log'])->name('log');
            Route::post('search', [CustomerController::class, 'search'])->name('search');
            Route::get('status/{id}', [CustomerController::class, 'status'])->name('status');
            Route::get('kyc-requests', [CustomerController::class, 'getKycRequest'])->name('kyc_requests');
            Route::get('kyc-status-update/{id}/{status}', [CustomerController::class, 'updateKycStatus'])->name('kyc_status_update');
        });
        Route::get('admin/transaction/{user_id}', [AdminController::class, 'transaction'])->name('admin.transaction');
        Route::get('admin/view/{user_id}', [AdminController::class, 'view'])->name('admin.view');

        Route::group(['prefix' => 'agent', 'as' => 'agent.'], function () {
            Route::get('add', [AgentController::class, 'index'])->name('add');
            Route::post('store', [AgentController::class, 'store'])->name('store');
            Route::get('list', [AgentController::class, 'list'])->name('list');
            Route::get('view/{user_id}', [CustomerController::class, 'view'])->name('view');
            Route::get('transaction/{user_id}', [CustomerController::class, 'transaction'])->name('transaction');
            Route::get('log/{user_id}', [CustomerController::class, 'log'])->name('log');
            Route::get('edit/{id}', [AgentController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [AgentController::class, 'update'])->name('update');
            Route::post('search', [AgentController::class, 'search'])->name('search');
            Route::get('status/{id}', [AgentController::class, 'status'])->name('status');
            Route::get('kyc-requests', [AgentController::class, 'getKycRequest'])->name('kyc_requests');
            Route::get('kyc-status-update/{id}/{status}', [AgentController::class, 'updateKycStatus'])->name('kyc_status_update');
        });

        Route::group(['prefix' => 'merchant', 'as' => 'merchant.'], function () {
            Route::get('add', [MerchantController::class, 'index'])->name('add');
            Route::post('store', [MerchantController::class, 'store'])->name('store');
            Route::get('list', [MerchantController::class, 'list'])->name('list');
            Route::get('view/{user_id}', [MerchantController::class, 'view'])->name('view');
            Route::get('transaction/{user_id}', [MerchantController::class, 'transaction'])->name('transaction');
            Route::get('edit/{id}', [MerchantController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [MerchantController::class, 'update'])->name('update');
            Route::post('search', [MerchantController::class, 'search'])->name('search');
            Route::get('status/{id}', [MerchantController::class, 'status'])->name('status');

        });

        Route::group(['prefix' => 'user', 'as' => 'user.'], function () {
            Route::get('log', [UserController::class, 'log'])->name('log');
        });

        Route::group(['prefix' => 'transaction', 'as' => 'transaction.'], function () {
            Route::get('index', [TransactionController::class, 'index'])->name('index');
            Route::get('export', [TransactionController::class, 'exportTransactions'])->name('export');
            Route::post('store', [TransactionController::class, 'store'])->name('store');

            Route::get('request-money', [TransactionController::class, 'requestMoney'])->name('request_money');
            Route::get('request-money-status/{slug}', [TransactionController::class, 'requestMoneyStatusChange'])->name('request_money_status_change');
            Route::get('get-user', [TransferController::class, 'getUser'])->name('get_user');
        });

        Route::group(['prefix' => 'expense', 'as' => 'expense.'], function () {
            Route::get('index', [ExpenseController::class, 'index'])->name('index');
        });

        Route::group(['prefix' => 'withdraw', 'as' => 'withdraw.'], function () {
            Route::get('requests', [WithdrawController::class, 'index'])->name('requests');
            Route::get('status-update', [WithdrawController::class, 'status_update'])->name('status_update');
            Route::get('download', [WithdrawController::class, 'download'])->name('download');
        });

        Route::group(['prefix' => 'transfer', 'as' => 'transfer.'], function () {
            Route::get('index', [TransferController::class, 'index'])->name('index');
            Route::post('store', [TransferController::class, 'store'])->name('store');
            Route::get('get-user', [TransferController::class, 'getUser'])->name('get_user');
        });

        Route::group(['prefix' => 'emoney', 'as' => 'emoney.'], function () {
            Route::get('index', [EMoneyController::class, 'index'])->name('index');
            Route::post('store', [EMoneyController::class, 'store'])->name('store');

        });

        Route::group(['prefix' => 'purpose', 'as' => 'purpose.'], function () {
            Route::get('index', [PurposeController::class, 'index'])->name('index');
            Route::post('store', [PurposeController::class, 'store'])->name('store');
            Route::get('edit/{id}', [PurposeController::class, 'edit'])->name('edit');
            Route::post('update', [PurposeController::class, 'update'])->name('update');
            Route::get('delete/{id}', [PurposeController::class, 'delete'])->name('delete');

        });

        Route::group(['prefix' => 'withdrawal-methods', 'as' => 'withdrawal_methods.'], function () {
            Route::get('add-method', [WithdrawalController::class, 'addMethod'])->name('add');
            Route::post('store', [WithdrawalController::class, 'storeMethod'])->name('store');
            Route::post('delete', [WithdrawalController::class, 'deleteMethod'])->name('delete');

        });

        Route::group(['prefix' => 'blog', 'as' => 'blog.'], function () {
            Route::get('index', [BlogController::class, 'index'])->name('index');
            Route::get('create', [BlogController::class, 'create'])->name('create');
            Route::post('store', [BlogController::class, 'store'])->name('store');
            Route::post('delete/{id}', [BlogController::class, 'delete'])->name('delete');
            Route::get('status/{id}}', [BlogController::class, 'status'])->name('status');
            Route::get('preview/{id}', [BlogController::class, 'preview'])->name('preview');
            Route::get('draft-preview/{id}', [BlogController::class, 'draftPreview'])->name('draft-preview');
            Route::get('edit/{id}', [BlogController::class, 'edit'])->name('edit');
            Route::get('draft-edit/{id}', [BlogController::class, 'draftEdit'])->name('draft-edit');
            Route::post('update/{id}', [BlogController::class, 'update'])->name('update');
            Route::get('priority', [BlogController::class, 'priority'])->name('priority');
            Route::get('download', [BlogController::class, 'download'])->name('download');
            Route::post('update-download', [BlogController::class, 'updateDownload'])->name('update-download');

            Route::group(['prefix' => 'category', 'as' => 'category.'], function () {
                Route::post('store', [BlogCategoryController::class, 'store'])->name('store');
                Route::post('update/{id}', [BlogCategoryController::class, 'update'])->name('update');
                Route::get('status/{id}', [BlogCategoryController::class, 'status'])->name('status');
                Route::post('delete/{id}', [BlogCategoryController::class, 'delete'])->name('delete');
                // AMIAL-FIX: أُزيل مسار PUT مكرّر (نفس الاسم/الـURI/الإجراء للـ POST أعلاه)
                // كان يكسر route:cache بخطأ "Another route has already been assigned name".
                Route::get('search', [BlogCategoryController::class, 'search'])->name('search');
                Route::get('count-increment/{id}', [BlogCategoryController::class, 'countIncrement'])->name('count-increment');
            });
        });

        Route::group(['prefix' => 'faq', 'as' => 'faq.'], function () {
            Route::get('index', [FAQController::class, 'index'])->name('index');
            Route::get('create', [FAQController::class, 'create'])->name('create');
            Route::post('store', [FAQController::class, 'store'])->name('store');
            Route::delete('delete/{id}', [FAQController::class, 'delete'])->name('delete');
            Route::get('status/{id}}', [FAQController::class, 'status'])->name('status');
            Route::get('edit/{id}', [FAQController::class, 'edit'])->name('edit');
            Route::get('details/{id}', [FAQController::class, 'details'])->name('details');
            Route::post('update/{id}', [FAQController::class, 'update'])->name('update');
            Route::get('priority', [FAQController::class, 'priority'])->name('priority');
            Route::get('download', [FAQController::class, 'download'])->name('download');
            Route::post('update-download', [FAQController::class, 'updateDownload'])->name('update-download');

            Route::group(['prefix' => 'category', 'as' => 'category.'], function () {
                Route::post('store', [FAQCategoryController::class, 'store'])->name('store');
                Route::post('update/{id}', [FAQCategoryController::class, 'update'])->name('update');
                Route::get('status/{id}', [FAQCategoryController::class, 'status'])->name('status');
                Route::post('delete/{id}', [FAQCategoryController::class, 'delete'])->name('delete');
                Route::get('search', [FAQCategoryController::class, 'search'])->name('search');
                Route::get('count-increment/{id}', [FAQCategoryController::class, 'countIncrement'])->name('count-increment');
            });
        });

        Route::group(['prefix' => 'disputes', 'as' => 'disputes.'], function () {
            Route::get('index', [DisputeController::class, 'list'])->name('index');
            Route::post('change-status', [DisputeController::class, 'changeStatus'])->name('change-status');
        });

    });
});


