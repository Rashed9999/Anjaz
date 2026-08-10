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
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\WithdrawalController;
use App\Http\Controllers\Admin\SocialMediaController;
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

        // AMIAL-OPS-CONSOLE-001 — منصة عمليات الموظفين (واجهة ويب + JSON بجلسة الأدمن)
        Route::group(['prefix' => 'support-center', 'as' => 'support-center.'], function () {
            $sc = \App\Http\Controllers\Api\V1\Amial\SupportConsoleController::class;
            Route::get('/', fn () => view('admin-views.support.console'))->name('index');
            Route::get('search', [$sc, 'search'])->name('search');
            Route::get('ops-dashboard', [$sc, 'opsDashboard'])->name('ops-dashboard');
            Route::get('customers/{id}', [$sc, 'customer'])->where('id', '[0-9]+')->middleware('platform:platform.customers.view')->name('customers.show');
            Route::get('customers/{id}/transactions', [$sc, 'customerTransactions'])->where('id', '[0-9]+')->middleware('platform:platform.transactions.view')->name('customers.transactions');
            Route::get('transactions/{ref}', [$sc, 'transaction'])->name('transactions.show');
            Route::post('customers/{id}/freeze', [$sc, 'freeze'])->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('customers.freeze');
            Route::post('customers/{id}/reset-pin', [$sc, 'resetPin'])->where('id', '[0-9]+')->middleware('platform:platform.customers.reset_pin')->name('customers.reset-pin');
            Route::post('customers/{id}/revoke-sessions', [$sc, 'revokeSessions'])->where('id', '[0-9]+')->middleware('platform:platform.customers.sessions')->name('customers.revoke-sessions');
            Route::post('customers/{id}/require-kyc', [$sc, 'requireKyc'])->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('customers.require-kyc');
            // AMIAL-DEVICE-TRUST-001: أجهزة العميل — تُحظر واحداً واحداً بدل
            // تجميد الحساب كلّه. والصلاحية نفسها المستعملة لإنهاء الجلسات:
            // كلاهما قطعُ وصولٍ لا مسٌّ بالمال.
            Route::get('customers/{id}/devices', [$sc, 'devices'])->where('id', '[0-9]+')->middleware('platform:platform.customers.sessions')->name('customers.devices');
            Route::post('devices/{deviceRowId}/block', [$sc, 'blockDevice'])->where('deviceRowId', '[0-9]+')->middleware('platform:platform.customers.sessions')->name('devices.block');
            Route::post('devices/{deviceRowId}/unblock', [$sc, 'unblockDevice'])->where('deviceRowId', '[0-9]+')->middleware('platform:platform.customers.sessions')->name('devices.unblock');
            Route::get('tickets', [$sc, 'tickets'])->name('tickets.index');
            Route::post('tickets', [$sc, 'createTicket'])->name('tickets.create');
            Route::get('tickets/{id}', [$sc, 'showTicket'])->where('id', '[0-9]+')->name('tickets.show');
            Route::post('tickets/{id}/update', [$sc, 'updateTicket'])->where('id', '[0-9]+')->name('tickets.update');
            Route::post('tickets/{id}/note', [$sc, 'addTicketNote'])->where('id', '[0-9]+')->name('tickets.note');
            // AMIAL-INSIDER-001: Maker-Checker + مراقبة الموظفين
            Route::get('approvals', [$sc, 'approvalsList'])->name('approvals.index');
            Route::post('approvals/{id}/approve', [$sc, 'approveRequest'])->where('id', '[0-9]+')->middleware('platform:platform.approvals.decide')->name('approvals.approve');
            Route::post('approvals/{id}/reject', [$sc, 'rejectRequest'])->where('id', '[0-9]+')->middleware('platform:platform.approvals.decide')->name('approvals.reject');
            Route::get('insider/overview', [$sc, 'insiderOverview'])->name('insider.overview');
            Route::post('insider/alerts/{id}/ack', [$sc, 'acknowledgeAlert'])->where('id', '[0-9]+')->name('insider.alerts.ack');
        });

        // AMIAL-MAINT-001 — لوحة «الصيانة الأولية» (تشغيل/إيقاف الميزات)
        Route::group(['prefix' => 'maintenance', 'as' => 'maintenance.'], function () {
            $mc = \App\Http\Controllers\Api\V1\Amial\MaintenanceController::class;
            Route::get('/', fn () => view('admin-views.maintenance.index'))->name('index');
            Route::get('list', [$mc, 'index'])->name('list');
            Route::post('{key}/enable', [$mc, 'enable'])->name('enable');
            Route::post('{key}/disable', [$mc, 'disable'])->name('disable');
        });
        Route::post('settings', [DashboardController::class, 'settingsUpdate'])->middleware('platform:platform.settings.update')->name('settings.update');
        Route::post('settings-password', [DashboardController::class, 'settingsPasswordUpdate'])->name('settings-password');

        Route::group(['prefix' => 'pages', 'as' => 'pages.'], function () {



        });

        Route::group(['prefix' => 'contact', 'as' => 'contact.'], function () {
        });

        Route::group(['prefix' => 'landing-settings', 'as' => 'landing-settings.'], function () {
        });

        Route::group(['prefix' => 'business-settings', 'as' => 'business-settings.'], function () {
            Route::post('update-business-setting-data', [BusinessSettingsController::class, 'updateBusinessSettingData'])->name('update-business-setting-data');

            Route::get('business-setup', [BusinessSettingsController::class, 'businessIndex'])->name('business-setup');
            Route::post('update-setup', [BusinessSettingsController::class, 'businessSetup'])->name('update-setup');

            // AMIAL-CLEANUP: أُزيلت مسارات بوّابات الدفع الخارجية (صفحتها محذوفة أصلاً)

            // AMIAL-CLEANUP: أُزيلت مسارات SMS القديمة (6cash) — إعدادات SMS الآن
            // عبر /api/v1/amial/admin/settings/sms (AdminSettingsController)

            Route::post('mail-config-update', [BusinessSettingsController::class, 'mailConfigUpdate'])->name('mail_config_update');
            Route::post('mail-config-status', [BusinessSettingsController::class, 'mailConfigStatus'])->name('mail_config_status');
            Route::get('mail-send', [BusinessSettingsController::class, 'sendMail'])->name('send_mail');

            Route::get('charge-setup', [BusinessSettingsController::class, 'chargeSetupIndex'])->name('charge-setup');
            Route::put('charge-setup', [BusinessSettingsController::class, 'chargeSetupUpdate']);

            Route::post('report-disputes-status', [DisputeController::class, 'disputesReasonSettingsStatus'])->name('report-disputes-status');
            Route::post('disputes-reason-time-update', [DisputeController::class, 'disputesReasonTimeUpdate'])->name('disputes-reason-time-update');

            Route::get('app-setting-update', [BusinessSettingsController::class, 'appSettingUpdate'])->name('app_setting_update');

            Route::post('recaptcha-update', [BusinessSettingsController::class, 'recaptchaUpdate'])->name('recaptcha_update');

            Route::get('fcm-index', [BusinessSettingsController::class, 'fcmIndex'])->name('fcm-index');
            Route::post('update-fcm', [BusinessSettingsController::class, 'updateFcm'])->name('update-fcm');
            // AMIAL-FCM-002: إرسال إشعارة اختبار حقيقية لتشخيص الصمت
            Route::post('test-fcm', [BusinessSettingsController::class, 'testFcm'])->name('test-fcm');
            Route::post('update-fcm-messages', [BusinessSettingsController::class, 'updateFcmMessages'])->name('update-fcm-messages');

            // AMIAL-AUDIT-ORPHAN-002: صفحة اللغات حيّة ومربوطة بالقائمة
            // الجانبية — أُعيدت بعد أن حذفتُها خطأً في تنظيف اللوحة القديمة.
            // حذف مسار تشير إليه القائمة يُسقط كل صفحات أميال، لأن الشريط
            // الجانبي جزء من قالبها المشترك: خطأ واحد فيه يعمّ اللوحة كلّها.
            Route::group(['prefix' => 'language', 'as' => 'language.', 'middleware' => []], function () {
                Route::get('', [LanguageController::class, 'index'])->name('index');
                Route::post('add-new', [LanguageController::class, 'store'])->name('add-new');
                Route::post('update-status', [LanguageController::class, 'updateStatus'])->name('update-status');
                Route::post('update-default-status', [LanguageController::class, 'updateDefaultStatus'])->name('update-default-status');
                Route::post('update', [LanguageController::class, 'update'])->name('update');
                Route::post('translate-submit/{lang}', [LanguageController::class, 'translateSubmit'])->name('translate-submit');
                Route::post('remove-key/{lang}', [LanguageController::class, 'translateKeyRemove'])->name('remove-key');
            });

            Route::post('otp-setup-update', [BusinessSettingsController::class, 'otpSetupUpdate'])->name('otp_setup_update');

            Route::post('system-feature-update', [BusinessSettingsController::class, 'systemFeatureUpdate'])->name('system_feature_update');

            Route::post('transaction-limits/{transaction_type}', [BusinessSettingsController::class, 'transactionLimitsUpdate'])->name('transaction_limits_update');
        });

        // AMIAL-CLEANUP: أُزيلت مجموعة مسارات addon (نظام إضافات 6cash — بلا وحدات)

        Route::group(['prefix' => 'merchant-config', 'as' => 'merchant-config.'], function () {
            Route::post('merchant-payment-otp-verification-update', [BusinessSettingsController::class, 'merchantPaymentOtpUpdate'])->middleware('amial.idempotency')->name('merchant-payment-otp-verification-update');
            Route::post('settings-update', [BusinessSettingsController::class, 'merchantSettingUpdate'])->name('settings-update');
        });


        Route::group(['prefix' => 'notification', 'as' => 'notification.'], function () {
            Route::get('add-new', [NotificationController::class, 'index'])->name('add-new');
            Route::post('store', [NotificationController::class, 'store'])->name('store');
            Route::get('edit/{id}', [NotificationController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [NotificationController::class, 'update'])->name('update');
            Route::post('status/{id}/{status}', [NotificationController::class, 'status'])->name('status');
            Route::delete('delete/{id}', [NotificationController::class, 'delete'])->name('delete');
        });

        Route::group(['prefix' => 'banner', 'as' => 'banner.'], function () {
            Route::get('add-new', [BannerController::class, 'index'])->name('index');
            Route::post('store', [BannerController::class, 'store'])->name('store');
            Route::get('edit/{id}', [BannerController::class, 'edit'])->name('edit');
            Route::post('update/{id}', [BannerController::class, 'update'])->name('update');
            Route::post('status/{id}', [BannerController::class, 'status'])->name('status');
            Route::delete('delete/{id}', [BannerController::class, 'delete'])->name('delete');
        });

        Route::group(['prefix' => 'bonus', 'as' => 'bonus.'], function () {
        });

        Route::group(['prefix' => 'helpTopic', 'as' => 'helpTopic.'], function () {
        });

        Route::group(['prefix' => 'customer', 'as' => 'customer.', 'middleware' => []], function () {
        });

        Route::group(['prefix' => 'agent', 'as' => 'agent.'], function () {
        });

        Route::group(['prefix' => 'merchant', 'as' => 'merchant.'], function () {
            Route::post('search', [MerchantController::class, 'search'])->name('search');

        });

        Route::group(['prefix' => 'user', 'as' => 'user.'], function () {
        });

        Route::group(['prefix' => 'transaction', 'as' => 'transaction.'], function () {
            Route::get('index', [TransactionController::class, 'index'])->name('index');
            Route::get('export', [TransactionController::class, 'exportTransactions'])->name('export');
            Route::post('store', [TransactionController::class, 'store'])->name('store');

        });

        Route::group(['prefix' => 'expense', 'as' => 'expense.'], function () {
            Route::get('index', [ExpenseController::class, 'index'])->name('index');
        });

        // ══════════════════════════════════════════════════════════════
        // AMIAL-WITHDRAW-DOOR-001 — **مالٌ يُحجز ولا بابَ لإخراجه.**
        //
        // كانت هذه المجموعةُ **فارغةً تماماً**. و`Admin\WithdrawController`
        // مكتملٌ — فيه `index` و`status_update` — ولا مسارَ يقود إليه.
        //
        // وفي المقابل `POST /api/v1/customer/withdraw` **حيّ**: العميلُ
        // يطلب سحباً فيُنقل مبلغُه من المتاح إلى المحجوز. ثمّ:
        //
        //   • لا مسارَ للإدارة يعتمد أو يرفض،
        //   • ولا نقطةَ للعميل تُلغي (`Customer\WithdrawController` فيها
        //     `list` وحدها).
        //
        // **فالمالُ يدخل الحجزَ ولا يخرج منه أبداً.** ولا خطأَ في أيّ
        // سجلّ: الطلبُ يُنشأ بنجاح، والرصيدُ ينقص، والعميلُ ينتظر.
        //
        // وهو نمطُ العطل الأكثر تكراراً في المشروع بصورته الأقسى: ليست
        // شاشةً لا يُوصل إليها، بل **بابَ خروجٍ للمال غيرَ موجود**.
        //
        // والصلاحيّة `platform.money.move`: اعتمادُ سحبٍ صرفٌ حقيقيّ من
        // خزنة المنصّة، لا قراءةُ تدقيق.
        Route::group(['prefix' => 'withdraw', 'as' => 'withdraw.'], function () {
            Route::get('/', [WithdrawController::class, 'index'])
                ->middleware('platform:platform.audit.view')->name('index');
            // **ومفتاحُ التفرّد إلزاميّ**: أمسك الحارسُ القائم
            // (`AppMoneyIdempotencyTest`) هذا المسارَ لحظةَ تسجيله وقال
            // «مسارُ مالٍ بلا مفتاح تفرّد» — قبل أن يصل إلى أحد. وهو
            // مثالٌ على حارسٍ يعمل: منع عودةَ صنفٍ من العطل في مسارٍ لم
            // يكن موجوداً يوم كُتب.
            Route::post('status-update', [WithdrawController::class, 'status_update'])
                ->middleware(['platform:platform.money.move', 'amial.idempotency'])
                ->name('status-update');
        });

        Route::group(['prefix' => 'transfer', 'as' => 'transfer.'], function () {
        });

        Route::group(['prefix' => 'emoney', 'as' => 'emoney.'], function () {
            Route::get('index', [EMoneyController::class, 'index'])->name('index');
            Route::post('store', [EMoneyController::class, 'store'])->name('store');

        });

        Route::group(['prefix' => 'purpose', 'as' => 'purpose.'], function () {

        });

        Route::group(['prefix' => 'withdrawal-methods', 'as' => 'withdrawal_methods.'], function () {

        });

        Route::group(['prefix' => 'blog', 'as' => 'blog.'], function () {

            Route::group(['prefix' => 'category', 'as' => 'category.'], function () {
                Route::post('store', [BlogCategoryController::class, 'store'])->name('store');
                Route::post('update/{id}', [BlogCategoryController::class, 'update'])->name('update');
                Route::post('delete/{id}', [BlogCategoryController::class, 'delete'])->name('delete');
                // AMIAL-FIX: أُزيل مسار PUT مكرّر (نفس الاسم/الـURI/الإجراء للـ POST أعلاه)
                // كان يكسر route:cache بخطأ "Another route has already been assigned name".
            });
        });

        Route::group(['prefix' => 'faq', 'as' => 'faq.'], function () {
            Route::get('index', [FAQController::class, 'index'])->name('index');
            Route::get('create', [FAQController::class, 'create'])->name('create');
            Route::post('store', [FAQController::class, 'store'])->name('store');
            Route::delete('delete/{id}', [FAQController::class, 'delete'])->name('delete');
            Route::post('status/{id}/{status}', [FAQController::class, 'status'])->name('status');
            Route::get('edit/{id}', [FAQController::class, 'edit'])->name('edit');
            Route::get('details/{id}', [FAQController::class, 'details'])->name('details');
            Route::post('update/{id}', [FAQController::class, 'update'])->name('update');
            Route::post('update-download', [FAQController::class, 'updateDownload'])->name('update-download');

            Route::group(['prefix' => 'category', 'as' => 'category.'], function () {
                Route::post('store', [FAQCategoryController::class, 'store'])->name('store');
                Route::post('update/{id}', [FAQCategoryController::class, 'update'])->name('update');
                Route::post('delete/{id}', [FAQCategoryController::class, 'delete'])->name('delete');
            });
        });

        Route::group(['prefix' => 'disputes', 'as' => 'disputes.'], function () {
        });

    });
});


