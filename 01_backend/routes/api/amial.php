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

// P0-LEGAL — Markdown docs للموقع العام (بدون auth)
Route::prefix('legal-docs')->name('amial.legal-docs.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\V1\Amial\PublicLegalController::class, 'index'])->name('index');
    Route::get('/{slug}', [\App\Http\Controllers\Api\V1\Amial\PublicLegalController::class, 'show'])
        ->where('slug', '[a-z_-]+')->name('show');
});

// ============================================================
// AUTHENTICATED USER
// ============================================================

Route::middleware(['auth:api'])->group(function () {

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
    });

    // AMIAL-BARCODE-001 — البحث السريع بالباركود (للـ continuous scanner)
    Route::get('/barcode/lookup', [\App\Http\Controllers\Api\V1\Amial\BarcodeLookupController::class, 'lookup'])
        ->middleware('amial.rate-limit:barcode_lookup,600,1') // 600/دقيقة (مسح سريع)
        ->name('amial.barcode.lookup');

    // CRITICAL-001-PLANS — كتالوج الخطط (شاشة الترقية)
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
            Route::get('/search', [$c, 'search'])->name('search');
            Route::get('/ops-dashboard', [$c, 'opsDashboard'])->name('ops-dashboard');
            Route::get('/customers/{id}', [$c, 'customer'])->where('id', '[0-9]+')->name('customers.show');
            Route::get('/customers/{id}/transactions', [$c, 'customerTransactions'])->where('id', '[0-9]+')->name('customers.transactions');
            Route::get('/transactions/{ref}', [$c, 'transaction'])->name('transactions.show');
            // إجراءات التحقّق — كلها تتطلب سبباً وتُسجَّل في التدقيق
            Route::post('/customers/{id}/freeze', [$c, 'freeze'])->where('id', '[0-9]+')->name('customers.freeze');
            Route::post('/customers/{id}/reset-pin', [$c, 'resetPin'])->where('id', '[0-9]+')->name('customers.reset-pin');
            Route::post('/customers/{id}/revoke-sessions', [$c, 'revokeSessions'])->where('id', '[0-9]+')->name('customers.revoke-sessions');
            Route::post('/customers/{id}/require-kyc', [$c, 'requireKyc'])->where('id', '[0-9]+')->name('customers.require-kyc');
            // تذاكر النزاعات
            Route::get('/tickets', [$c, 'tickets'])->name('tickets.index');
            Route::post('/tickets', [$c, 'createTicket'])->name('tickets.create');
            Route::get('/tickets/{id}', [$c, 'showTicket'])->where('id', '[0-9]+')->name('tickets.show');
            Route::post('/tickets/{id}/update', [$c, 'updateTicket'])->where('id', '[0-9]+')->name('tickets.update');
            Route::post('/tickets/{id}/note', [$c, 'addTicketNote'])->where('id', '[0-9]+')->name('tickets.note');
            // AMIAL-INSIDER-001: Maker-Checker + مراقبة الموظفين
            Route::get('/approvals', [$c, 'approvalsList'])->name('approvals.index');
            Route::post('/approvals/{id}/approve', [$c, 'approveRequest'])->where('id', '[0-9]+')->name('approvals.approve');
            Route::post('/approvals/{id}/reject', [$c, 'rejectRequest'])->where('id', '[0-9]+')->name('approvals.reject');
            Route::get('/insider/overview', [$c, 'insiderOverview'])->name('insider.overview');
            Route::post('/insider/alerts/{id}/ack', [$c, 'acknowledgeAlert'])->where('id', '[0-9]+')->name('insider.alerts.ack');
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
        Route::prefix('settlements')->name('settlements.')->group(function () {
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
        Route::post('/variances/{id}/resolve', [\App\Http\Controllers\Api\V1\Amial\AdminPanelController::class, 'resolveVariance'])
            ->where('id', '[0-9]+')->name('variances.resolve');
        Route::post('/merchants/{id}/verify', [\App\Http\Controllers\Api\V1\Amial\AdminPanelController::class, 'verifyMerchant'])
            ->where('id', '[0-9]+')->name('merchants.verify');

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
        Route::prefix('agent-settlements')->name('agent-settlements.')->group(function () {
            Route::get('/', [AgentNetworkController::class, 'adminSettlements'])->name('index');
            Route::post('/{ulid}/approve', [AgentNetworkController::class, 'adminApproveSettlement'])
                ->where('ulid', '[0-9A-Za-z]{26}')->name('approve');
            Route::post('/{ulid}/reject', [AgentNetworkController::class, 'adminRejectSettlement'])
                ->where('ulid', '[0-9A-Za-z]{26}')->name('reject');
        });
        Route::post('/agents/{id}/credit', [AgentNetworkController::class, 'adminCreditAgent'])
            ->where('id', '[0-9]+')->name('agents.credit');
    });

    // -------- Payment Requests (AMIAL-PAYMENT-REQUESTS-001) --------
    Route::prefix('payment-requests')->name('amial.payment-requests.')->group(function () {
        Route::post('/', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'create'])
            ->middleware('amial.rate-limit:payment_request_create,30,1')->name('create');
        Route::get('/', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'list'])->name('list');
        Route::get('/code/{code}', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'showByCode'])
            ->where('code', '[A-Z0-9]{6,8}')->name('show-by-code');
        Route::post('/code/{code}/pay', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'pay'])
            ->where('code', '[A-Z0-9]{6,8}')
            ->middleware('amial.rate-limit:payment_request_pay,30,1')->name('pay');
        Route::post('/{id}/cancel', [\App\Http\Controllers\Api\V1\Amial\PaymentRequestController::class, 'cancel'])
            ->where('id', '[0-9]+')->name('cancel');
    });

    // -------- Legal Terms --------
    // مهم: هذه خارج middleware 'amial.terms' لتجنب deadlock
    Route::prefix('legal')->name('amial.legal.')->group(function () {
        Route::get('/status', [LegalController::class, 'status'])->name('status');
        Route::get('/current', [LegalController::class, 'current'])->name('current');
        Route::post('/accept', [LegalController::class, 'accept'])->name('accept');
    });

    // -------- Account Recovery --------
    Route::prefix('recovery')->name('amial.recovery.')->group(function () {
        Route::post('/initiate-self', [AccountRecoveryController::class, 'initiateSelf'])
            ->name('initiate-self');

        Route::post('/initiate-lost', [AccountRecoveryController::class, 'initiateLost'])
            ->name('initiate-lost');

        Route::post('/{ulid}/verify-otp', [AccountRecoveryController::class, 'verifyOtp'])
            ->where('ulid', '[A-Z0-9]{26}')
            ->name('verify-otp');

        Route::post('/{ulid}/complete', [AccountRecoveryController::class, 'complete'])
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
    Route::prefix('receipts')->name('amial.receipts.')->group(function () {
        Route::get('/', [ReceiptController::class, 'index'])->name('index');
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
    });

    // -------- AMIAL-FUND-FAMILY-001 (v0.9-B) --------
    Route::prefix('funds')->name('amial.funds.')->group(function () {
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
    Route::prefix('bill-pay')->name('amial.bill-pay.')->group(function () {
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
    Route::prefix('safe-payments')->name('amial.safe-pay.')->middleware('feature:safe_payment')->group(function () {
        Route::get('/', [SafePaymentController::class, 'index'])->name('index');
        Route::post('/', [SafePaymentController::class, 'create'])
            ->middleware(['amial.zone:safe_payment_create', 'amial.rate-limit:safe_pay_create,5,1'])
            ->name('create');

        Route::get('/{ulid}', [SafePaymentController::class, 'show'])
            ->where('ulid', '[A-Z0-9]{26}')->name('show');

        // Seller actions
        Route::post('/{ulid}/seller-accept', [SafePaymentController::class, 'sellerAccept'])
            ->where('ulid', '[A-Z0-9]{26}')->name('seller-accept');
        Route::post('/{ulid}/seller-reject', [SafePaymentController::class, 'sellerReject'])
            ->where('ulid', '[A-Z0-9]{26}')->name('seller-reject');
        Route::post('/{ulid}/seller-mark-in-delivery', [SafePaymentController::class, 'sellerMarkInDelivery'])
            ->where('ulid', '[A-Z0-9]{26}')->name('seller-in-delivery');
        Route::post('/{ulid}/seller-mark-delivered', [SafePaymentController::class, 'sellerMarkDelivered'])
            ->where('ulid', '[A-Z0-9]{26}')->name('seller-delivered');

        // Buyer actions
        Route::post('/{ulid}/buyer-confirm', [SafePaymentController::class, 'buyerConfirm'])
            ->where('ulid', '[A-Z0-9]{26}')->name('buyer-confirm');
        Route::post('/{ulid}/buyer-cancel', [SafePaymentController::class, 'buyerCancel'])
            ->where('ulid', '[A-Z0-9]{26}')->name('buyer-cancel');
        Route::post('/{ulid}/buyer-dispute', [SafePaymentController::class, 'buyerDispute'])
            ->where('ulid', '[A-Z0-9]{26}')->name('buyer-dispute');
    });

    // -------- AMIAL-RECIPIENT-VERIFY-001 (v2.6) --------
    Route::prefix('transfer')->name('amial.transfer.')->group(function () {
        Route::post('/verify-recipient', [RecipientVerificationController::class, 'verify'])
            ->middleware('amial.rate-limit:verify_recipient,30,1')
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
    Route::prefix('merchant')->name('amial.merchant.')->group(function () {
        // P1-BRANCHES — إدارة الفروع
        Route::prefix('branches')->name('branches.')->group(function () {
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
            Route::post('/products', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'addProduct'])->name('products.add');
            Route::put('/products/{id}', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'updateProduct'])->name('products.update');
            Route::post('/sales', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'recordSale'])
                ->middleware('amial.rate-limit:cashier_sale,120,1')->name('sales');
            Route::post('/sales/{id}/settle', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'settleCredit'])->name('sales.settle');
            // AMIAL-CASHIER-REFUND-001 — قائمة مبيعات اليوم (مدخل شاشة الاسترجاع)
            Route::get('/sales', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'listSales'])->name('sales.list');
            Route::get('/report', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'report'])->name('report');
            // AMIAL-PROFIT-001 — تقرير الربحية (إيراد/تكلفة/ربح/هامش + اتجاه + منتجات)
            Route::get('/profit-report', [\App\Http\Controllers\Api\V1\Amial\CashierController::class, 'profitReport'])->name('profit-report');
        });

        // AMIAL-SUPPLIERS-001 — الموردون وأوامر الشراء (تصاميم 53/57/67/68)
        Route::prefix('suppliers')->name('suppliers.')->group(function () {
            $sc = \App\Http\Controllers\Api\V1\Amial\SupplierController::class;
            Route::get('/', [$sc, 'index'])->name('index');
            Route::post('/', [$sc, 'store'])->name('store');
            Route::get('/{id}', [$sc, 'show'])->where('id', '[0-9]+')->name('show');
            Route::post('/{id}/payment', [$sc, 'payment'])
                ->where('id', '[0-9]+')
                ->middleware('amial.rate-limit:supplier_payment,30,1')->name('payment');
        });
        Route::prefix('purchase-orders')->name('purchase-orders.')->group(function () {
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
            Route::get('/customers', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'listCustomers'])->name('customers');
            Route::post('/customers', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'upsertCustomer'])->name('customers.upsert');
            Route::get('/customers/{id}', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'showCustomer'])->name('customers.show');
            Route::get('/customers/{id}/statement', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditController::class, 'statement'])->name('customers.statement');
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
            Route::post('/products', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'addProduct'])->name('products.add');
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
                ->where('id', '[0-9]+')->name('companies.cards.index');
            Route::post('/companies/{id}/cards', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'addCard'])
                ->where('id', '[0-9]+')->name('companies.cards.add');
            Route::put('/companies/{id}/cards/{cardId}', [\App\Http\Controllers\Api\V1\Amial\FuelStationController::class, 'updateCard'])
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
                ->where('id', '[0-9]+')->name('batches.index');
            Route::post('/products/{id}/batches', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'addBatch'])
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

            // Alerts
            Route::get('/alerts', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'listAlerts'])->name('alerts.index');
            Route::post('/alerts/scan', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'scanExpiringBatches'])->name('alerts.scan');
            Route::post('/alerts/{id}/dismiss', [\App\Http\Controllers\Api\V1\Amial\PharmacyController::class, 'dismissAlert'])
                ->where('id', '[0-9]+')->name('alerts.dismiss');
        });

        // AMIAL-WHOLESALE-001 — قطاع تجارة الجملة
        Route::prefix('wholesale')->name('wholesale.')->group(function () {
            Route::get('/dashboard', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'dashboard'])->name('dashboard');

            // Business
            Route::get('/', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'getBusiness'])->name('show');
            Route::post('/', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'upsertBusiness'])->name('upsert');

            // Price Tiers
            Route::post('/price-tiers', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'addPriceTier'])->name('tiers.add');

            // Products
            Route::get('/products', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'listProducts'])->name('products.index');
            Route::post('/products', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'addProduct'])
                ->middleware('amial.usage:add_product')->name('products.add');
            Route::put('/products/{id}', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'updateProduct'])
                ->where('id', '[0-9]+')->name('products.update');
            Route::post('/products/{id}/adjust-stock', [\App\Http\Controllers\Api\V1\Amial\WholesaleController::class, 'adjustStock'])
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
    Route::prefix('merchant/corporate')->name('amial.merchant.corporate.')->group(function () {
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
        Route::post('/', [\App\Http\Controllers\Api\V1\Amial\MerchantStaffController::class, 'store'])->name('store');
        Route::post('/{id}/toggle', [\App\Http\Controllers\Api\V1\Amial\MerchantStaffController::class, 'toggle'])
            ->where('id', '[0-9]+')->name('toggle');
        Route::post('/{id}/operations-manager', [\App\Http\Controllers\Api\V1\Amial\MerchantStaffController::class, 'setOperationsManager'])
            ->where('id', '[0-9]+')->name('ops-manager');
        Route::post('/{id}/financial-manager', [\App\Http\Controllers\Api\V1\Amial\MerchantStaffController::class, 'setFinancialManager'])
            ->where('id', '[0-9]+')->name('fin-manager');
    });

    // -------- AMIAL-CUSTOMER-CREDIT-VIEW-001 — العميل يرى ما عليه من آجل --------
    Route::prefix('customer/credits')->name('amial.customer.credits.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditViewController::class, 'myAccounts'])->name('mine');
        Route::get('/{id}/statement', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditViewController::class, 'myStatement'])
            ->where('id', '[0-9]+')->name('statement');
        Route::post('/{id}/settle', [\App\Http\Controllers\Api\V1\Amial\CustomerCreditViewController::class, 'settle'])
            ->where('id', '[0-9]+')
            ->middleware('amial.rate-limit:credit_settle,30,1')->name('settle');
    });

    // -------- AMIAL-CUSTOMER-WITHDRAW-001 — السحب المبدوء من العميل --------
    Route::prefix('withdraw')->name('amial.withdraw.')->group(function () {
        Route::post('/request', [\App\Http\Controllers\Api\V1\Amial\CustomerWithdrawController::class, 'request'])
            ->middleware(['amial.zone:cash_out', 'amial.idempotency', 'amial.rate-limit:withdraw_req,20,1'])
            ->name('request');
        Route::get('/mine', [\App\Http\Controllers\Api\V1\Amial\CustomerWithdrawController::class, 'mine'])->name('mine');
        Route::post('/{id}/cancel', [\App\Http\Controllers\Api\V1\Amial\CustomerWithdrawController::class, 'cancel'])->name('cancel');
    });
    Route::prefix('agent/withdraw')->name('amial.agent.withdraw.')->group(function () {
        Route::post('/lookup', [\App\Http\Controllers\Api\V1\Amial\CustomerWithdrawController::class, 'lookup'])
            ->middleware('amial.rate-limit:withdraw_lookup,60,1')->name('lookup');
        Route::post('/execute', [\App\Http\Controllers\Api\V1\Amial\CustomerWithdrawController::class, 'execute'])
            ->middleware(['amial.zone:cash_out', 'amial.rate-limit:withdraw_exec,40,1'])->name('execute');
    });

    Route::prefix('split-bills')->name('amial.split-bills.')->group(function () {
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
            ->middleware('amial.rate-limit:agent_topup,10,60')->name('topup-request');
        Route::get('/settlements', [AgentNetworkController::class, 'settlements'])->name('settlements');
        Route::get('/distributor-network', [AgentNetworkController::class, 'distributorNetwork'])->name('distributor-network');
    });

    // -------- AMIAL-DONATIONS-001 (v1.2) --------
    Route::prefix('donations')->name('amial.donations.')->group(function () {
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
Route::get('/v/{code}', [ReceiptController::class, 'verifyPublic'])
    ->where('code', '[A-Z2-9]{16}')
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
