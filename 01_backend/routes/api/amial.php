Warning: truncated output (original token count: 28520)
Total output lines: 1535

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

        // ══════════════════════════════════════════════════════════════
        // AMIAL-PROFILE-CHANGE-005 — **الطرفُ الناقص: مكانٌ يملأ فيه العميل.**
        //
        // وهو نفسُ عطل `AMIAL-KYC-DOCS-001` أعلاه بصورةٍ أخرى: الدعمُ
        // يفتح طلبَ تحديثٍ من اللوحة، **ولا مكانَ يستجيب فيه العميل** —
        // فيبقى الطلبُ `PENDING_CUSTOMER` إلى الأبد.
        //
        // **ولا نقطةَ كتابةٍ مباشرةٍ للحقول ها هنا**: العميلُ يملأ **قيمةَ
        // طلبٍ فُتح**، ويعتمدها مراجع. فحسابٌ موثَّقٌ لا يُغيّر صاحبُه
        // اسمَه ولا رقمَ هويّته بنفسه.
        // ══════════════════════════════════════════════════════════════
        $pch = \App\Http\Controllers\Api\V1\Amial\ProfileChangeController::class;

        Route::get('/profile-changes', [$pch, 'mine'])->name('profile-changes.mine');
        Route::get('/profile-changes/fields', [$pch, 'fields'])->name('profile-changes.fields');
        Route::post('/profile-changes', [$pch, 'open'])->name('profile-changes.open');
        Route::post('/profile-changes/{id}/submit', [$pch, 'submit'])
            ->where('id', '[0-9]+')->name('profile-changes.submit');
        Route::post('/profile-changes/{id}/cancel', [$pch, 'cancel'])
            ->where('id', '[0-9]+')->name('profile-changes.cancel');
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
        Route::get('…8520 tokens truncated…) و٤٠٣ لنقص الدور (يذهب لمديره ليمنحه). وردُّ
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
            Route::post('/batches/{id}/recall', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'recallBatch'])
                ->middleware('capability:pharmacy_batches')
                ->where('id', '[0-9]+')->name('batches.recall');

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
            Route::get('/products/{id}/units', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'listProductUnits'])
                ->where('id', '[0-9]+')->name('products.units.index');
            Route::post('/products/{id}/units', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'saveProductUnit'])
                ->middleware('capability:inventory')->where('id', '[0-9]+')->name('products.units.save');
            Route::get('/products/{id}/lots', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'listProductLots'])
                ->middleware('capability:inventory')->where('id', '[0-9]+')->name('products.lots.index');
            Route::post('/products/{id}/lots', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'receiveProductLot'])
                ->middleware('capability:inventory')->where('id', '[0-9]+')->name('products.lots.receive');

            // Multi-Pricing
            Route::get('/products/{id}/prices', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'listProductPrices'])
                ->middleware('capability:wholesale_multi_pricing')
                ->where('id', '[0-9]+')->name('products.prices');
            Route::post('/products/{id}/prices', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'setProductPrice'])
                ->middleware('capability:wholesale_multi_pricing')
                ->where('id', '[0-9]+')->name('products.price.set');
            Route::get('/products/{id}/quote', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'quoteProduct'])
                ->where('id', '[0-9]+')->name('products.quote');

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
            Route::post('/invoices/amial-payment-request', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'createInvoicePaymentRequest'])
                ->middleware('amial.rate-limit:wholesale_invoice,200,1')
                ->name('invoices.amial-payment-request');
            Route::post('/invoices/{id}/void', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'voidInvoice'])
                ->where('id', '[0-9]+')->name('invoices.void');
            Route::post('/invoices/{id}/amial-payment-request', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'createCollectionPaymentRequest'])
                ->middleware('amial.rate-limit:wholesale_invoice,200,1')
                ->where('id', '[0-9]+')->name('invoices.collection-amial-payment-request');
            Route::post('/payment-requests/{id}/cancel', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'cancelWholesalePaymentRequest'])
                ->where('id', '[0-9]+')->name('payment-requests.cancel');

            // Returns — طلبٌ ومراجعةٌ منفصلان؛ لا تُستعمل مرتجعات التاجر العامة.
            Route::get('/returns', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'listReturns'])->name('returns.index');
            Route::post('/invoices/{id}/returns', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'requestReturn'])
                ->where('id', '[0-9]+')->name('returns.request');
            Route::post('/returns/{id}/resolve', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'resolveReturn'])
                ->where('id', '[0-9]+')->name('returns.resolve');

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
        Route::get('/financial-report', [MerchantController::class, 'financialReport'])->name('financial-report');
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
