<?php

use App\Http\Controllers\Admin\AccountRecoveryController as AdminRecoveryController;
use App\Http\Controllers\Admin\AdminCharityController;
use App\Http\Controllers\Admin\AdminSafePaymentController;
use App\Http\Controllers\Admin\LegalTermsController as AdminLegalController;
use App\Http\Controllers\Api\V1\Amial\AccountRecoveryController;
use App\Http\Controllers\Api\V1\Amial\AgentNetworkController;
use App\Http\Controllers\Api\V1\Amial\AgentStatsController;
use App\Http\Controllers\Api\V1\Amial\BillPayController;
use App\Http\Controllers\Api\V1\Amial\DonationsController;
use App\Http\Controllers\Api\V1\Amial\FamilyFundController;
use App\Http\Controllers\Api\V1\Amial\LegalController;
use App\Http\Controllers\Api\V1\Amial\MerchantController;
use App\Http\Controllers\Api\V1\Amial\PendingTransferController;
use App\Http\Controllers\Api\V1\Amial\PolicyController;
use App\Http\Controllers\Api\V1\Amial\ReportController;
use App\Http\Controllers\Api\V1\Amial\RecipientVerificationController;
use App\Http\Controllers\Api\V1\Amial\ReceiptController;
use App\Http\Controllers\Api\V1\Amial\SafePaymentController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-ZONE-001 + AMIAL-LEGAL-001 + AMIAL-RECOVERY-001
 *
 * Routes جديدة لـ Amial Pay. تُدرج من RouteServiceProvider:
 *
 *   Route::prefix('api/v1/amial')
 *       ->middleware('api')
 *       ->group(base_path('routes/api/amial.php'));
 *
 * أو في bootstrap/app.php عبر withRouting (Laravel 11).
 *
 * هذا الملف فقط الـ amial endpoints. الـ admin endpoints يجب دمجها في routes/admin.php
 * كما هو موضح أدناه في تعليق التعليمات.
 */

// ============================================================
// PUBLIC / UNAUTHENTICATED
// ============================================================

// (لا شيء عام في v0.7 — كل endpoint يحتاج مصادقة على الأقل)

// P0-MONITORING — ping عام لخدمات المراقبة الخارجية (UptimeRobot)
Route::get('/ping', [\App\Http\Controllers\Api\V1\Amial\HealthController::class, 'ping'])
    ->name('amial.ping');

// AMIAL-SETTINGS-CENTER-001 — بيانات التواصل/الدعم (عام، يقرأه التطبيق)
Route::get('/support-contact', [\App\Http\Controllers\Api\V1\Amial\AdminSettingsController::class, 'publicContact'])
    ->name('amial.support-contact');

// AMIAL-OPS-001 — إصدار التطبيق (عام؛ لفرض التحديث عند الإقلاع)
Route::get('/app-version', [\App\Http\Controllers\Api\V1\Amial\AdminOpsController::class, 'publicAppVersion'])
    ->name('amial.app-version');

// AMIAL-GEO-ZONE-001 — إحداثيات الجهاز ← اسم المحافظة (عام: يُستدعى أثناء
// التسجيل قبل وجود حساب). محدود المعدّل لأنه بلا مصادقة.
Route::post('/geo/resolve-zone', [\App\Http\Controllers\Api\V1\Amial\GeoZoneController::class, 'resolve'])
    ->middleware('throttle:30,1')
    ->name('amial.geo.resolve-zone');

// AMIAL-GOVERNORATES-001 — جدول المحافظات للقائمة المنسدلة في التسجيل
Route::get('/geo/governorates', [\App\Http\Controllers\Api\V1\Amial\GeoZoneController::class, 'governorates'])
    ->name('amial.geo.governorates');

// P0-LEGAL — Markdown docs للموقع العام (بدون auth)
Route::prefix('legal-docs')->name('amial.legal-docs.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\V1\Amial\PublicLegalController::class, 'index'])->name('index');
    Route::get('/{slug}', [\App\Http\Controllers\Api\V1\Amial\PublicLegalController::class, 'show'])
        ->where('slug', '[a-z_-]+')->name('show');
});

// ============================================================
// AUTHENTICATED USER
// ============================================================

// AMIAL-POS-DEVICES-003 — **البوّابةُ على السطح كلِّه لا على مسارات التاجر
// وحدَها.** ولو قُصرت على `merchant/*` لكان الالتفافُ سطراً واحداً: يُستعمل
// رمزُ نقطة البيع على مسارٍ غيرِ محروسٍ فيعمل بلا مقعد. **والحدُّ يُفرض حيث
// يصل الرمزُ، لا حيث نتوقّع أن يذهب.**
//
// وهي صامتةٌ لمن لا مقعدَ له ولا هو موظّفُ نقطة بيع — فالعميلُ والتاجرُ
// يمرّان بلا أثر.
Route::middleware(['auth:api', 'amial.pos-device'])->group(function () {

    // -------- AMIAL-PIN-GATE-001: تحقّق رمز المعاملات (بوّابة بعد الدخول
    // وقبل العمليات المالية في التطبيق) --------
    Route::post('/verify-pin', function (\Illuminate\Http\Request $request) {
        $request->validate(['pin' => 'required|string|min:4|max:10']);
        $ok = \App\CentralLogics\Helpers::pin_check($request->user()->id, (string) $request->pin);
        return new \Illuminate\Http\JsonResponse([
            'success' => $ok,
            'code' => $ok ? 'PIN_OK' : 'PIN_INVALID',
            'message' => $ok ? 'تم التحقق' : 'رمز PIN غير صحيح',
            'errors' => (object) [],
            'meta' => (object) [],
        ], $ok ? 200 : 403);
    })->middleware('amial.rate-limit:verify_pin,10,1')->name('amial.verify-pin');

    // -------- Zone Policy --------
    Route::get('/policy/session', [PolicyController::class, 'session'])
        ->name('amial.policy.session');

    // -------- Notifications (AMIAL-NOTIFICATIONS-001) --------
    Route::prefix('notifications')->name('amial.notifications.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\Amial\NotificationController::class, 'index'])->name('index');
        Route::get('/unread-count', [\App\Http\Controllers\Api\V1\Amial\NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::post('/read-all', [\App\Http\Controllers\Api\V1\Amial\NotificationController::class, 'markAllRead'])->name('read-all');
        Route::post('/{id}/read', [\App\Http\Controllers\Api\V1\Amial\NotificationController::class, 'markRead'])
            ->where('id', '[0-9]+')->name('read');
    });

    // -------- Me — بيانات المستخدم الحالي (AMIAL-ME-001) --------
    Route::prefix('me')->name('amial.me.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\Amial\MeController::class, 'show'])->name('show');
        Route::get('/account-number', [\App\Http\Controllers\Api\V1\Amial\MeController::class, 'accountNumber'])->name('account-number');

        // CRITICAL-001 — Access endpoint (يقرأه AccessController في Flutter عند الدخول)
        Route::get('/access', [\App\Http\Controllers\Api\V1\Amial\AccessController::class, 'me'])->name('access');

        // AMIAL-ENTITLEMENTS-001 — **ملفّ خدمات التاجر**.
        //
        // نداءٌ واحدٌ يردّ كلَّ قدرات المنصّة بحالة كلٍّ لهذا الحساب:
        // متاحة · مقفلة بالباقة (وسعرُ فتحها) · مقفلة بالدور (ومن يمنحها)
        // · بلغتْ حدَّها (وبالرقمين). ومنه تُرسم شاشةُ «خدماتي».
        $EC = \App\Http\Controllers\Api\V1\Amial\EntitlementController::class;
        Route::get('/entitlements', [$EC, 'me'])->name('entitlements');
        Route::get('/entitlements/{code}', [$EC, 'show'])
            ->where('code', '[a-z0-9_.]+')->name('entitlements.show');

        // AMIAL-KYC-DOCS-001 — الطرف الناقص: مكانٌ يرفع فيه العميل هويّته.
        //
        // كان زرّ «طلب تحديث الهوية» في لوحة الدعم يضع علامةً على المستخدم
        // ثم لا مكان يرفع إليه — الزرّ يعمل والعميل ينتظر ما لن يأتي.
        Route::post('/kyc/documents', [\App\Http\Controllers\Api\V1\Amial\KycDocumentController::class, 'upload'])->name('kyc.upload');
        Route::get('/kyc/documents', [\App\Http\Controllers\Api\V1\Amial\KycDocumentController::class, 'mine'])->name('kyc.mine');
    });

    // AMIAL-BARCODE-001 — البحث السريع بالباركود (للـ continuous scanner)
    // AMIAL-CATALOG-001 — الكتالوج المشترك: يملأ الحقول عند إنشاء صنف.
    // وهو غيرُ `barcode/lookup` أدناه: ذاك يبحث في منتجات التاجر للبيع،
    // وهذا في الكتالوج العامّ للإدخال. شاشتان ونداءان.
    Route::get('/catalog/lookup', [\App\Http\Controllers\Api\V1\Amial\ProductCatalogController::class, 'lookup'])
        ->middleware('amial.rate-limit:catalog_lookup,120,1')->name('amial.catalog.lookup');

    Route::get('/barcode/lookup', [\App\Http\Controllers\Api\V1\Amial\BarcodeLookupController::class, 'lookup'])
        ->middleware('amial.rate-limit:barcode_lookup,600,1') // 600/دقيقة (مسح سريع)
        ->name('amial.barcode.lookup');

    // CRITICAL-001-PLANS — كتالوج الخطط (شاشة الترقية)
    // **جدول المقارنة مولَّدٌ من السجلّ** — وصفحةُ تسعيرٍ مكتوبةٌ بيدٍ
    // تعِد بما لا يفتحه النظام.
    Route::get('/plans/capabilities', [\App\Http\Controllers\Api\V1\Amial\EntitlementController::class, 'plans'])
        ->name('plans.capabilities');
    Route::get('/plans', [\App\Http\Controllers\Api\V1\Amial\AccessController::class, 'plansCatalog'])
        ->name('amial.plans.catalog');

    // CRITICAL-001-USAGE — snapshot استخدامي
    Route::get('/usage', [\App\Http\Controllers\Api\V1\Amial\AccessController::class, 'myUsage'])
        ->name('amial.usage.snapshot');

    // CRITICAL-001 — التاجر يختار نوع نشاطه
    Route::put('/merchant/business-type', [\App\Http\Controllers\Api\V1\Amial\AccessController::class, 'updateMyBusinessType'])
        ->name('amial.merchant.business-type');

    // CRITICAL-001 — Admin: إدارة الخطط + Business Types + Extra Features
    Route::prefix('admin')->name('amial.admin.')->group(function () {
        Route::get('/access-catalog', [\App\Http\Controllers\Api\V1\Amial\AccessController::class, 'adminCatalog'])->name('catalog');
        Route::post('/merchants/{id}/plan', [\App\Http\Controllers\Api\V1\Amial\AccessController::class, 'adminUpdatePlan'])
            ->where('id', '[0-9]+')->name('merchants.plan');
        Route::post('/merchants/{id}/business-type', [\App\Http\Controllers\Api\V1\Amial\AccessController::class, 'adminUpdateBusinessType'])
            ->where('id', '[0-9]+')->name('merchants.business-type');
        Route::post('/merchants/{id}/extra-feature', [\App\Http\Controllers\Api\V1\Amial\AccessController::class, 'adminAddExtraFeature'])
            ->where('id', '[0-9]+')->name('merchants.extra-feature');

        // P0-MONITORING — Health Check للأدمن
        Route::get('/health', [\App\Http\Controllers\Api\V1\Amial\HealthController::class, 'full'])->name('health');

        // AMIAL-OPS-CONSOLE-001 — منصة عمليات الموظفين (Customer Operations Console)
        Route::prefix('support')->name('support.')->group(function () {
            $c = \App\Http\Controllers\Api\V1\Amial\SupportConsoleController::class;
            Route::get('/search', [$c, 'search'])->middleware('platform:platform.customers.view')->name('search');
            Route::get('/ops-dashboard', [$c, 'opsDashboard'])->middleware('platform:platform.ops.view')->name('ops-dashboard');
            Route::get('/customers/{id}', [$c, 'customer'])->where('id', '[0-9]+')->middleware('platform:platform.customers.view')->name('customers.show');
            Route::get('/customers/{id}/transactions', [$c, 'customerTransactions'])->where('id', '[0-9]+')->middleware('platform:platform.transactions.view')->name('customers.transactions');
            Route::get('/transactions/{ref}', [$c, 'transaction'])->middleware('platform:platform.transactions.view')->name('transactions.show');
            // إجراءات التحقّق — كلها تتطلب سبباً وتُسجَّل في التدقيق
            Route::post('/customers/{id}/freeze', [$c, 'freeze'])->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('customers.freeze');
            Route::post('/customers/{id}/reset-pin', [$c, 'resetPin'])->where('id', '[0-9]+')->middleware('platform:platform.customers.reset_pin')->name('customers.reset-pin');
            Route::post('/customers/{id}/revoke-sessions', [$c, 'revokeSessions'])->where('id', '[0-9]+')->middleware('platform:platform.customers.sessions')->name('customers.revoke-sessions');
            Route::post('/customers/{id}/require-kyc', [$c, 'requireKyc'])->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('customers.require-kyc');
            // AMIAL-KYC-DOCS-001 — مراجعة مستندات الهوية.
            // مراجعة الوثائق قرار امتثال، ولا تُخلط مع صلاحية تجميد الحساب.
            Route::get('/kyc/queue', [\App\Http\Controllers\Api\V1\Amial\KycDocumentController::class, 'queue'])->middleware('platform:platform.approvals.decide')->name('kyc.queue');
            Route::get('/kyc/documents/{id}/file', [\App\Http\Controllers\Api\V1\Amial\KycDocumentController::class, 'file'])->where('id', '[0-9]+')->middleware('platform:platform.approvals.decide')->name('kyc.file');
            Route::post('/kyc/documents/{id}/approve', [\App\Http\Controllers\Api\V1\Amial\KycDocumentController::class, 'approve'])->where('id', '[0-9]+')->middleware('platform:platform.approvals.decide')->name('kyc.approve');
            Route::post('/kyc/documents/{id}/reject', [\App\Http\Controllers\Api\V1\Amial\KycDocumentController::class, 'reject'])->where('id', '[0-9]+')->middleware('platform:platform.approvals.decide')->name('kyc.reject');

            // AMIAL-DEVICE-TRUST-001 — نفس مسارات الويب بنفس الصلاحية.
            // تحصينُ سطحٍ واحد يترك الآخر باباً مفتوحاً، وقد وقع ذلك في هذا
            // المشروع من قبل: حُصّنت مسارات الويب وبقي توأمها في الـ API.
            Route::get('/customers/{id}/devices', [$c, 'devices'])->where('id', '[0-9]+')->middleware('platform:platform.customers.sessions')->name('customers.devices');
            Route::post('/devices/{deviceRowId}/block', [$c, 'blockDevice'])->where('deviceRowId', '[0-9]+')->middleware('platform:platform.customers.sessions')->name('devices.block');
            Route::post('/devices/{deviceRowId}/unblock', [$c, 'unblockDevice'])->where('deviceRowId', '[0-9]+')->middleware('platform:platform.customers.sessions')->name('devices.unblock');
            // تذاكر النزاعات
            Route::get('/tickets', [$c, 'tickets'])->middleware('platform:platform.tickets.manage')->name('tickets.index');
            Route::post('/tickets', [$c, 'createTicket'])->middleware('platform:platform.tickets.manage')->name('tickets.create');
            Route::get('/tickets/{id}', [$c, 'showTicket'])->where('id', '[0-9]+')->middleware('platform:platform.tickets.manage')->name('tickets.show');
            Route::post('/tickets/{id}/update', [$c, 'updateTicket'])->where('id', '[0-9]+')->middleware('platform:platform.tickets.manage')->name('tickets.update');
            Route::post('/tickets/{id}/note', [$c, 'addTicketNote'])->where('id', '[0-9]+')->middleware('platform:platform.tickets.manage')->name('tickets.note');
            // AMIAL-INSIDER-001: Maker-Checker + مراقبة الموظفين
            Route::get('/approvals', [$c, 'approvalsList'])->middleware('platform:platform.approvals.decide')->name('approvals.index');
            Route::post('/approvals/{id}/approve', [$c, 'approveRequest'])->where('id', '[0-9]+')->middleware('platform:platform.approvals.decide')->name('approvals.approve');
            Route::post('/approvals/{id}/reject', [$c, 'rejectRequest'])->where('id', '[0-9]+')->middleware('platform:platform.approvals.decide')->name('approvals.reject');
            Route::get('/insider/overview', [$c, 'insiderOverview'])->middleware('platform:platform.audit.view')->name('insider.overview');
            Route::post('/insider/alerts/{id}/ack', [$c, 'acknowledgeAlert'])->where('id', '[0-9]+')->middleware('platform:platform.approvals.decide')->name('insider.alerts.ack');
        });

        // AMIAL-MAINT-001 — لوحة «الصيانة الأولية» (تشغيل/إيقاف الميزات)
        Route::prefix('maintenance')->name('maintenance.')->group(function () {
            $mc = \App\Http\Controllers\Api\V1\Amial\MaintenanceController::class;
            Route::get('/list', [$mc, 'index'])->name('list');
            Route::post('/{key}/enable', [$mc, 'enable'])->name('enable');
            Route::post('/{key}/disable', [$mc, 'disable'])->name('disable');
        });

        // CRITICAL-001-SUBS — إدارة الاشتراكات + Audit Log
        // USE-001 — Revenue Settlement Center
        Route::prefix('settlements')->name('settlements.')->middleware('amial.idempotency')->group(function () {
            Route::get('/dashboard',  [\App\Http\Controllers\Api\V1\Amial\SettlementController::class, 'dashboard'])->name('dashboard');
            Route::get('/partners',   [\App\Http\Controllers\Api\V1\Amial\SettlementController::class, 'partners'])->name('partners');
            Route::get('/wallet',     [\App\Http\Controllers\Api\V1\Amial\SettlementController::class, 'revenueWallet'])->name('wallet');
            Route::get('/',           [\App\Http\Controllers\Api\V1\Amial\SettlementController::class, 'index'])->name('index');
            Route::post('/',          [\App\Http\Controllers\Api\V1\Amial\SettlementController::class, 'store'])->name('store');
            Route::get('/{id}',       [\App\Http\Controllers\Api\V1\Amial\SettlementController::class, 'show'])->where('id','[0-9]+')->name('show');
            Route::post('/{id}/submit',   [\App\Http\Controllers\Api\V1\Amial\SettlementController::class, 'submit'])->where('id','[0-9]+')->name('submit');
            Route::post('/{id}/approve',  [\App\Http\Controllers\Api\V1\Amial\SettlementController::class, 'approve'])->where('id','[0-9]+')->name('approve');
            Route::post('/{id}/reject',   [\App\Http\Controllers\Api\V1\Amial\SettlementController::class, 'reject'])->where('id','[0-9]+')->name('reject');
            Route::post('/{id}/process',  [\App\Http\Controllers\Api\V1\Amial\SettlementController::class, 'process'])->where('id','[0-9]+')->name('process');
            Route::post('/{id}/complete', [\App\Http\Controllers\Api\V1\Amial\SettlementController::class, 'complete'])->where('id','[0-9]+')->name('complete');
            Route::post('/{id}/cancel',   [\App\Http\Controllers\Api\V1\Amial\SettlementController::class, 'cancel'])->where('id','[0-9]+')->name('cancel');
        });

        Route::prefix('subscriptions')->name('subscriptions.')->group(function () {
            Route::get('/summary', [\App\Http\Controllers\Api\V1\Amial\SubscriptionController::class, 'summary'])->name('summary');
            Route::get('/expiring', [\App\Http\Controllers\Api\V1\Amial\SubscriptionController::class, 'expiring'])->name('expiring');
            Route::get('/log', [\App\Http\Controllers\Api\V1\Amial\SubscriptionController::class, 'log'])->name('log');
            Route::post('/process-expired', [\App\Http\Controllers\Api\V1\Amial\SubscriptionController::class, 'processExpired'])->name('process-expired');
            Route::get('/merchant/{id}/history', [\App\Http\Controllers\Api\V1\Amial\SubscriptionController::class, 'merchantHistory'])
                ->where('id', '[0-9]+')->name('merchant.history');
            Route::post('/merchant/{id}/renew', [\App\Http\Controllers\Api\V1\Amial\SubscriptionController::class, 'renew'])
                ->where('id', '[0-9]+')->name('merchant.renew');
            Route::post('/merchant/{id}/extend', [\App\Http\Controllers\Api\V1\Amial\SubscriptionController::class, 'extend'])
                ->where('id', '[0-9]+')->name('merchant.extend');
        });

        // CRITICAL-001-ADMIN — لوحة الإدارة الموحّدة
        Route::get('/dashboard', [\App\Http\Controllers\Api\V1\Amial\AdminPanelController::class, 'dashboard'])->name('dashboard');
        Route::get('/merchants', [\App\Http\Controllers\Api\V1\Amial\AdminPanelController::class, 'listMerchants'])->name('merchants.list');
        Route::get('/variances/pending', [\App\Http\Controllers\Api\V1\Amial\AdminPanelController::class, 'pendingVariances'])->name('variances.pending');
        // AMIAL-ADMIN-DOORS-002 — حسمُ فرقٍ ماليٍّ قرارُ مال، وتوثيقُ
        // تاجرٍ قرارُ اعتماد. وكانا بلا صلاحيّة.
        Route::post('/variances/{id}/resolve', [\App\Http\Controllers\Api\V1\Amial\AdminPanelController::class, 'resolveVariance'])
            ->where('id', '[0-9]+')
            ->middleware('platform:platform.settlements.decide')->name('variances.resolve');
        Route::post('/merchants/{id}/verify', [\App\Http\Controllers\Api\V1\Amial\AdminPanelController::class, 'verifyMerchant'])
            ->where('id', '[0-9]+')
            ->middleware('platform:platform.approvals.decide')->name('merchants.verify');

        // AMIAL-WHATSAPP-OTP-001 — إدارة قناة واتساب (مزوّدون + تفضيل + إرسال تجريبي)
        Route::prefix('whatsapp')->name('whatsapp.')->group(function () {
            Route::get('/config', [\App\Http\Controllers\Api\V1\Amial\WhatsappAdminController::class, 'getConfig'])->name('config');
            Route::post('/provider', [\App\Http\Controllers\Api\V1\Amial\WhatsappAdminController::class, 'saveProvider'])->name('provider');
            Route::post('/channel', [\App\Http\Controllers\Api\V1\Amial\WhatsappAdminController::class, 'setChannel'])->name('channel');
            Route::post('/test', [\App\Http\Controllers\Api\V1\Amial\WhatsappAdminController::class, 'testSend'])->name('test');
        });

        // AMIAL-SETTINGS-CENTER-001 — مركز الإعدادات الموحّد (SMS/إشعارات/تواصل/رسوم)
        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('/sms', [\App\Http\Controllers\Api\V1\Amial\AdminSettingsController::class, 'getSms'])->name('sms');
            Route::post('/sms/provider', [\App\Http\Controllers\Api\V1\Amial\AdminSettingsController::class, 'saveSmsProvider'])->name('sms.provider');
            Route::get('/notifications', [\App\Http\Controllers\Api\V1\Amial\AdminSettingsController::class, 'getNotifications'])->name('notifications');
            Route::post('/notifications', [\App\Http\Controllers\Api\V1\Amial\AdminSettingsController::class, 'saveNotifications'])->name('notifications.save');
            Route::get('/contact', [\App\Http\Controllers\Api\V1\Amial\AdminSettingsController::class, 'getContact'])->name('contact');
            Route::post('/contact', [\App\Http\Controllers\Api\V1\Amial\AdminSettingsController::class, 'saveContact'])->name('contact.save');
            Route::get('/fees', [\App\Http\Controllers\Api\V1\Amial\AdminSettingsController::class, 'getFees'])->name('fees');
            Route::post('/fees', [\App\Http\Controllers\Api\V1\Amial\AdminSettingsController::class, 'createFee'])->name('fees.create');
            Route::post('/fees/simulate', [\App\Http\Controllers\Api\V1\Amial\AdminSettingsController::class, 'simulateFee'])->name('fees.simulate');
            Route::post('/fees/{id}/deactivate', [\App\Http\Controllers\Api\V1\Amial\AdminSettingsController::class, 'deactivateFee'])
                ->where('id', '[0-9]+')->name('fees.deactivate');
        });

        // AMIAL-OPS-001 — التشغيل (صيانة/كاش/إصدار)
        Route::prefix('ops')->name('ops.')->group(function () {
            Route::get('/status', [\App\Http\Controllers\Api\V1\Amial\AdminOpsController::class, 'status'])->name('status');
            Route::post('/maintenance', [\App\Http\Controllers\Api\V1\Amial\AdminOpsController::class, 'setMaintenance'])->name('maintenance');
            Route::post('/clear-cache', [\App\Http\Controllers\Api\V1\Amial\AdminOpsController::class, 'clearCache'])->name('clear-cache');
            Route::post('/app-version', [\App\Http\Controllers\Api\V1\Amial\AdminOpsController::class, 'setAppVersion'])->name('app-version');
        });

        // AMIAL-ADMIN-AGENT-CREDIT-001 — الإدارة تُموّل الوكلاء وتعتمد تسوياتهم
        Route::prefix('agent-settlements')->name('agent-settlements.')->middleware('amial.idempotency')->group(function () {
            Route::get('/', [AgentNetworkController::class, 'adminSettlements'])->name('index');
            Route::post('/{ulid}/approve', [AgentNetworkController::class, 'adminApproveSettlement'])
                ->where('ulid', '[0-9A-Za-z]{26}')->name('approve');
            Route::post('/{ulid}/reject', [AgentNetworkController::class, 'adminRejectSettlement'])
                ->where('ulid', '[0-9A-Za-z]{26}')->name('reject');
        });
        Route::post('/agents/{id}/credit', [AgentNetworkController::class, 'adminCreditAgent'])
            ->where('id', '[0-9]+')->middleware('amial.idempotency')->name('agents.credit');
    });

    // -------- Payment Requests (AMIAL-PAYMENT-REQUESTS-001) --------
    Route::prefix('payment-requests')->name('amial.payment-requests.')->middleware('amial.idempotency')->group(function () {
        // طلبُ عميلٍ مباشراً: لا رابطَ ولا QR، ومستلمٌ مؤكّد إلزامي.
        Route::post('/direct', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'createDirect'])
            ->middleware([
                'amial.zone:request_money',
                'amial.rate-limit:payment_request_create,30,1',
            ])->name('create-direct');
        Route::post('/', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'create'])
            ->middleware('amial.rate-limit:payment_request_create,30,1')->name('create');
        Route::get('/', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'list'])->name('list');
        // AMIAL-REQUEST-DIRECT-002 — «أهذا الرقم مشترك؟» تُنادى أثناء
        // الكتابة، فتقول الشاشةُ قبل الإرسال أيصل الطلبُ أم يبقى رابطاً.
        // قراءةٌ لا حركة ⇒ بلا مفتاح تفرّد، وبحدٍّ لأنّها تُنادى بكلّ حرف.
        Route::post('/check-recipient', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'checkRecipient'])
            ->withoutMiddleware('amial.idempotency')
            ->middleware('amial.rate-limit:request_check_recipient,60,1')->name('check-recipient');
        Route::get('/code/{code}', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'showByCode'])
            ->where('code', '[A-Z0-9]{6,8}')->name('show-by-code');
        // AMIAL-MERCHANT-PAY-002 — الدفعُ برقم حساب التاجر ورقم الفاتورة،
        // لمن لا تعمل كاميرتُه. قراءةٌ لا حركة ⇒ بلا مفتاح تفرّد.
        Route::post('/invoice/lookup', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'lookupInvoice'])
            ->withoutMiddleware('amial.idempotency')
            ->middleware('amial.rate-limit:invoice_lookup,60,1')->name('invoice.lookup');
        Route::post('/code/{code}/pay', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'pay'])
            ->where('code', '[A-Z0-9]{6,8}')
            ->middleware('amial.rate-limit:payment_request_pay,30,1')->name('pay');
        Route::post('/{id}/cancel', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'cancel'])
            ->where('id', '[0-9]+')->name('cancel');

        // AMIAL-REQUEST-DIRECT-001 — الطرفان الناقصان في «يوافق أو يرفض».
        //
        // كان الدفع لا يُمكن إلّا بالرمز القصير — وهو مسار من وصله رابط. أمّا
        // من وصله الطلبُ في قائمته فلا يملك رمزاً يكتبه. ولا رفضَ إطلاقاً:
        // من لا يريد الطلب يتجاهله حتى تنتهي صلاحيته، والطالب ينتظر أسبوعاً.
        Route::post('/{id}/decline', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'decline'])
            ->where('id', '[0-9]+')->name('decline');
        Route::post('/{id}/pay', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'payById'])
            ->where('id', '[0-9]+')
            ->middleware([
                'amial.zone:send_money',
                'amial.rate-limit:payment_request_pay,30,1',
            ])->name('pay-by-id');
    });

    // -------- Legal Terms --------
    // مهم: هذه خارج middleware 'amial.terms' لتجنب deadlock
    Route::prefix('legal')->name('amial.legal.')->group(function () {
        Route::get('/status', [LegalController::class, 'status'])->name('status');
        Route::get('/current', [LegalController::class, 'current'])->name('current');
        Route::post('/accept', [LegalController::class, 'accept'])->name('accept');
    });

    // -------- Account Recovery --------
    //
    // ══════════════════════════════════════════════════════════════════
    // AMIAL-PROD-READINESS-004 — **استعادةُ الحساب كانت بلا سقفٍ خاصّ.**
    //
    // قِيس في تدقيق الجاهزيّة: هذه المساراتُ تحمل سقفَ المجموعة العامّ
    // وحدَه (‏٦٠/دقيقة)، بينما `auth_login` عليه ١٠ و`verify_pin` عليه ١٠.
    //
    // **وهي بابُ استيلاءٍ على حساب**: ستّون محاولةً في الدقيقة على رمزٍ
    // من ستّ خاناتٍ تستنفده في ساعاتٍ من عنوانٍ واحد — ثمّ يُبدَّل رقمُ
    // الهاتف ويُملَك الحساب بما فيه.
    //
    // والسقوفُ بحسب الخطر لا بالتساوي:
    //   · التحقّقُ من الرمز — الأخطر — ٥/دقيقة كـ`check-otp` في التسجيل.
    //   · بدءُ الاستعادة يُرسل رسالةً مدفوعة ⇒ ٥/دقيقة.
    //   · الإتمامُ يغيّر الهاتفَ فعلاً ⇒ ١٠/دقيقة.
    // ══════════════════════════════════════════════════════════════════
    Route::prefix('recovery')->name('amial.recovery.')->group(function () {
        Route::post('/initiate-self', [AccountRecoveryController::class, 'initiateSelf'])
            ->middleware('amial.rate-limit:recovery_initiate,5,1')
            ->name('initiate-self');

        Route::post('/initiate-lost', [AccountRecoveryController::class, 'initiateLost'])
            ->middleware('amial.rate-limit:recovery_initiate,5,1')
            ->name('initiate-lost');

        Route::post('/{ulid}/verify-otp', [AccountRecoveryController::class, 'verifyOtp'])
            ->middleware('amial.rate-limit:recovery_verify,5,1')
            ->where('ulid', '[A-Z0-9]{26}')
            ->name('verify-otp');

        Route::post('/{ulid}/complete', [AccountRecoveryController::class, 'complete'])
            ->middleware('amial.rate-limit:recovery_complete,10,1')
            ->where('ulid', '[A-Z0-9]{26}')
            ->name('complete');

        Route::get('/{ulid}', [AccountRecoveryController::class, 'show'])
            ->where('ulid', '[A-Z0-9]{26}')
            ->name('show');
    });

    // ==========================================================
    // AMIAL v0.9 endpoints
    // ==========================================================

    // -------- AMIAL-RECEIPTS-001 (v0.9-A) --------
    // AMIAL-STATEMENT-001 — كشف حساب المحفظة (بيان محاسبي لفترة).
    // مختلف عن الإيصالات: الإيصال مستند عملية واحدة، والكشف كل الحركات
    // بعمودَي مدين ودائن ورصيد جارٍ — وهو ما يُطلب لإثبات الدخل أو تسوية خلاف.
    Route::prefix('statement')->name('amial.statement.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\Amial\AccountStatementController::class, 'index'])
            ->name('index');
        Route::get('/pdf', [\App\Http\Controllers\Api\V1\Amial\AccountStatementController::class, 'pdf'])
            ->middleware('amial.rate-limit:statement_pdf,10,1')->name('pdf');
    });

    Route::prefix('receipts')->name('amial.receipts.')->group(function () {
        Route::get('/', [ReceiptController::class, 'index'])->name('index');

        // AMIAL-RECIPIENTS-001 — مستلمو «تحويل سريع» مشتقّين من السجلّ.
        // **قبل `/{id}`** — وإلّا ابتلعه القيد الرقميّ… لا، بل يمرّ لأنّ
        // `{id}` مقيّدٌ بـ[0-9]+، لكنّ الترتيب يبقى الأوضح للقارئ.
        Route::get('/recent-recipients', [ReceiptController::class, 'recentRecipients'])
            ->name('recent-recipients');

        Route::get('/{id}', [ReceiptController::class, 'show'])
            ->where('id', '[0-9]+')->name('show');
        Route::get('/{id}/download', [ReceiptController::class, 'download'])
            ->where('id', '[0-9]+')->name('download');
        // AMIAL-THERMAL-001: إيصال حراري 58/80مم لطابعات POS
        Route::get('/{id}/thermal', [ReceiptController::class, 'thermal'])
            ->where('id', '[0-9]+')->name('thermal');
        // AMIAL-INVOICE-A4-001: فاتورة رسمية A4
        Route::get('/{id}/invoice', [ReceiptController::class, 'invoice'])
            ->where('id', '[0-9]+')->name('invoice');
        Route::post('/{id}/print-event', [ReceiptController::class, 'recordPrint'])
            ->middleware('amial.rate-limit:receipt_print,30,1')
            ->where('id', '[0-9]+')->name('print-event');
    });

    // -------- AMIAL-FUND-FAMILY-001 (v0.9-B) --------
    Route::prefix('funds')->name('amial.funds.')->middleware('amial.idempotency')->group(function () {
        Route::get('/', [FamilyFundController::class, 'index'])->name('index');
        Route::post('/', [FamilyFundController::class, 'create'])->name('create');

        Route::get('/{ulid}', [FamilyFundController::class, 'show'])
            ->where('ulid', '[A-Z0-9]{26}')->name('show');

        Route::post('/{ulid}/invite', [FamilyFundController::class, 'invite'])
            ->where('ulid', '[A-Z0-9]{26}')->name('invite');

        Route::post('/{ulid}/contribute', [FamilyFundController::class, 'contribute'])
            ->where('ulid', '[A-Z0-9]{26}')
            ->middleware('amial.zone:family_fund_contribute')
            ->name('contribute');

        Route::post('/{ulid}/propose-disbursement', [FamilyFundController::class, 'proposeDisbursement'])
            ->where('ulid', '[A-Z0-9]{26}')
            ->middleware('amial.zone:family_fund_disburse')
            ->name('propose-disbursement');

        Route::get('/{ulid}/transactions', [FamilyFundController::class, 'transactions'])
            ->where('ulid', '[A-Z0-9]{26}')->name('transactions');

        Route::post('/disbursements/{ulid}/approve', [FamilyFundController::class, 'approveDisbursement'])
            ->where('ulid', '[A-Z0-9]{26}')->name('approve-disbursement');

        Route::post('/disbursements/{ulid}/reject', [FamilyFundController::class, 'rejectDisbursement'])
            ->where('ulid', '[A-Z0-9]{26}')->name('reject-disbursement');

        Route::post('/memberships/{id}/accept', [FamilyFundController::class, 'acceptInvite'])
            ->where('id', '[0-9]+')->name('accept-invite');
    });

    // -------- AMIAL-BILL-PAY-001 (v0.9-C) --------
    Route::prefix('bill-pay')->name('amial.bill-pay.')->middleware('amial.idempotency')->group(function () {
        Route::get('/providers', [BillPayController::class, 'listProviders'])->name('providers');
        Route::get('/services/{service_id}/products', [BillPayController::class, 'listProducts'])
            ->where('service_id', '[0-9]+')->name('products');
        Route::post('/pay', [BillPayController::class, 'pay'])
            ->middleware('amial.zone:pay_bill')
            ->name('pay');
        Route::get('/orders', [BillPayController::class, 'listOrders'])->name('orders');
        Route::get('/orders/{ulid}', [BillPayController::class, 'showOrder'])
            ->where('ulid', '[A-Z0-9]{26}')->name('order-show');
    });

    // -------- AMIAL-SAFE-PAYMENT-001 (v1.1) --------
    // AMIAL-MAINT-001: مثال حيّ — الدفع الآمن يُغلق من لوحة الصيانة (بحارس مالي)
    Route::prefix('safe-payments')->name('amial.safe-pay.')->middleware(['feature:safe_payment', 'amial.idempotency'])->group(function () {
        Route::get('/', [SafePaymentController::class, 'index'])->name('index');
        // أسباب النزاع من الخادم — إضافة سبب لا تستحقّ إصدار تطبيق
        Route::get('/dispute-reasons', [SafePaymentController::class, 'disputeReasons'])
            ->name('dispute-reasons');
        Route::post('/verify-seller', [SafePaymentController::class, 'verifySeller'])
            ->middleware('amial.rate-limit:safe_pay_verify_seller,10,1')
            ->name('verify-seller');
        Route::get('/evidence/{id}/file', [SafePaymentController::class, 'evidenceFile'])
            ->where('id', '[0-9]+')->name('evidence.file');
        Route::post('/', [SafePaymentController::class, 'create'])
            ->middleware(['amial.zone:safe_payment_create', 'amial.rate-limit:safe_pay_create,5,1'])
            ->name('create');

        Route::get('/{ulid}', [SafePaymentController::class, 'show'])
            ->where('ulid', '[A-Z0-9]{26}')->name('show');

        // AMIAL-SAFEPAY-EVIDENCE-001 — أدلّة حقيقية (ملفات) للطرفين معاً
        Route::post('/{ulid}/evidence', [SafePaymentController::class, 'uploadEvidence'])
            ->where('ulid', '[A-Z0-9]{26}')
            ->middleware('amial.rate-limit:safe_pay_evidence,20,1')->name('evidence.upload');
        Route::get('/{ulid}/evidence', [SafePaymentController::class, 'listEvidence'])
            ->where('ulid', '[A-Z0-9]{26}')->name('evidence.list');

        // AMIAL-SAFEPAY-CODE-001 — تأكيد التسليم برمز المشتري
        Route::post('/{ulid}/verify-delivery', [SafePaymentController::class, 'verifyDelivery'])
            ->where('ulid', '[A-Z0-9]{26}')
            ->middleware('amial.rate-limit:safe_pay_delivery,10,1')->name('verify-delivery');

        // Seller actions
        Route::post('/{ulid}/seller-accept', [SafePaymentController::class, 'sellerAccept'])
            ->where('ulid', '[A-Z0-9]{26}')
            ->middleware('amial.rate-limit:safe_pay_seller_response,10,1')->name('seller-accept');
        Route::post('/{ulid}/seller-reject', [SafePaymentController::class, 'sellerReject'])
            ->where('ulid', '[A-Z0-9]{26}')
            ->middleware('amial.rate-limit:safe_pay_seller_response,10,1')->name('seller-reject');
        Route::post('/{ulid}/seller-mark-in-delivery', [SafePaymentController::class, 'sellerMarkInDelivery'])
            ->where('ulid', '[A-Z0-9]{26}')->name('seller-in-delivery');
        Route::post('/{ulid}/seller-mark-delivered', [SafePaymentController::class, 'sellerMarkDelivered'])
            ->where('ulid', '[A-Z0-9]{26}')->name('seller-delivered');

        // Buyer actions
        // AMIAL-ZONE-GAP-001: كان الإنشاء محروساً بـ amial.zone والتحرير بلا
        // حارس — أي حُرس فتح الصندوق ولم يُحرس إخراج المال منه. buyer-confirm
        // هو ما ينقل المبلغ فعلاً إلى البائع.
        Route::post('/{ulid}/buyer-confirm', [SafePaymentController::class, 'buyerConfirm'])
            ->middleware(['amial.zone:safe_payment_release', 'amial.rate-limit:safe_pay_confirm,5,1'])
            ->where('ulid', '[A-Z0-9]{26}')->name('buyer-confirm');
        Route::post('/{ulid}/buyer-cancel', [SafePaymentController::class, 'buyerCancel'])
            ->where('ulid', '[A-Z0-9]{26}')
            ->middleware('amial.rate-limit:safe_pay_cancel,5,1')->name('buyer-cancel');
        Route::post('/{ulid}/buyer-dispute', [SafePaymentController::class, 'buyerDispute'])
            ->where('ulid', '[A-Z0-9]{26}')
            ->middleware('amial.rate-limit:safe_pay_dispute,5,1')->name('buyer-dispute');
    });

    // -------- AMIAL-RECIPIENT-VERIFY-001 (v2.6) --------
    Route::prefix('transfer')->name('amial.transfer.')->middleware('amial.idempotency')->group(function () {
        // AMIAL-PILOT-IDEM-002 — **استثناءٌ مُعلَن: هذه قراءةٌ لا حركة.**
        // مفتاحُ التفرّد يحفظ الردَّ أربعاً وعشرين ساعة ويُعيده. فلو شمل
        // هذه لأظهرت اسمَ مستلِمٍ قديماً بعد تغيّره — **حارسٌ يُنتج كذباً
        // بدل أن يمنع ضرراً.** والاستثناءُ يُكتب ولا يُترك صمتاً.
        Route::post('/verify-recipient', [RecipientVerificationController::class, 'verify'])
            ->middleware('amial.rate-limit:verify_recipient,30,1')
            ->withoutMiddleware('amial.idempotency')
            ->name('verify-recipient');

        // -------- AMIAL-TRANSFER-COOLDOWN-001 (v2.7) --------
        Route::post('/initiate', [PendingTransferController::class, 'initiate'])
            ->middleware(['amial.zone:send_money', 'amial.idempotency', 'amial.rate-limit:transfer_initiate,20,1'])
            ->name('initiate');
        Route::post('/{ulid}/cancel', [PendingTransferController::class, 'cancel'])
            ->name('cancel');
        Route::get('/{ulid}/status', [PendingTransferController::class, 'status'])
            ->name('status');
    });

    // -------- AMIAL-REPORTS-001 (v2.11) --------
    Route::prefix('reports')->name('amial.reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::post('/request', [ReportController::class, 'request'])
            ->middleware('amial.rate-limit:report_request,10,5')
            ->name('request');
        Route::get('/{ulid}/status', [ReportController::class, 'status'])->name('status');
        Route::get('/{ulid}/download', [ReportController::class, 'download'])->name('download');
    });

    // -------- AMIAL-MERCHANT-001 (v1.7) --------
    Route::prefix('merchant')->name('amial.merchant.')->middleware('amial.idempotency')->group(function () {
        // ══════════════════════════════════════════════════════════
        // AMIAL-POS-DEVICES-004 — **مقاعدُ أجهزة نقاط البيع.**
        //
        // `capability:multi_pos` يحمل `limit('max_pos_devices')`، فالحدُّ
        // يُقرأ من مصدرٍ واحد. **والقراءةُ خارج البوّابة عمداً**: تاجرٌ
        // بلغ حدَّه يجب أن **يرى أجهزتَه ليُلغي واحداً** — ولو حُرست
        // القائمةُ لصار الحدُّ بابَ سجنٍ لا بابَ ترقية: يُمنع من الدخول
        // ويُمنع من الإصلاح، ولا مخرجَ إلّا الدعم.
        Route::prefix('pos-devices')->name('pos-devices.')->group(function () {
            $c = \App\Http\Controllers\Api\V1\Amial\PosDeviceController::class;

            Route::get('/', [$c, 'index'])->name('index');

            // ══════════════════════════════════════════════════════
            // **والحدُّ يُفرَض في المُسجِّل لا في وسيطٍ عامّ.**
            //
            // `capability:multi_pos` يحمل `limit('max_pos_devices')`،
            // ووسيطُ القدرات يعرف «مشغولٌ ١ من ١» **ولا يعرف أيَّ جهازٍ
            // يطرق**. فكان يقع هذا — وقِيس بالتشغيل:
            //
            //   تاجرٌ بلغ حدَّه ⇒ جهازُه المسجَّلُ نفسُه يُعيد الاقتران
            //     ⇒ الوسيطُ يردّ ٤٠٢ قبل أن يُسأل «أهو جهازٌ جديد؟»
            //       ⇒ **جهازٌ يملك مقعدَه لا يستطيع استعمالَه**
            //
            // وهو نفسُ العطل الذي أُصلح داخل المُسجِّل («العودةُ بالبصمة
            // نفسِها لا تستهلك مقعداً») ثمّ أُعيد من باب المسار.
            //
            // **فالمُسجِّلُ وحدَه يفرض**: يعرف الهويّة، ويعدّ داخل معاملةٍ
            // بقفلٍ على صفّ التاجر، ويردّ ٤٠٢ بالصيغة نفسِها. وهو أمتنُ
            // من الوسيط لا أضعف: الوسيطُ يقرأ ثمّ يُمرّر — **وبين
            // القراءة والكتابة يمرّ طلبٌ آخر**.
            //
            // (‏ولا بوّابةَ باقةٍ هنا: `multi_pos` أدناها «مجّاني» لأنّ
            // جدولَ الحدود يبيع للمجّانيّ مقعداً واحداً.)
            Route::post('/', [$c, 'store'])
                ->middleware('amial.rate-limit:pos_device_register,10,1')
                ->name('store');
            Route::post('/pair', [$c, 'pair'])
                ->middleware('amial.rate-limit:pos_device_register,10,1')
                ->name('pair');

            // وتغييرُ الاسم لا يستهلك مقعداً — فلا يُسأل عن الحدّ أصلاً.
            // **PUT وPATCH كلاهما** — عميلُ فلاتر لا يملك PATCH، وإضافةُ
            // فعلٍ إلى العميل المشترك أوسعُ أثراً من قبولِ مرادفٍ هنا.
            // (‏ولولا هذا لكان زرُّ «تسمية» زرّاً ميّتاً: يُضغط فلا يصل طلب.)
            Route::match(['put', 'patch'], '/{id}', [$c, 'update'])
                ->where('id', '[0-9]+')->name('update');

            // **والإلغاءُ بلا حارسِ باقة** — من هبطت باقتُه يجب أن يستطيع
            // تقليصَ أجهزته إلى الحدّ الجديد. وحراستُه تحبسه فوق الحدّ
            // بلا سبيلٍ إلى النزول.
            Route::delete('/{id}', [$c, 'destroy'])
                ->where('id', '[0-9]+')->name('destroy');
        });

        // P1-BRANCHES — إدارة الفروع
        Route::prefix('branches')->name('branches.')
            ->middleware('capability:' . \App\Support\Access\AccessConstants::F_BRANCHES)
            ->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\Amial\BranchController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Api\V1\Amial\BranchController::class, 'store'])
                ->middleware('amial.rate-limit:branch_create,10,1')->name('store');
            Route::get('/{id}', [\App\Http\Controllers\Api\V1\Amial\BranchController::class, 'show'])
                ->where('id', '[0-9]+')->name('show');
            Route::put('/{id}', [\App\Http\Controllers\Api\V1\Amial\BranchController::class, 'update'])
                ->where('id', '[0-9]+')->name('update');
            Route::delete('/{id}', [\App\Http\Controllers\Api\V1\Amial\BranchController::class, 'destroy'])
                ->where('id', '[0-9]+')->name('destroy');
            Route::post('/{id}/default', [\App\Http\Controllers\Api\V1\Amial\BranchController::class, 'setDefault'])
                ->where('id', '[0-9]+')->name('default');
            // P1-BRANCHES — تقرير سريع لكل فرع (إجمالي البيع + عدد الفواتير)
            Route::get('/{id}/report', [\App\Http\Controllers\Api\V1\Amial\BranchController::class, 'report'])
                ->middleware('capability:branch_reports')
                ->where('id', '[0-9]+')->name('report');
        });

        // P1-RBAC — إدارة الأدوار والصلاحيات
        Route::prefix('rbac')->name('rbac.')->group(function () {
            Route::get('/permissions', [\App\Http\Controllers\Api\V1\Amial\RbacController::class, 'permissions'])->name('permissions');
            Route::get('/roles', [\App\Http\Controllers\Api\V1\Amial\RbacController::class, 'roles'])->name('roles');
            Route::get('/pos-users/{id}/roles', [\App\Http\Controllers\Api\V1\Amial\RbacController::class, 'posUserRoles'])
                ->where('id', '[0-9]+')->name('pos-user.roles');
            Route::post('/pos-users/{id}/assign-role', [\App\Http\Controllers\Api\V1\Amial\RbacController::class, 'assignRole'])
                ->where('id', '[0-9]+')->name('pos-user.assign');
            Route::post('/pos-users/{id}/revoke-role', [\App\Http\Controllers\Api\V1\Amial\RbacController::class, 'revokeRole'])
                ->where('id', '[0-9]+')->name('pos-user.revoke');
        });

        // AMIAL-MERCHANT-PAY-001 — دفع العميل للتاجر (QR/POS)
        Route::post('/quote', [\App\Http\Controllers\Api\V1\Amial\MerchantPaymentController::class, 'quote'])
            ->withoutMiddleware('amial.idempotency')
            ->name('quote');
        Route::post('/pay', [\App\Http\Controllers\Api\V1\Amial\MerchantPaymentController::class, 'pay'])
            ->middleware(['amial.zone:merchant_payment', 'amial.idempotency', 'amial.rate-limit:merchant_pay,30,1'])
            ->name('pay');

        // AMIAL-SPLIT-BILL-001 — التاجر/POS ينشئ ويعرض الفواتير المقسّمة
        Route::post('/split-bills', [\App\Http\Controllers\Api\V1\Amial\SplitBillController::class, 'create'])
            ->middleware('amial.rate-limit:split_create,20,1')->name('split-bills.create');
        Route::get('/split-bills/{ulid}', [\App\Http\Controllers\Api\V1\Amial\SplitBillController::class, 'show'])
            ->name('split-bills.show');

        // AMIAL-CASHIER-001 — كاشير التاجر (منتجات + بيع + تقرير)
        Route::prefix('cashier')->name('cashier.')->group(function () {
            Route::get('/products', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'products'])->name('products');
            Route::get('/products/lookup', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'lookupBarcode'])->name('products.lookup');
            // ══════════════════════════════════════════════════════════
            //  AMIAL-PRODUCT-QUOTA-001 — **حدُّ الأصناف يُفرَض عند بابه.**
            //
            //  **الثمن الذي دُفع:** قال صاحبُ المشروع: «لا تنسَ أنّ عدد
            //  المنتجات مرتبطٌ بالباقات». وقِيس فوُجد أنّ كلَّ شيءٍ مبنيٌّ
            //  **إلّا الفحص**:
            //
            //    · `PLAN_LIMITS` تقول: مجّانيّة ٠ · ناشئة ١٠٠ · نموّ ٣٠٠ ·
            //      ما فوقها بلا حدّ.
            //    · `EntitlementService::usageFor` تعدّ الأصناف فعلاً.
            //    · `evaluate()` تُرجع `LIMIT_REACHED` عند البلوغ.
            //    · `EnsureCapability` تردّ ٤٠٢ برسالةٍ فيها المستعمَل والحدّ
            //      وسعرُ الباقة التالية.
            //
            //  **وبابُ الإنشاء لا يمرّ بشيءٍ من ذلك.** فتاجرٌ في الباقة
            //  المجّانيّة يضيف ألفَ صنف، والباقاتُ المدفوعةُ لا تبيع شيئاً
            //  لأنّ المجّانيّة تعطي كلَّ شيء. محرّكٌ كاملٌ بلا مفتاح.
            //
            //  **وعلى الإنشاء وحده**: تاجرٌ بلغ ١٠٠/١٠٠ يجب أن يبقى قادراً
            //  على تعديل صنفٍ قائمٍ وتصحيح سعره — ومنعُه عقوبةٌ لا حدّ.
            // ══════════════════════════════════════════════════════════
            Route::post('/products', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'addProduct'])
                ->middleware('capability:' . \App\Support\Access\AccessConstants::F_PRODUCTS)
                ->name('products.add');

            // AMIAL-CATALOG-ADOPT-001 — **الاتّفاقُ المسبق**: يُمسح الباركودُ
            // فيُضاف الصنفُ من الكتالوج العامّ بضغطة. **وهو بابُ إنشاءٍ
            // فيُحرَس بحدّ الباقة نفسِه** — وإلّا صار طريقاً يلتفّ عليها.
            Route::post('/products/adopt', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'adoptFromCatalog'])
                ->middleware('capability:' . \App\Support\Access\AccessConstants::F_PRODUCTS)
                ->name('products.adopt');
            Route::put('/products/{id}', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'updateProduct'])->name('products.update');
            Route::post('/sales', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'recordSale'])
                ->middleware('amial.rate-limit:cashier_sale,120,1')->name('sales');
            Route::post('/sales/{id}/settle', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'settleCredit'])->name('sales.settle');
            // AMIAL-CASHIER-REFUND-001 — قائمة مبيعات اليوم (مدخل شاشة الاسترجاع)
            Route::get('/sales', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'listSales'])->name('sales.list');
            Route::get('/report', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'report'])->name('report');
            // AMIAL-PROFIT-001 — تقرير الربحية (إيراد/تكلفة/ربح/هامش + اتجاه + منتجات)
            Route::get('/profit-report', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'profitReport'])
                ->middleware('capability:' . \App\Support\Access\AccessConstants::F_PROFIT_REPORTS)
                ->name('profit-report');
        });

        // AMIAL-SUPPLIERS-001 — الموردون وأوامر الشراء (تصاميم 53/57/67/68)
        // AMIAL-ENTITLEMENTS-002 — حارسُ الباقة، ويبدأ في وضع الظلّ
        // (‏`AMIAL_ENTITLEMENTS_ENFORCE=false`): يُكتب المنعُ ولا يقع.
        Route::prefix('suppliers')->name('suppliers.')
            ->middleware('capability:' . \App\Support\Access\AccessConstants::F_SUPPLIERS)
            ->group(function () {
            $sc = \App\Http\Controllers\Api\V1\Amial\SupplierController::class;
            Route::get('/', [$sc, 'index'])->name('index');
            Route::post('/', [$sc, 'store'])->name('store');
            Route::get('/{id}', [$sc, 'show'])->where('id', '[0-9]+')->name('show');
            Route::post('/{id}/payment', [$sc, 'payment'])
                ->where('id', '[0-9]+')
                ->middleware('amial.rate-limit:supplier_payment,30,1')->name('payment');
        });
        Route::prefix('purchase-orders')->name('purchase-orders.')
            ->middleware('capability:' . \App\Support\Access\AccessConstants::F_PURCHASES)
            ->group(function () {
            $sc = \App\Http\Controllers\Api\V1\Amial\SupplierController::class;
            Route::get('/', [$sc, 'poIndex'])->name('index');
            Route::post('/', [$sc, 'poStore'])
                ->middleware('amial.rate-limit:po_create,30,1')->name('store');
            Route::get('/{id}', [$sc, 'poShow'])->where('id', '[0-9]+')->name('show');
            Route::post('/{id}/approve', [$sc, 'poApprove'])->where('id', '[0-9]+')->name('approve');
            Route::post('/{id}/receive', [$sc, 'poReceive'])
                ->where('id', '[0-9]+')
                ->middleware('amial.rate-limit:po_receive,30,1')->name('receive');
            Route::post('/{id}/cancel', [$sc, 'poCancel'])->where('id', '[0-9]+')->name('cancel');
        });

        // AMIAL-CUSTOMER-CREDIT-001 — نظام ديون العملاء
        Route::prefix('credit')->name('credit.')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'dashboard'])->name('dashboard');
            // AMIAL-ENTITLEMENTS-006 — **الحدُّ على الفعل لا على البادئة.**
            //
            // `credit/*` تخدم قدرتين: `debts` **مجّانيّة** و`customers`
            // من باقة الأعمال. فحراسةُ البادئة كلِّها كانت تُقفل دفترَ
            // الديون في وجه كلّ تاجرٍ مجّانيّ.
            //
            // فالمحروسُ **إدارةُ العملاء** (قائمةٌ وإنشاءٌ وملفٌّ وكشف)،
            // و**عملياتُ الدين تبقى مجّانيّة**: تسديدٌ ومرتجعٌ وتسوية —
            // فمن باع بالآجل يجب أن يُحصّل دينَه مهما كانت باقتُه.
            Route::get('/customers', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'listCustomers'])
                ->middleware('capability:' . \App\Support\Access\AccessConstants::F_CUSTOMERS)->name('customers');
            Route::post('/customers', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'upsertCustomer'])
                ->middleware('capability:' . \App\Support\Access\AccessConstants::F_CUSTOMERS)->name('customers.upsert');
            Route::get('/customers/{id}', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'showCustomer'])
                ->middleware('capability:' . \App\Support\Access\AccessConstants::F_CUSTOMERS)->name('customers.show');
            Route::get('/customers/{id}/statement', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'statement'])
                ->middleware('capability:' . \App\Support\Access\AccessConstants::F_CUSTOMERS)->name('customers.statement');
            Route::get('/customers/{id}/statement/pdf', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'statementPdf'])->name('customers.statement-pdf');
            Route::post('/customers/{id}/payment', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'recordPayment'])
                ->middleware('amial.rate-limit:credit_payment,60,1')->name('customers.payment');
            Route::post('/customers/{id}/return', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'recordReturn'])->name('customers.return');
            Route::post('/customers/{id}/adjustment', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'recordAdjustment'])->name('customers.adjustment');
        });

        // AMIAL-CASHIER-REFUND-001 — مرتجعات بيوع الكاشير
        Route::prefix('cashier/refunds')->name('cashier.refunds.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\Amial\CashierRefundController::class, 'index'])->name('index');
            Route::get('/{id}', [\App\Http\Controllers\Api\V1\Amial\CashierRefundController::class, 'show'])
                ->where('id', '[0-9]+')->name('show');
        });
        Route::prefix('cashier/sales')->name('cashier.sales.')->group(function () {
            // AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — تفصيلُ البيعة سطراً سطراً
            Route::get('/{ulid}', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'showSale'])
                ->where('ulid', '[A-Z0-9]{26}')->name('show');
            Route::get('/{ulid}/refundable', [\App\Http\Controllers\Api\V1\Amial\CashierRefundController::class, 'refundable'])
                ->where('ulid', '[A-Z0-9]{26}')->name('refundable');
            Route::post('/{ulid}/refund', [\App\Http\Controllers\Api\V1\Amial\CashierRefundController::class, 'create'])
                ->where('ulid', '[A-Z0-9]{26}')
                ->middleware('amial.rate-limit:cashier_refund,30,1')
                ->name('refund');
        });

        // AMIAL-MERCHANT-VERIFY-001 — توثيق التاجر (§13)
        Route::prefix('verification')->name('verification.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\Amial\MerchantVerificationController::class, 'status'])->name('status');
            Route::post('/', [\App\Http\Controllers\Api\V1\Amial\MerchantVerificationController::class, 'submit'])
                ->middleware('amial.rate-limit:verification_submit,5,1')->name('submit');
            Route::get('/document/{type}', [\App\Http\Controllers\Api\V1\Amial\MerchantVerificationController::class, 'document'])
                ->where('type', '[a-z_]+')->name('document');
        });

        // AMIAL-FUEL-001 — قطاع محطات الوقود
        Route::prefix('fuel')->name('fuel.')->group(function () {
            // Station
            Route::get('/station', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'getStation'])->name('station.show');
            Route::post('/station', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'upsertStation'])->name('station.upsert');

            // Pumps
            Route::get('/pumps', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'listPumps'])->name('pumps.index');
            Route::post('/pumps', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'addPump'])->name('pumps.add');
            Route::put('/pumps/{id}', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'updatePump'])
                ->where('id', '[0-9]+')->name('pumps.update');
            Route::post('/pumps/{id}/link-products', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'linkPumpToProducts'])
                ->where('id', '[0-9]+')->name('pumps.link');

            // Products + Prices
            Route::get('/products', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'listProducts'])->name('products.index');
            // AMIAL-PRODUCT-QUOTA-002 — **البابُ الرابع.** كان بلا حدٍّ
            // إطلاقاً بينما إخوتُه الثلاثة محروسة — والباقةُ لا تُباع بحدٍّ
            // يلتفّ عليه من يفتح محطّة.
            Route::post('/products', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'addProduct'])
                ->middleware('amial.usage:add_product')->name('products.add');
            Route::get('/price-history', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'priceHistory'])->name('price-history');
            Route::put('/products/{id}/price', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'updateProductPrice'])
                ->where('id', '[0-9]+')->name('products.price');

            // Sales (الجوهر)
            Route::post('/sales', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'recordSale'])
                ->middleware(['amial.rate-limit:fuel_sale,300,1', 'amial.usage:sale_operation'])
                ->name('sales.record');
            Route::get('/sales', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'listSales'])->name('sales.index');
            Route::get('/sales/{ulid}', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'showSale'])
                ->where('ulid', '[A-Z0-9]{26}')->name('sales.show');
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'dashboard'])->name('dashboard');

            // Company Accounts
            Route::get('/companies', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'listCompanies'])->name('companies.index');
            Route::post('/companies', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'addCompany'])->name('companies.add');
            Route::post('/companies/{id}/payment', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'recordCompanyPayment'])
                ->where('id', '[0-9]+')->name('companies.payment');

            // Cards (AMIAL-FUEL-CARDS-001)
            Route::get('/companies/{id}/cards', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'listCards'])
                ->middleware('capability:fuel_cards')
                ->where('id', '[0-9]+')->name('companies.cards.index');
            Route::post('/companies/{id}/cards', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'addCard'])
                ->middleware('capability:fuel_cards')
                ->where('id', '[0-9]+')->name('companies.cards.add');
            Route::put('/companies/{id}/cards/{cardId}', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'updateCard'])
                ->middleware('capability:fuel_cards')
                ->where(['id' => '[0-9]+', 'cardId' => '[0-9]+'])->name('companies.cards.update');

            // Shifts (AMIAL-FUEL-002)
            Route::get('/shifts/current', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'currentShift'])->name('shifts.current');
            Route::post('/shifts/open', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'openShift'])->name('shifts.open');
            Route::post('/shifts/{id}/close', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'closeShift'])
                ->where('id', '[0-9]+')->name('shifts.close');
            Route::get('/shifts', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'listShifts'])->name('shifts.index');
            Route::get('/shifts/{id}', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'showShift'])
                ->where('id', '[0-9]+')->name('shifts.show');

            // Variance Records
            Route::get('/variances', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'listVarianceRecords'])->name('variances.index');

            // Receipt PDF
            Route::get('/sales/{ulid}/receipt', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'downloadReceipt'])
                ->where('ulid', '[A-Z0-9]{26}')->name('sales.receipt');

            // ══ AMIAL-FUEL-VERTICAL-001 · المراحل ١–٧ ══════════════════
            //
            // وكلُّ فعلٍ خلفَه صلاحيّةٌ تُفحص في المتحكّم بنطاقها وحدّها —
            // إخفاءُ الزرّ ليس أماناً.
            $FV = \App\Http\Controllers\Api\V1\Amial\FuelVerticalController::class;

            // مركز العمليّات — الحالةُ الآن في نداءٍ واحد
            Route::get('/ops', [$FV, 'operationsCenter'])->name('ops');

            // الخزّانات والمسدسات
            Route::get('/tanks', [$FV, 'tanks'])->name('tanks.index');
            Route::post('/tanks', [$FV, 'addTank'])->name('tanks.add');
            Route::post('/tanks/{id}/dip', [$FV, 'recordDip'])
                ->where('id', '[0-9]+')->name('tanks.dip');
            Route::post('/pumps/{pumpId}/nozzles', [$FV, 'addNozzle'])
                ->where('pumpId', '[0-9]+')->name('nozzles.add');
            Route::post('/nozzles/{id}/tank', [$FV, 'linkNozzleToTank'])
                ->where('id', '[0-9]+')->name('nozzles.link-tank');

            // التوريدات
            Route::get('/deliveries', [$FV, 'deliveries'])->name('deliveries.index');
            Route::post('/deliveries', [$FV, 'receiveDelivery'])->name('deliveries.receive');
            Route::post('/deliveries/{id}/verify', [$FV, 'verifyDelivery'])
                ->where('id', '[0-9]+')->name('deliveries.verify');
            Route::post('/deliveries/{id}/post', [$FV, 'postDelivery'])
                ->where('id', '[0-9]+')->name('deliveries.post');
            Route::post('/suppliers', [$FV, 'addSupplier'])->name('suppliers.add');

            // مصالحة المخزون الرطب
            Route::get('/tanks/{id}/reconciliation', [$FV, 'reconciliationPreview'])
                ->middleware('capability:fuel_variance')
                ->where('id', '[0-9]+')->name('recon.preview');
            Route::post('/tanks/{id}/reconcile', [$FV, 'reconcile'])
                ->middleware('capability:fuel_variance')
                ->where('id', '[0-9]+')->name('recon.run');
            Route::get('/stock-variances', [$FV, 'variances'])
                ->middleware('capability:fuel_variance')->name('recon.index');
            Route::post('/stock-variances/{id}/resolve', [$FV, 'resolveVariance'])
                ->middleware('capability:fuel_variance')
                ->where('id', '[0-9]+')->name('recon.resolve');

            // الأسعار — اقتراحٌ ثمّ اعتماد
            Route::post('/prices/propose', [$FV, 'proposePrice'])->name('prices.propose');
            Route::get('/prices/pending', [$FV, 'pendingPrices'])->name('prices.pending');
            Route::post('/prices/{id}/approve', [$FV, 'approvePrice'])
                ->where('id', '[0-9]+')->name('prices.approve');

            // نقد الوردية
            Route::get('/shifts/{id}/cash', [$FV, 'shiftCash'])
                ->where('id', '[0-9]+')->name('shifts.cash');
            Route::post('/shifts/{id}/cash', [$FV, 'recordCashMovement'])
                ->where('id', '[0-9]+')->name('shifts.cash.add');

            // الأدوار والصلاحيّات
            Route::get('/me/permissions', [$FV, 'myPermissions'])->name('me.permissions');
            Route::get('/roles', [$FV, 'roles'])->name('roles.index');
            Route::post('/roles/seed', [$FV, 'seedRoles'])->name('roles.seed');
        });

        // ══ AMIAL-RETAIL-VERTICAL-001 · المراحل ٢–٩ ════════════════════
        //
        // وكلُّ فعلٍ خلفَه صلاحيّةٌ تُفحص في المتحكّم بنطاقها وحدّها —
        // إخفاءُ الزرّ ليس أماناً.
        Route::prefix('retail')->name('retail.')->group(function () {
            $RV = \App\Http\Controllers\Api\V1\Amial\RetailVerticalController::class;

            // AMIAL-ENTITLEMENTS-001 — **الباقة تُفحص هنا، والدور في المتحكّم**.
            //
            // ورقمان مختلفان لبابين مختلفين: ٤٠٢ لنقص الباقة (يذهب لصاحب
            // المتجر ليرقّي) و٤٠٣ لنقص الدور (يذهب لمديره ليمنحه). وردُّ
            // ٤٠٣ على نقص الباقة يُرسل التاجر يبحث عن دورٍ لن يجده.

            // مركز العمليّات — الحالةُ كلُّها في نداءٍ واحد
            Route::get('/ops', [$RV, 'operationsCenter'])->name('ops');
            Route::get('/me/permissions', [$RV, 'myPermissions'])->name('me.permissions');

            // محرّك الأصناف
            Route::get('/categories', [$RV, 'categories'])->middleware('capability:retail.catalog')->name('categories.index');
            Route::post('/categories', [$RV, 'addCategory'])->middleware('capability:retail.catalog')->name('categories.add');
            Route::get('/brands', [$RV, 'brands'])->middleware('capability:retail.catalog')->name('brands.index');
            Route::post('/brands', [$RV, 'addBrand'])->middleware('capability:retail.catalog')->name('brands.add');
            Route::get('/units', [$RV, 'units'])->middleware('capability:retail.catalog')->name('units.index');
            Route::post('/units', [$RV, 'addUnit'])->middleware('capability:retail.catalog')->name('units.add');
            Route::get('/scan', [$RV, 'scan'])->middleware('capability:barcode')->name('scan');
            Route::post('/products/{id}/barcodes', [$RV, 'addBarcode'])
                ->where('id', '[0-9]+')->name('products.barcodes.add');
            Route::post('/products/{id}/variants', [$RV, 'generateVariants'])->middleware('capability:retail.variants')
                ->where('id', '[0-9]+')->name('products.variants');

            // المخزون والمواقع
            Route::get('/locations', [$RV, 'locations'])->middleware('capability:retail.locations')->name('locations.index');
            Route::post('/locations', [$RV, 'addLocation'])->middleware('capability:retail.locations')->name('locations.add');
            Route::get('/products/{id}/stock', [$RV, 'productStock'])
                ->middleware('capability:inventory')
                ->where('id', '[0-9]+')->name('products.stock');
            Route::get('/products/{id}/movements', [$RV, 'movements'])
                ->middleware('capability:inventory')
                ->where('id', '[0-9]+')->name('products.movements');
            Route::get('/products/{id}/price-history', [$RV, 'priceHistory'])
                ->where('id', '[0-9]+')->name('products.price-history');

            // التحويلات — طلب ← اعتماد ← إرسال ← استلام
            Route::get('/transfers', [$RV, 'transfers'])->middleware('capability:retail.transfers')->name('transfers.index');
            Route::post('/transfers', [$RV, 'requestTransfer'])->middleware('capability:retail.transfers')->name('transfers.request');
            Route::get('/transfers/{id}', [$RV, 'showTransfer'])
                ->where('id', '[0-9]+')->name('transfers.show');
            Route::post('/transfers/{id}/approve', [$RV, 'approveTransfer'])
                ->where('id', '[0-9]+')->name('transfers.approve');
            Route::post('/transfers/{id}/ship', [$RV, 'shipTransfer'])
                ->where('id', '[0-9]+')->name('transfers.ship');
            Route::post('/transfers/{id}/receive', [$RV, 'receiveTransfer'])
                ->where('id', '[0-9]+')->name('transfers.receive');
            Route::post('/transfers/{id}/cancel', [$RV, 'cancelTransfer'])
                ->where('id', '[0-9]+')->name('transfers.cancel');

            // الجرد
            Route::get('/counts', [$RV, 'counts'])->middleware('capability:inventory_audit')->name('counts.index');
            Route::post('/counts', [$RV, 'openCount'])->middleware('capability:inventory_audit')->name('counts.open');
            Route::get('/counts/{id}', [$RV, 'countSheet'])
                ->where('id', '[0-9]+')->name('counts.sheet');
            Route::post('/counts/{id}/enter', [$RV, 'enterCount'])
                ->where('id', '[0-9]+')->name('counts.enter');
            Route::post('/counts/{id}/submit', [$RV, 'submitCount'])
                ->where('id', '[0-9]+')->name('counts.submit');
            Route::get('/counts/{id}/variances', [$RV, 'countVariances'])
                ->where('id', '[0-9]+')->name('counts.variances');
            Route::post('/counts/{id}/approve', [$RV, 'approveCount'])
                ->where('id', '[0-9]+')->name('counts.approve');

            // الهالك
            Route::get('/wastes', [$RV, 'wastes'])->middleware('capability:retail.waste')->name('wastes.index');
            Route::post('/wastes', [$RV, 'recordWaste'])->middleware('capability:retail.waste')->name('wastes.record');
            Route::post('/wastes/{id}/approve', [$RV, 'approveWaste'])
                ->where('id', '[0-9]+')->name('wastes.approve');
            Route::post('/wastes/{id}/reject', [$RV, 'rejectWaste'])
                ->where('id', '[0-9]+')->name('wastes.reject');

            // المرتجعات بأسطرها
            Route::get('/sales/{ulid}/returnable', [$RV, 'refundableLines'])
                ->where('ulid', '[A-Z0-9]{26}')->name('returns.lines');
            Route::post('/sales/{ulid}/returns', [$RV, 'createReturn'])
                ->where('ulid', '[A-Z0-9]{26}')->name('returns.create');
            Route::post('/returns/{id}/approve', [$RV, 'approveReturn'])
                ->where('id', '[0-9]+')->name('returns.approve');

            // الأسعار — اقتراحٌ ثمّ اعتماد
            Route::post('/prices/propose', [$RV, 'proposePrice'])->middleware('capability:retail.price_versions')->name('prices.propose');
            Route::get('/prices/pending', [$RV, 'pendingPrices'])->middleware('capability:retail.price_versions')->name('prices.pending');
            Route::post('/prices/{id}/approve', [$RV, 'approvePrice'])
                ->where('id', '[0-9]+')->name('prices.approve');

            // الأدوار
            Route::get('/roles', [$RV, 'roles'])->middleware('capability:rbac')->name('roles.index');
            Route::post('/roles/seed', [$RV, 'seedRoles'])->middleware('capability:rbac')->name('roles.seed');
        });

        // AMIAL-PHARMACY-001 — قطاع الصيدليات
        Route::prefix('pharmacy')->name('pharmacy.')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'dashboard'])->name('dashboard');

            // Pharmacy
            Route::get('/', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'getPharmacy'])->name('show');
            Route::post('/', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'upsertPharmacy'])->name('upsert');

            // Products
            Route::get('/products', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'listProducts'])->name('products.index');
            Route::post('/products', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'addProduct'])
                ->middleware('amial.usage:add_product')->name('products.add');
            Route::put('/products/{id}', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'updateProduct'])
                ->where('id', '[0-9]+')->name('products.update');

            // Batches
            Route::get('/products/{id}/batches', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'listBatches'])
                ->middleware('capability:pharmacy_batches')
                ->where('id', '[0-9]+')->name('batches.index');
            Route::post('/products/{id}/batches', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'addBatch'])
                ->middleware('capability:pharmacy_batches')
                ->where('id', '[0-9]+')->name('batches.add');

            // Customers
            Route::get('/customers', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'listCustomers'])->name('customers.index');
            Route::get('/customers/by-phone', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'findCustomerByPhone'])->name('customers.by-phone');
            Route::post('/customers', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'addCustomer'])->name('customers.add');
            Route::put('/customers/{id}', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'updateCustomer'])
                ->where('id', '[0-9]+')->name('customers.update');

            // Sales
            Route::post('/sales', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'recordSale'])
                ->middleware(['amial.rate-limit:pharmacy_sale,300,1', 'amial.usage:sale_operation'])
                ->name('sales.record');
            Route::get('/sales', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'listSales'])->name('sales.index');

            // ══════════════════════════════════════════════════════
            // Alerts — **تنبيهُ نفاد المخزون، مبنيٌّ ولم يكن موصولاً
            // باستحقاقه.**
            //
            // `PharmacyAlertService` قائمٌ منذ مدّة (‏`checkLowStock` و
            // `scanExpiringBatches` و`dismissAlert`)، والقدرةُ
            // `low_stock_alerts` تُباع من «البداية» في السجلّ وفي جدول
            // الباقات — **ولا حارسَ بينهما**. فكانت تُقرأ «مدفوعةٌ بلا
            // نقطة نهاية» وهي مبنيّةٌ بالكامل: العطلُ في الوصل لا في
            // البناء.
            Route::get('/alerts', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'listAlerts'])
                ->middleware('capability:low_stock_alerts')->name('alerts.index');
            Route::post('/alerts/scan', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'scanExpiringBatches'])
                ->middleware('capability:low_stock_alerts')->name('alerts.scan');
            Route::post('/alerts/{id}/dismiss', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'dismissAlert'])
                ->middleware('capability:low_stock_alerts')
                ->where('id', '[0-9]+')->name('alerts.dismiss');
        });

        // AMIAL-WHOLESALE-001 — قطاع تجارة الجملة
        Route::prefix('wholesale')->name('wholesale.')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'dashboard'])->name('dashboard');

            // Business
            Route::get('/', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'getBusiness'])->name('show');
            Route::post('/', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'upsertBusiness'])->name('upsert');

            // Price Tiers
            Route::post('/price-tiers', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'addPriceTier'])
                ->middleware('capability:wholesale_multi_pricing')->name('tiers.add');

            // Products
            Route::get('/products', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'listProducts'])->name('products.index');
            Route::post('/products', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'addProduct'])
                ->middleware('amial.usage:add_product')->name('products.add');
            Route::put('/products/{id}', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'updateProduct'])
                ->where('id', '[0-9]+')->name('products.update');
            Route::post('/products/{id}/adjust-stock', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'adjustStock'])
                ->middleware('capability:inventory')
                ->where('id', '[0-9]+')->name('products.adjust-stock');

            // Multi-Pricing
            Route::get('/products/{id}/prices', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'listProductPrices'])
                ->where('id', '[0-9]+')->name('products.prices');
            Route::post('/products/{id}/prices', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'setProductPrice'])
                ->where('id', '[0-9]+')->name('products.price.set');

            // Customers
            Route::get('/customers', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'listCustomers'])->name('customers.index');
            Route::post('/customers', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'addCustomer'])->name('customers.add');
            Route::put('/customers/{id}', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'updateCustomer'])
                ->where('id', '[0-9]+')->name('customers.update');

            // Invoices
            Route::get('/invoices', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'listInvoices'])->name('invoices.index');
            Route::get('/invoices/{id}', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'showInvoice'])
                ->where('id', '[0-9]+')->name('invoices.show');
            Route::get('/invoices/{id}/pdf', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'downloadInvoicePdf'])
                ->where('id', '[0-9]+')->name('invoices.pdf');
            Route::post('/invoices', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'createInvoice'])
                ->middleware(['amial.rate-limit:wholesale_invoice,200,1', 'amial.usage:sale_operation'])
                ->name('invoices.create');
            Route::post('/invoices/{id}/void', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'voidInvoice'])
                ->where('id', '[0-9]+')->name('invoices.void');

            // Collections
            Route::get('/collections', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'listCollections'])->name('collections.index');
            Route::post('/invoices/{id}/collect', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'recordCollection'])
                ->where('id', '[0-9]+')->name('collections.record');

            // Sales Reps
            Route::get('/sales-reps', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'listSalesReps'])->name('reps.index');
            Route::post('/sales-reps', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'addSalesRep'])->name('reps.add');

            // Reports
            Route::get('/reports/aging', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'agingReport'])->name('reports.aging');
            Route::get('/reports/customer/{id}/statement', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'customerStatement'])
                ->where('id', '[0-9]+')->name('reports.statement');
            Route::get('/reports/sales-reps', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'salesRepsPerformance'])->name('reports.reps');
        });

        Route::post('/refund', [MerchantController::class, 'refund'])
            ->middleware(['amial.zone:refund', 'amial.rate-limit:merchant_refund,20,1'])
            ->name('refund');
        Route::get('/ledger', [MerchantController::class, 'ledger'])->name('ledger');
        Route::get('/daily-stats', [MerchantController::class, 'dailyStats'])->name('daily-stats');
    });

    // -------- AMIAL-API-ACCESS-001 — إدارة مفاتيح API للتاجر --------
    Route::prefix('merchant/api-keys')->name('amial.merchant.api-keys.')->group(function () {
        $c = \App\Http\Controllers\Api\V1\Amial\MerchantApiKeyController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::post('/', [$c, 'store'])->name('store');
        Route::post('/{id}/toggle', [$c, 'toggle'])->where('id', '[0-9]+')->name('toggle');
        Route::delete('/{id}', [$c, 'destroy'])->where('id', '[0-9]+')->name('destroy');
    });

    // -------- AMIAL-BACKUP-001 — نسخة احتياطية لبيانات التاجر --------
    Route::get('merchant/backup', [\App\Http\Controllers\Api\V1\Amial\MerchantBackupController::class, 'download'])
        ->name('amial.merchant.backup');

    // -------- AMIAL-PROMOTIONS-001 — العروض والخصومات والكوبونات --------
    Route::prefix('merchant/promotions')->name('amial.merchant.promotions.')->group(function () {
        $c = \App\Http\Controllers\Api\V1\Amial\PromotionController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::post('/', [$c, 'store'])->name('store');
        Route::post('/apply', [$c, 'apply'])->name('apply');
        Route::post('/{id}', [$c, 'update'])->where('id', '[0-9]+')->name('update');
        Route::post('/{id}/toggle', [$c, 'toggle'])->where('id', '[0-9]+')->name('toggle');
        Route::delete('/{id}', [$c, 'destroy'])->where('id', '[0-9]+')->name('destroy');
    });

    // -------- AMIAL-LOYALTY-001 — برنامج الولاء والنقاط --------
    Route::prefix('merchant/loyalty')->name('amial.merchant.loyalty.')->group(function () {
        $c = \App\Http\Controllers\Api\V1\Amial\LoyaltyController::class;
        Route::get('/program', [$c, 'program'])->name('program');
        Route::post('/program', [$c, 'saveProgram'])->name('program.save');
        Route::get('/accounts', [$c, 'accounts'])->name('accounts');
        Route::get('/lookup', [$c, 'lookup'])->name('lookup');
        Route::post('/redeem', [$c, 'redeem'])->name('redeem');
        Route::post('/adjust', [$c, 'adjust'])->name('adjust');
    });

    // -------- AMIAL-EXPENSES-001 — المصروفات والصندوق النثري --------
    Route::prefix('merchant/expenses')->name('amial.merchant.expenses.')->group(function () {
        $c = \App\Http\Controllers\Api\V1\Amial\ExpenseController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::post('/', [$c, 'store'])->name('store');
        Route::post('/{id}', [$c, 'update'])->where('id', '[0-9]+')->name('update');
        Route::delete('/{id}', [$c, 'destroy'])->where('id', '[0-9]+')->name('destroy');
    });

    // -------- AMIAL-SHIFT-CLOSE-001 — ورديات الكاشير ودرج النقد --------
    Route::prefix('cashier/shift')->name('amial.cashier.shift.')->group(function () {
        $c = \App\Http\Controllers\Api\V1\Amial\CashierShiftController::class;
        Route::get('/', [$c, 'current'])->name('current');
        Route::post('/open', [$c, 'open'])->name('open');
        Route::get('/x', [$c, 'xReport'])->name('x');
        Route::post('/close', [$c, 'close'])->name('close');
        Route::get('/history', [$c, 'history'])->name('history');
    });

    // -------- AMIAL-GIFT-CARDS-001 — بطاقات الهدايا ورصيد المتجر --------
    Route::prefix('merchant/gift-cards')->name('amial.merchant.gift-cards.')->middleware('amial.idempotency')->group(function () {
        $c = \App\Http\Controllers\Api\V1\Amial\GiftCardController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::post('/', [$c, 'issue'])->name('issue');
        Route::get('/lookup', [$c, 'lookup'])->name('lookup');
        Route::post('/redeem', [$c, 'redeem'])->name('redeem');
        Route::post('/topup', [$c, 'topUp'])->name('topup');
        Route::post('/void', [$c, 'void'])->name('void');
    });
    Route::get('me/gift-cards', [\App\Http\Controllers\Api\V1\Amial\GiftCardController::class, 'mine'])
        ->name('amial.me.gift-cards');

    // -------- AMIAL-RESTAURANT-001 — المطاعم: طاولات + طلبات + مطبخ --------
    Route::prefix('restaurant')->name('amial.restaurant.')->group(function () {
        $c = \App\Http\Controllers\Api\V1\Amial\RestaurantController::class;
        Route::get('/tables', [$c, 'tables'])->name('tables');
        Route::post('/tables', [$c, 'createTable'])->name('tables.create');
        Route::post('/tables/{id}', [$c, 'updateTable'])->where('id', '[0-9]+')->name('tables.update');
        Route::delete('/tables/{id}', [$c, 'deleteTable'])->where('id', '[0-9]+')->name('tables.delete');
        Route::get('/orders', [$c, 'orders'])->name('orders');
        Route::post('/orders', [$c, 'openOrder'])->name('orders.open');
        Route::get('/kitchen', [$c, 'kitchen'])->name('kitchen');
        Route::get('/orders/{id}', [$c, 'showOrder'])->where('id', '[0-9]+')->name('orders.show');
        Route::post('/orders/{id}', [$c, 'updateOrder'])->where('id', '[0-9]+')->name('orders.update');
        Route::post('/orders/{id}/status', [$c, 'setStatus'])->where('id', '[0-9]+')->name('orders.status');
        Route::post('/orders/{id}/close', [$c, 'closeOrder'])->where('id', '[0-9]+')->name('orders.close');
    });

    // -------- AMIAL-INSTALLMENTS-001 — البيع بالتقسيط --------
    Route::prefix('merchant/installments')->name('amial.merchant.installments.')->middleware('amial.idempotency')->group(function () {
        $c = \App\Http\Controllers\Api\V1\Amial\InstallmentController::class;
        Route::get('/plan', [$c, 'plan'])->name('plan');
        Route::post('/plan', [$c, 'savePlan'])->name('plan.save');
        // استثناءٌ مُعلَن: تسعيرةٌ تُحسب ولا تُحرّك مالاً — ولا تُعاد من ذاكرة.
        Route::post('/quote', [$c, 'quote'])->withoutMiddleware('amial.idempotency')->name('quote');
        Route::get('/contracts', [$c, 'contracts'])->name('contracts');
        Route::post('/contracts', [$c, 'createContract'])->name('contracts.create');
        Route::get('/contracts/{id}', [$c, 'showContract'])->where('id', '[0-9]+')->name('contracts.show');
    });
    // جهة العميل — أقساطي
    Route::prefix('me/installments')->name('amial.me.installments.')->middleware('amial.idempotency')->group(function () {
        $c = \App\Http\Controllers\Api\V1\Amial\InstallmentController::class;
        Route::get('/', [$c, 'myContracts'])->name('mine');
        Route::post('/{id}/pay', [$c, 'pay'])->where('id', '[0-9]+')->name('pay');
    });

    // -------- AMIAL-MULTI-CURRENCY-001 — عملات التاجر --------
    Route::prefix('merchant/currencies')->name('amial.merchant.currencies.')->group(function () {
        $c = \App\Http\Controllers\Api\V1\Amial\MerchantCurrencyController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::post('/', [$c, 'store'])->name('store');
        Route::post('/{id}', [$c, 'update'])->where('id', '[0-9]+')->name('update');
        Route::post('/{id}/toggle', [$c, 'toggle'])->where('id', '[0-9]+')->name('toggle');
        Route::delete('/{id}', [$c, 'destroy'])->where('id', '[0-9]+')->name('destroy');
    });

    // -------- AMIAL-CORPORATE-ACCOUNTS-001 — حسابات الشركات (B2B) --------
    Route::prefix('merchant/corporate')->name('amial.merchant.corporate.')->middleware('amial.idempotency')->group(function () {
        $c = \App\Http\Controllers\Api\V1\Amial\CorporateAccountController::class;
        Route::get('/dashboard', [$c, 'dashboard'])->name('dashboard');
        Route::get('/accounts', [$c, 'index'])->name('accounts');
        Route::post('/accounts', [$c, 'store'])->name('accounts.store');
        Route::get('/accounts/{id}', [$c, 'show'])->where('id', '[0-9]+')->name('accounts.show');
        Route::post('/accounts/{id}', [$c, 'update'])->where('id', '[0-9]+')->name('accounts.update');
        Route::post('/accounts/{id}/members', [$c, 'addMember'])->where('id', '[0-9]+')->name('accounts.members');
        Route::post('/accounts/{id}/charge', [$c, 'charge'])->where('id', '[0-9]+')->name('accounts.charge');
        Route::post('/accounts/{id}/settle', [$c, 'settle'])->where('id', '[0-9]+')->name('accounts.settle');
        Route::get('/accounts/{id}/statement', [$c, 'statement'])->where('id', '[0-9]+')->name('accounts.statement');
    });

    // -------- AMIAL-RECEIPT-SETTINGS-001 — إعدادات الفاتورة والطباعة --------
    Route::prefix('merchant/receipt-settings')->name('amial.merchant.receipt-settings.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\Amial\MerchantReceiptSettingsController::class, 'show'])->name('show');
        Route::post('/', [\App\Http\Controllers\Api\V1\Amial\MerchantReceiptSettingsController::class, 'save'])->name('save');
        Route::post('/logo', [\App\Http\Controllers\Api\V1\Amial\MerchantReceiptSettingsController::class, 'uploadLogo'])->name('logo');
    });

    // -------- AMIAL-MERCHANT-AUDIT-001 — سجلّ التدقيق للتاجر (برو فأعلى) --------
    Route::get('merchant/audit-log', [\App\Http\Controllers\Api\V1\Amial\MerchantAuditController::class, 'index'])
        ->name('amial.merchant.audit-log');

    // -------- AMIAL-MERCHANT-STAFF-001 — التاجر يدير موظفي نقاط البيع --------
    Route::prefix('merchant/staff')->name('amial.merchant.staff.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\Amial\MerchantStaffController::class, 'index'])->name('index');
        // AMIAL-STAFF-PERFORMANCE-001 — أداء الموظفين من المبيعات الفعلية
        Route::get('/performance', [\App\Http\Controllers\Api\V1\Amial\MerchantStaffController::class, 'performance'])->name('performance');
        Route::post('/', [\App\Http\Controllers\Api\V1\Amial\MerchantStaffController::class, 'store'])->name('store');
        Route::post('/{id}/toggle', [\App\Http\Controllers\Api\V1\Amial\MerchantStaffController::class, 'toggle'])
            ->where('id', '[0-9]+')->name('toggle');
        Route::post('/{id}/operations-manager', [\App\Http\Controllers\Api\V1\Amial\MerchantStaffController::class, 'setOperationsManager'])
            ->where('id', '[0-9]+')->name('ops-manager');
        Route::post('/{id}/financial-manager', [\App\Http\Controllers\Api\V1\Amial\MerchantStaffController::class, 'setFinancialManager'])
            ->where('id', '[0-9]+')->name('fin-manager');
    });

    // -------- AMIAL-CUSTOMER-CREDIT-VIEW-001 — العميل يرى ما عليه من آجل --------
    Route::prefix('customer/credits')->name('amial.customer.credits.')->middleware('amial.idempotency')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditViewController::class, 'myAccounts'])->name('mine');
        Route::get('/{id}/statement', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditViewController::class, 'myStatement'])
            ->where('id', '[0-9]+')->name('statement');
        Route::post('/{id}/settle', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditViewController::class, 'settle'])
            ->where('id', '[0-9]+')
            ->middleware('amial.rate-limit:credit_settle,30,1')->name('settle');
    });

    // -------- AMIAL-CUSTOMER-WITHDRAW-001 — السحب المبدوء من العميل --------
    Route::prefix('withdraw')->name('amial.withdraw.')->middleware('amial.idempotency')->group(function () {
        Route::post('/request', [\App\Http\Controllers\Api\V1\Amial\CustomerWithdrawController::class, 'request'])
            ->middleware(['amial.zone:cash_out', 'amial.idempotency', 'amial.rate-limit:withdraw_req,20,1'])
            ->name('request');
        Route::get('/mine', [\App\Http\Controllers\Api\V1\Amial\CustomerWithdrawController::class, 'mine'])->name('mine');
        Route::post('/{id}/cancel', [\App\Http\Controllers\Api\V1\Amial\CustomerWithdrawController::class, 'cancel'])->name('cancel');
    });
    Route::prefix('agent/withdraw')->name('amial.agent.withdraw.')->group(function () {
        Route::post('/lookup', [\App\Http\Controllers\Api\V1\Amial\CustomerWithdrawController::class, 'lookup'])
            ->middleware('amial.rate-limit:withdraw_lookup,60,1')->name('lookup');
        // AMIAL-ZONE-BOUNDARY-001: هنا يسلّم الوكيل ورقاً حقيقياً — الحدّ
        // الفعلي بين العملتين. يُلزَم بموقعه لحظة التسليم.
        // **وهنا يُسلَّم ورقٌ حقيقيّ** — فإعادةُ الطلب تُسلّم مرّتين.
        Route::post('/execute', [\App\Http\Controllers\Api\V1\Amial\CustomerWithdrawController::class, 'execute'])
            ->middleware(['amial.zone:cash_out', 'amial.agent-location', 'amial.rate-limit:withdraw_exec,40,1', 'amial.idempotency'])->name('execute');
    });

    // AMIAL-FAVORITES-001 — مفضّلة موحّدة (جهة اتصال/حساب/تاجر/عملية)
    Route::prefix('favorites')->name('amial.favorites.')->group(function () {
        $fc = \App\Http\Controllers\Api\V1\Amial\FavoritesController::class;
        Route::get('/', [$fc, 'index'])->name('index');
        Route::post('/toggle', [$fc, 'toggle'])
            ->middleware('amial.rate-limit:fav_toggle,60,1')->name('toggle');
        Route::post('/check', [$fc, 'check'])->name('check');
        Route::delete('/{id}', [$fc, 'destroy'])->where('id', '[0-9]+')->name('destroy');
    });

    // AMIAL-COVERAGE-001 — تغطية الخدمة في محافظة المستخدم (وكلاء/تجار)
    Route::get('/service-coverage', [\App\Http\Controllers\Api\V1\Amial\ServiceCoverageController::class, 'show'])
        ->name('amial.service-coverage');

    // AMIAL-COVERAGE-002 — تحديد محافظة الإقامة من التطبيق. كانت الرسالة
    // تنصح بتحديث العنوان ولا سبيل إليه.
    Route::post('/me/governorate', [\App\Http\Controllers\Api\V1\Amial\ServiceCoverageController::class, 'setGovernorate'])
        ->middleware('amial.rate-limit:set_governorate,6,1')
        ->name('amial.me.governorate');

    Route::prefix('split-bills')->name('amial.split-bills.')->middleware('amial.idempotency')->group(function () {
        Route::get('/mine', [\App\Http\Controllers\Api\V1\Amial\SplitBillController::class, 'mine'])->name('mine');
        Route::post('/participants/{id}/pay', [\App\Http\Controllers\Api\V1\Amial\SplitBillController::class, 'payShare'])
            ->middleware(['amial.zone:split_bill', 'amial.rate-limit:split_pay,30,1'])
            ->name('pay');
    });

    // -------- AMIAL-AGENT-STATS-001 (v1.7) --------
    Route::prefix('agent')->name('amial.agent.')
        ->middleware('amial.agent') // AMIAL-FIX-004 — فرض دور الوكيل
        ->group(function () {
        Route::get('/daily-stats', [AgentStatsController::class, 'dailyStats'])->name('daily-stats');
        // -------- AMIAL-AGENT-NETWORK-001 (v2.4) --------
        Route::get('/float-dashboard', [AgentNetworkController::class, 'floatDashboard'])->name('float-dashboard');
        Route::get('/float-statement', [AgentNetworkController::class, 'floatStatement'])->name('float-statement');
        Route::post('/topup-request', [AgentNetworkController::class, 'requestTopup'])
            ->middleware(['amial.rate-limit:agent_topup,10,60', 'amial.idempotency'])->name('topup-request');
        Route::get('/settlements', [AgentNetworkController::class, 'settlements'])->name('settlements');
        Route::get('/distributor-network', [AgentNetworkController::class, 'distributorNetwork'])->name('distributor-network');
    });

    // -------- AMIAL-DONATIONS-001 (v1.2) --------
    Route::prefix('donations')->name('amial.donations.')->middleware('amial.idempotency')->group(function () {
        Route::get('/categories', [DonationsController::class, 'categories'])->name('categories');
        Route::get('/organizations', [DonationsController::class, 'organizations'])->name('orgs');
        Route::get('/campaigns', [DonationsController::class, 'campaigns'])->name('campaigns');
        Route::get('/campaigns/{ulid}', [DonationsController::class, 'campaignShow'])
            ->where('ulid', '[A-Z0-9]{26}')->name('campaign-show');

        Route::post('/donate', [DonationsController::class, 'donate'])
            ->middleware(['amial.zone:donate', 'amial.rate-limit:donate,10,1'])
            ->name('donate');

        Route::get('/my-donations', [DonationsController::class, 'myDonations'])->name('my-donations');
    });
});

// ==========================================================
// AMIAL-API-ACCESS-001 — واجهة الشركاء (مصادقة بمفتاح API لا auth:api)
// ==========================================================
Route::prefix('partner')->middleware('amial.api-key')->name('amial.partner.')->group(function () {
    Route::get('/sales', [\App\Http\Controllers\Api\V1\Partner\PartnerController::class, 'sales'])->name('sales');
});

// ==========================================================
// Public — لا مصادقة
// ==========================================================

// AMIAL-RECEIPTS-001: تحقق عام من إيصال عبر QR code
// AMIAL-RECEIPT-NUMBERS-001: النمط يقبل الشكلين — 16 رقماً (الجديد) و16
// حرفاً Base32 (القديم). الإيصالات المطبوعة قبل التغيير يجب أن تبقى
// قابلة للتحقّق إلى الأبد؛ مستند مالي بيد عميل لا يُبطَل بتحديث برمجي.
//
// وحدّ المعدّل: النقطة عامّة بلا مصادقة وكانت بلا أي حدّ، فتُجرَّب الأكواد
// بالقوة الغاشمة بلا مانع. صار 20 محاولة/دقيقة لكل IP — يكفي المستخدم
// الحقيقي (يمسح رمزاً أو يكتب كوداً مرّة) ويقتل التجريب الآلي.
Route::get('/v/{code}', [ReceiptController::class, 'verifyPublic'])
    // النمط يسمح بالمسافات والشَرطات لأن العميل يكتب الكود كما يراه
    // مجموعاً على الورقة؛ المتحكّم يُطبّع ثم يتحقّق من الشكل بدقّة.
    ->where('code', '[A-Za-z0-9 \-]{16,32}')
    ->middleware('throttle:20,1')
    ->name('amial.receipt.verify');

// ============================================================
// تعليمات لـ routes/admin.php
// ============================================================
/*
 * أضف ما يلي إلى routes/admin.php داخل group middleware 'admin':
 *
 * use App\Http\Controllers\Admin\LegalTermsController;
 * use App\Http\Controllers\Admin\AccountRecoveryController;
 *
 * Route::prefix('legal')->name('admin.legal.')->group(function () {
 *     Route::get('/terms', [LegalTermsController::class, 'index'])->name('index');
 *     Route::post('/terms', [LegalTermsController::class, 'store'])->name('store');
 *     Route::get('/terms/{id}', [LegalTermsController::class, 'show'])->name('show');
 *     Route::get('/terms/{id}/stats', [LegalTermsController::class, 'stats'])->name('stats');
 * });
 *
 * Route::prefix('recovery')->name('admin.recovery.')->group(function () {
 *     Route::get('/requests', [AccountRecoveryController::class, 'index'])->name('index');
 *     Route::get('/requests/{ulid}', [AccountRecoveryController::class, 'show'])->name('show');
 *     Route::post('/requests/{ulid}/approve', [AccountRecoveryController::class, 'approve'])->name('approve');
 *     Route::post('/requests/{ulid}/reject', [AccountRecoveryController::class, 'reject'])->name('reject');
 * });
 */

// AMIAL-CLEANUP: أُزيلت تعليمات install.php/update.php (النظام محذوف)

// ============================================================
// تطبيق middleware على routes المالية القائمة
// ============================================================
/*
 * في routes/api/customer.php (أو ما يقابلها)، التف بـ:
 *
 * Route::middleware([
 *     'auth:api',
 *     'amial.terms',
 *     'amial.idempotency',
 * ])->group(function () {
 *
 *     Route::post('/send-money', [TransactionController::class, 'sendMoney'])
 *         ->middleware('amial.zone:send_money');
 *
 *     Route::post('/cash-out', [TransactionController::class, 'cashOut'])
 *         ->middleware('amial.zone:cash_out');
 *
 *     Route::post('/request-money', [TransactionController::class, 'requestMoney'])
 *         ->middleware('amial.zone:request_money');
 *
 *     Route::post('/withdraw', [WithdrawController::class, 'withdraw'])
 *         ->middleware('amial.zone:withdraw');
 *
 *     Route::post('/add-money', [TransactionController::class, 'addMoney'])
 *         ->middleware('amial.zone:add_money');
 * });
 *
 * هذا الترتيب مقصود:
 *   auth → terms → idempotency → zone → controller
 *
 * كي يفشل الطلب بأقل تكلفة ممكنة عند المرحلة الأنسب.
 */
