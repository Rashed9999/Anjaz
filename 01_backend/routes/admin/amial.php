<?php

use App\Http\Controllers\Admin\AccountRecoveryController;
use App\Http\Controllers\Admin\AdminAmlController;
use App\Http\Controllers\Admin\AdminCharityController;
use App\Http\Controllers\Admin\AdminSafePaymentController;
use App\Http\Controllers\Admin\AuditDecisionsController;
use App\Http\Controllers\Admin\LegalTermsController;
use App\Http\Controllers\Admin\OperatorRolesController;
use App\Http\Controllers\Admin\OpsConsoleController;
use App\Http\Controllers\Admin\SecurityEventsController;
use App\Http\Controllers\Admin\SupervisionController;
use App\Http\Controllers\Admin\ZoneManagementController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-ADMIN-001 (v0.8)
 *
 * Admin routes لـ Amial features. تُدرج في bootstrap/app.php عبر:
 *
 *   Route::middleware(['web', 'admin'])
 *       ->prefix('admin/amial')
 *       ->name('admin.amial.')
 *       ->group(base_path('routes/admin/amial.php'));
 *
 * أو يدوياً في routes/web.php (داخل group admin الموجود):
 *
 *   Route::prefix('amial')->name('amial.')->group(base_path('routes/admin/amial.php'));
 */

// ============ Zone Management ============
Route::prefix('zones')->name('zones.')->group(function () {
    Route::get('/', [ZoneManagementController::class, 'index'])->name('index');
    Route::post('/update', [ZoneManagementController::class, 'update'])->name('update');
});

// ============ Legal Terms ============
Route::prefix('legal')->name('legal.')->group(function () {
    Route::get('/', [LegalTermsController::class, 'webIndex'])->name('index');
    Route::get('/create', [LegalTermsController::class, 'webCreate'])->name('create');
    Route::post('/', [LegalTermsController::class, 'webStore'])->name('store');
    Route::get('/{id}', [LegalTermsController::class, 'webShow'])
        ->where('id', '[0-9]+')
        ->name('show');
});

// ============ Account Recovery ============
Route::prefix('recovery')->name('recovery.')->group(function () {
    Route::get('/', [AccountRecoveryController::class, 'webIndex'])->name('index');
    Route::get('/{ulid}', [AccountRecoveryController::class, 'webShow'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->name('show');
    Route::post('/{ulid}/approve', [AccountRecoveryController::class, 'webApprove'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->name('approve');
    Route::post('/{ulid}/reject', [AccountRecoveryController::class, 'webReject'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->name('reject');
});

// ============ Audit Decisions ============
Route::get('/audit', [AuditDecisionsController::class, 'index'])->name('audit.index');

// ============ Security Events ============
Route::get('/security-events', [SecurityEventsController::class, 'index'])->name('security-events.index');

// ============ AMIAL-SAFE-PAYMENT-001 (v1.1) — Disputes resolution ============
Route::prefix('safe-payments')->name('safe-payments.')->middleware('amial.idempotency')->group(function () {
    Route::get('/', [AdminSafePaymentController::class, 'index'])->name('index');
    // AMIAL-SAFEPAY-EVIDENCE-001 — قبل مسار {ulid} كي لا يبتلعه
    Route::get('/evidence/{id}/file', [AdminSafePaymentController::class, 'evidenceFile'])
        ->where('id', '[0-9]+')->name('evidence-file');
    Route::get('/{ulid}', [AdminSafePaymentController::class, 'show'])
        ->where('ulid', '[A-Z0-9]{26}')->name('show');
    Route::post('/{ulid}/release', [AdminSafePaymentController::class, 'resolveRelease'])
        ->where('ulid', '[A-Z0-9]{26}')->name('release');
    Route::post('/{ulid}/refund', [AdminSafePaymentController::class, 'resolveRefund'])
        ->where('ulid', '[A-Z0-9]{26}')->name('refund');
    Route::post('/{ulid}/partial', [AdminSafePaymentController::class, 'resolvePartial'])
        ->where('ulid', '[A-Z0-9]{26}')->name('partial');
});

// ============ AMIAL-DONATIONS-001 (v1.2) ============
// AMIAL-SURFACE-002 — لوحات الأنظمة اليتيمة الأربع
Route::prefix('surface')->name('surface.')->group(function () {
    $sc = App\Http\Controllers\Admin\AdminSurfaceController::class;
    Route::get('/bill-providers', [$sc, 'billProviders'])->name('bill-providers');
    Route::post('/bill-providers/{id}/toggle', [$sc, 'toggleBillProvider'])->where('id', '[0-9]+')->name('bill-providers.toggle');
    Route::get('/funds', [$sc, 'funds'])->name('funds');
    Route::get('/payment-requests', [$sc, 'paymentRequests'])->name('payment-requests');
    Route::get('/rbac', [$sc, 'rbac'])->name('rbac');
});

Route::prefix('charity')->name('charity.')->middleware('amial.idempotency')->group(function () {
    // AMIAL-CHARITY-ADMIN-UI-001: صفحة اللوحة (الواجهة)
    Route::view('/', 'admin-views.amial.charity.index')->name('page');
    // Organizations
    Route::get('/organizations', [AdminCharityController::class, 'indexOrgs'])->name('orgs.index');
    Route::post('/organizations', [AdminCharityController::class, 'createOrg'])->name('orgs.create');
    Route::get('/organizations/{ulid}', [AdminCharityController::class, 'showOrg'])
        ->where('ulid', '[A-Z0-9]{26}')->name('orgs.show');
    Route::post('/organizations/{ulid}/verify', [AdminCharityController::class, 'verifyOrg'])
        ->where('ulid', '[A-Z0-9]{26}')->name('orgs.verify');
    Route::post('/organizations/{ulid}/reject', [AdminCharityController::class, 'rejectOrg'])
        ->where('ulid', '[A-Z0-9]{26}')->name('orgs.reject');
    Route::post('/organizations/{ulid}/suspend', [AdminCharityController::class, 'suspendOrg'])
        ->where('ulid', '[A-Z0-9]{26}')->name('orgs.suspend');

    // Campaigns
    Route::get('/campaigns', [AdminCharityController::class, 'indexCampaigns'])->name('campaigns.index');
    Route::post('/organizations/{orgUlid}/campaigns', [AdminCharityController::class, 'createCampaign'])
        ->where('orgUlid', '[A-Z0-9]{26}')->name('campaigns.create');
    Route::post('/campaigns/{ulid}/approve', [AdminCharityController::class, 'approveCampaign'])
        ->where('ulid', '[A-Z0-9]{26}')->name('campaigns.approve');
    Route::post('/campaigns/{ulid}/pause', [AdminCharityController::class, 'pauseCampaign'])
        ->where('ulid', '[A-Z0-9]{26}')->name('campaigns.pause');

    // Settlements
    Route::get('/settlements', [AdminCharityController::class, 'indexSettlements'])->name('settlements.index');
    Route::post('/settlements/generate', [AdminCharityController::class, 'generateSettlement'])->name('settlements.generate');
    Route::post('/settlements/{ulid}/transferred', [AdminCharityController::class, 'markTransferred'])
        ->where('ulid', '[A-Z0-9]{26}')->name('settlements.transferred');
});

// ============ AMIAL-AML-001 (v1.4) ============
Route::prefix('aml')->name('aml.')->group(function () {
    // AMIAL-AML-PANEL-001 — الصفحة. كلّ ما تحتها كان JSON بلا مُشغِّل.
    Route::get('/', [AdminAmlController::class, 'page'])->name('page');

    // Rules
    Route::get('/rules', [AdminAmlController::class, 'indexRules'])->name('rules.index');
    Route::get('/rules/{id}', [AdminAmlController::class, 'showRule'])->name('rules.show');
    Route::post('/rules/{id}/toggle', [AdminAmlController::class, 'toggleRule'])->name('rules.toggle');
    Route::patch('/rules/{id}', [AdminAmlController::class, 'updateRule'])->name('rules.update');

    // Flagged transactions
    Route::get('/flagged', [AdminAmlController::class, 'indexFlagged'])->name('flagged.index');
    Route::get('/flagged/{ulid}', [AdminAmlController::class, 'showFlagged'])
        ->where('ulid', '[A-Z0-9]{26}')->name('flagged.show');
    Route::post('/flagged/{ulid}/approve', [AdminAmlController::class, 'approveFlagged'])
        ->where('ulid', '[A-Z0-9]{26}')->name('flagged.approve');
    Route::post('/flagged/{ulid}/reject', [AdminAmlController::class, 'rejectFlagged'])
        ->where('ulid', '[A-Z0-9]{26}')->name('flagged.reject');

    // Alerts
    Route::get('/alerts', [AdminAmlController::class, 'indexAlerts'])->name('alerts.index');
    Route::post('/alerts/{ulid}/resolve', [AdminAmlController::class, 'resolveAlert'])
        ->where('ulid', '[A-Z0-9]{26}')->name('alerts.resolve');

    // AMIAL-AML-DASHBOARD-001 — المؤشّرات والتبويبات ٢ و٣ و٦
    Route::get('/dashboard', [AdminAmlController::class, 'dashboard'])->name('dashboard');
    Route::get('/large-transactions', [AdminAmlController::class, 'largeTransactions'])->name('large.index');
    Route::get('/structuring', [AdminAmlController::class, 'structuring'])->name('structuring.index');
    Route::get('/sanctions', [AdminAmlController::class, 'sanctions'])->name('sanctions.index');
    Route::post('/sanctions/{id}/review', [AdminAmlController::class, 'reviewSanction'])
        ->where('id', '[0-9]+')->name('sanctions.review');

    // AMIAL-AML-INVESTIGATION-001 — مركز التحقيقات (الفصل ١٠، التبويب ٧)
    Route::get('/investigations', [AdminAmlController::class, 'indexInvestigations'])->name('investigations.index');
    Route::post('/investigations', [AdminAmlController::class, 'openInvestigation'])->name('investigations.open');
    Route::get('/investigations/{id}', [AdminAmlController::class, 'showInvestigation'])
        ->where('id', '[0-9]+')->name('investigations.show');
    Route::post('/investigations/{id}/evidence', [AdminAmlController::class, 'investigationEvidence'])
        ->where('id', '[0-9]+')->name('investigations.evidence');
    Route::post('/investigations/{id}/action', [AdminAmlController::class, 'investigationAction'])
        ->where('id', '[0-9]+')->name('investigations.action');
    Route::post('/investigations/{id}/close', [AdminAmlController::class, 'closeInvestigation'])
        ->where('id', '[0-9]+')->name('investigations.close');
    Route::post('/investigations/{id}/reopen', [AdminAmlController::class, 'reopenInvestigation'])
        ->where('id', '[0-9]+')->name('investigations.reopen');
    Route::post('/investigations/{id}/str', [AdminAmlController::class, 'generateStr'])
        ->where('id', '[0-9]+')->name('investigations.str');

    // AMIAL-AML-REGREPORT-001 — التقارير التنظيمية (الفصل ١٠، التبويب ٨)
    Route::get('/reports', [AdminAmlController::class, 'indexReports'])->name('reports.index');
    Route::post('/reports/ctr', [AdminAmlController::class, 'generateCtr'])->name('reports.ctr');
    Route::post('/reports/{id}/submit', [AdminAmlController::class, 'submitReport'])
        ->where('id', '[0-9]+')->name('reports.submit');

    // User risk profiles
    Route::get('/users/{userId}/profile', [AdminAmlController::class, 'showUserProfile'])
        ->where('userId', '[0-9]+')->name('users.profile');
    Route::post('/users/{userId}/override', [AdminAmlController::class, 'setUserOverride'])
        ->where('userId', '[0-9]+')->name('users.override');
});

// ============ AMIAL-CUSTOMER-CENTER-001 — مركز العملاء (الفصل ٠٢) ============
//
// شاشةٌ واحدة تُدار منها كلّ شؤون العميل — والوثيقة تشترط ذلك حرفياً:
// «دون الحاجة للانتقال بين أكثر من لوحة».
Route::prefix('customer')->name('customer.')->middleware('platform:platform.customers.view')
    ->group(function () {
        $cc = App\Http\Controllers\Admin\CustomerCenterController::class;

        Route::get('/', [$cc, 'page'])->name('page');
        Route::get('/search', [$cc, 'search'])->name('search');
        Route::get('/{id}/tab/{tab}', [$cc, 'tab'])
            ->where(['id' => '[0-9]+', 'tab' => '[a-z]+'])->name('tab');
        Route::post('/{id}/action', [$cc, 'act'])->where('id', '[0-9]+')->name('action');
    });

// ============ AMIAL-FUEL-VERTICAL-001 — مركز محطات الوقود (المرحلة ٩) ============
//
// **رقابةٌ لا إدارة**: تُرى المحطّاتُ وخزّاناتُها وفروقاتُها، ولا يُدار
// موظّفوها ولا تُعتمد أسعارُها — ذاك للتاجر. والصلاحيّة `platform.audit.view`
// لأنّ قراءةَ فروقات المخزون من جنس التدقيق.
Route::prefix('fuel')->name('fuel.')->middleware('platform:platform.audit.view')
    ->group(function () {
        $fc = App\Http\Controllers\Admin\FuelCenterController::class;

        Route::get('/', [$fc, 'page'])->name('page');
        Route::get('/stations', [$fc, 'stations'])->name('stations');
        Route::get('/stations/{id}', [$fc, 'station'])
            ->where('id', '[0-9]+')->name('station');
        Route::get('/open-variances', [$fc, 'openVariances'])->name('open-variances');
    });

// ============ AMIAL-RETAIL-VERTICAL-001 · المرحلة ١١ — مركز التجزئة ============
//
// رقابةٌ لا إدارة: لا تعتمد اللوحةُ جردَ تاجرٍ ولا هالكَه — ذاك له.
// والصلاحيّة `platform.audit.view` لأنّ قراءة المخزون السالب وفروق الجرد
// من جنس التدقيق.
Route::prefix('retail')->name('retail.')->middleware('platform:platform.audit.view')
    ->group(function () {
        $rc = App\Http\Controllers\Admin\RetailCenterController::class;

        Route::get('/', [$rc, 'page'])->name('page');
        Route::get('/overview', [$rc, 'overview'])->name('overview');
        Route::get('/merchants', [$rc, 'merchants'])->name('merchants');
        Route::get('/merchants/{id}', [$rc, 'merchant'])
            ->where('id', '[0-9]+')->name('merchant');
        Route::get('/negative-stock', [$rc, 'negativeStock'])->name('negative-stock');
        Route::get('/stuck-transfers', [$rc, 'stuckTransfers'])->name('stuck-transfers');
    });

// ============ AMIAL-LEDGER-CENTER-001 — مركز الدفتر (الفصل ١٧) ============
//
// الصلاحية `platform.audit.view`: قراءة الدفتر اطّلاعٌ على حركة المال كلّها،
// وهي من جنس التدقيق لا من جنس خدمة العملاء.
Route::prefix('ledger')->name('ledger.')->middleware('platform:platform.audit.view')
    ->group(function () {
        $lc = App\Http\Controllers\Admin\LedgerCenterController::class;

        Route::get('/', [$lc, 'page'])->name('page');
        Route::get('/trial-balance', [$lc, 'trialBalance'])->name('trial-balance');
        Route::get('/accounts', [$lc, 'accounts'])->name('accounts');
        Route::get('/accounts/{id}/statement', [$lc, 'statement'])
            ->where('id', '[0-9]+')->name('statement');
        Route::get('/reconciliation', [$lc, 'reconciliation'])->name('reconciliation');
        Route::get('/entries', [$lc, 'entries'])->name('entries');
        // AMIAL-RECON-NIGHTLY-001: تاريخُ المصالحات — القاعدة ١٢.
        Route::get('/reconciliation-runs', [$lc, 'reconciliationRuns'])->name('reconciliation-runs');
    });

// ============ AMIAL-OTP-CENTER-001 — مركز التحقّق ============
//
// الصلاحيّة `platform.settings.update`: فتحُ بابِ تحقّقٍ أو إقفالُه ضبطُ
// منصّةٍ لا خدمةُ عملاء — وموظّفُ الدعم لا يملكها.
// AMIAL-CATALOG-001 — مركز كتالوج المنتجات (مفتوحٌ بمراجعة).
// الصلاحيّة `platform.settings.update`: هذا ضبطُ بياناتٍ مرجعيّة لا
// حركةُ مال — فلا تُطلب صلاحيّةُ المال ولا تُترك بلا حارس.
Route::prefix('catalog')->name('catalog.')->middleware('platform:platform.settings.update')
    ->group(function () {
        $cc = App\Http\Controllers\Admin\ProductCatalogCenterController::class;
        Route::get('/', [$cc, 'page'])->name('page');
        Route::get('/stats', [$cc, 'stats'])->name('stats');
        Route::get('/rows', [$cc, 'rows'])->name('rows');
        Route::get('/export', [$cc, 'export'])->name('export');
        Route::post('/', [$cc, 'store'])->name('store');
        Route::post('/import', [$cc, 'import'])->name('import');
        Route::get('/{id}', [$cc, 'show'])->where('id', '[0-9]+')->name('show');
        Route::post('/{id}/review', [$cc, 'review'])->where('id', '[0-9]+')->name('review');
    });

// AMIAL-WA-LIMIT-001 — سقفُ المال عبر بوت واتساب.
// الصلاحيّة `platform.money.move`: رفعُ السقف يُحرّك مالاً بالوكالة —
// لا يُحرّكه بنفسه، لكنّه يسمح بحركةٍ كانت ممنوعة.
Route::prefix('whatsapp')->name('whatsapp.')->middleware('platform:platform.money.move')
    ->group(function () {
        $wl = App\Http\Controllers\Admin\WhatsappLimitController::class;
        Route::get('/limits', [$wl, 'page'])->name('limits.page');
        Route::get('/limits/show', [$wl, 'show'])->name('limits.show');
        Route::post('/limits', [$wl, 'save'])->name('limits.save');
    });

// AMIAL-MERCHANT-PAY-002 — مركز فواتير التجّار.
// يُقرأ من `payment_requests` نفسِه الذي يكتب فيه التطبيق — جذرٌ واحدٌ
// يُقرأ من زاويتين، لا حقيقتان تفترقان.
Route::prefix('invoices')->name('invoices.')->middleware('platform:platform.money.move')
    ->group(function () {
        $ic = App\Http\Controllers\Admin\MerchantInvoiceCenterController::class;
        Route::get('/', [$ic, 'page'])->name('page');
        Route::get('/stats', [$ic, 'stats'])->name('stats');
        Route::get('/rows', [$ic, 'rows'])->name('rows');
        Route::get('/export', [$ic, 'export'])->name('export');
        Route::get('/{id}', [$ic, 'show'])->where('id', '[0-9]+')->name('show');
        Route::post('/{id}/cancel', [$ic, 'cancel'])->where('id', '[0-9]+')->name('cancel');
    });

Route::prefix('otp')->name('otp.')->middleware('platform:platform.settings.update')
    ->group(function () {
        $oc = App\Http\Controllers\Admin\OtpCenterController::class;

        Route::get('/', [$oc, 'page'])->name('page');
        Route::get('/stats', [$oc, 'stats'])->name('stats');
        Route::get('/numbers', [$oc, 'numbers'])->name('numbers');
        Route::get('/export', [$oc, 'export'])->name('export');

        Route::post('/numbers', [$oc, 'store'])->name('numbers.store');
        Route::post('/numbers/{id}/toggle', [$oc, 'toggle'])->where('id', '[0-9]+')->name('numbers.toggle');
        Route::post('/close-door', [$oc, 'closeDoor'])->name('close-door');
        Route::post('/test-send', [$oc, 'testSend'])->name('test-send');
    });

// ============ AMIAL-SETTLEMENT-PANEL-001 — تسويات الشركاء ============
//
// غير `settlements` أدناه: تلك تسويات الوكلاء (`AgentSettlement`). هذه
// تسويات الشركاء (`Settlement`) — وعليها بُنيت الموافقة المزدوجة، وكان
// سطحها الوحيد الـAPI فبقي الضابط بلا شاشة تُظهره.
Route::prefix('partner-settlements')->name('partner-settlements.')->middleware('amial.idempotency')->group(function () {
    $st = App\Http\Controllers\Api\V1\Amial\SettlementController::class;

    Route::get('/', [$st, 'page'])->name('page');
    Route::get('/list', [$st, 'index'])->name('list');
    Route::get('/dashboard', [$st, 'dashboard'])->name('dashboard');
    Route::get('/{id}', [$st, 'show'])->where('id', '[0-9]+')->name('show');
    Route::post('/{id}/submit', [$st, 'submit'])->where('id', '[0-9]+')->name('submit');
    Route::post('/{id}/approve', [$st, 'approve'])->where('id', '[0-9]+')->name('approve');
    Route::post('/{id}/reject', [$st, 'reject'])->where('id', '[0-9]+')->name('reject');
    Route::post('/{id}/process', [$st, 'process'])->where('id', '[0-9]+')->name('process');
    Route::post('/{id}/complete', [$st, 'complete'])->where('id', '[0-9]+')->name('complete');
    Route::post('/{id}/cancel', [$st, 'cancel'])->where('id', '[0-9]+')->name('cancel');
});

// ============ AMIAL-KYC-PANEL-001 — مراجعة مستندات الهوية ============
//
// كانت هذه النقاط مسجَّلة على سطح الـAPI وحده، فبقي المستند يصل بلا مراجع.
// الصلاحية هي `platform.customers.freeze` نفسها المستعملة في الـAPI: اعتماد
// هويّة يفتح حدوداً مالية أعلى، فهو من جنس القرارات التي تمسّ حساب العميل.
Route::prefix('kyc')->name('kyc.')->group(function () {
    $kyc = App\Http\Controllers\Api\V1\Amial\KycDocumentController::class;

    Route::get('/', [$kyc, 'page'])->middleware('platform:platform.customers.freeze')->name('page');
    Route::get('/queue', [$kyc, 'queue'])->middleware('platform:platform.customers.freeze')->name('queue');
    Route::get('/documents/{id}/file', [$kyc, 'file'])
        ->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('file');
    // AMIAL-KYC-OCR-001 — الحقول المستخرَجة وإقرارها
    Route::get('/documents/{id}/ocr', [$kyc, 'ocr'])
        ->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('ocr');
    Route::post('/documents/{id}/fields', [$kyc, 'confirmFields'])
        ->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('fields');
    Route::post('/documents/{id}/reread', [$kyc, 'reread'])
        ->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('reread');

    Route::post('/documents/{id}/approve', [$kyc, 'approve'])
        ->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('approve');
    Route::post('/documents/{id}/reject', [$kyc, 'reject'])
        ->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('reject');
});

// ============ AMIAL-2FA-001 (v1.8) ============
Route::prefix('2fa')->name('2fa.')->group(function () {
    Route::get('/status', [App\Http\Controllers\Admin\Admin2FAController::class, 'status'])->name('status');
    Route::post('/setup', [App\Http\Controllers\Admin\Admin2FAController::class, 'setup'])->name('setup');
    Route::post('/confirm', [App\Http\Controllers\Admin\Admin2FAController::class, 'confirm'])->name('confirm');
    Route::post('/disable', [App\Http\Controllers\Admin\Admin2FAController::class, 'disable'])->name('disable');
    Route::post('/regenerate-recovery-codes', [App\Http\Controllers\Admin\Admin2FAController::class, 'regenerateRecoveryCodes'])->name('regenerate');
});

// ============ AMIAL-ZONE-ASSIGN-001 (v2.0) ============
Route::prefix('zone')->name('zone.')->group(function () {
    Route::post('/assign', [App\Http\Controllers\Admin\AdminZoneController::class, 'assign'])->name('assign');
    Route::post('/assign-from-kyc', [App\Http\Controllers\Admin\AdminZoneController::class, 'assignFromKyc'])->name('assign-kyc');
    Route::get('/logs/{userId}', [App\Http\Controllers\Admin\AdminZoneController::class, 'logs'])->name('logs');
    Route::get('/stats', [App\Http\Controllers\Admin\AdminZoneController::class, 'stats'])->name('stats');
});

// ============ AMIAL-AGENT-NETWORK-001 (v2.4) ============
Route::prefix('agents')->name('agents.')->group(function () {
    Route::get('/', [App\Http\Controllers\Admin\AdminAgentNetworkController::class, 'index'])->name('index');
    Route::get('/network-stats', [App\Http\Controllers\Admin\AdminAgentNetworkController::class, 'networkStats'])->name('network-stats');
    Route::post('/{userId}/approve', [App\Http\Controllers\Admin\AdminAgentNetworkController::class, 'approve'])->name('approve');
    Route::post('/{userId}/suspend', [App\Http\Controllers\Admin\AdminAgentNetworkController::class, 'suspend'])->name('suspend');
    Route::put('/{userId}/limits', [App\Http\Controllers\Admin\AdminAgentNetworkController::class, 'updateLimits'])->name('limits');
});
// AMIAL-OPERATOR-RBAC-003: اعتمادُ تسويةٍ تحريكُ مالٍ حقيقيّ.
Route::prefix('settlements')->name('settlements.')->middleware(['platform:platform.money.move', 'amial.idempotency'])
    ->group(function () {
    Route::get('/pending', [App\Http\Controllers\Admin\AdminAgentNetworkController::class, 'pendingSettlements'])->name('pending');
    Route::post('/{ulid}/approve', [App\Http\Controllers\Admin\AdminAgentNetworkController::class, 'approveSettlement'])->name('approve');
});

// ============ AMIAL-MERCHANT-RISK-001 (v2.10) ============
Route::prefix('merchants')->name('merchants.')->group(function () {
    Route::get('/high-risk', [App\Http\Controllers\Admin\AdminMerchantRiskController::class, 'highRisk'])->name('high-risk');
    Route::get('/risk-stats', [App\Http\Controllers\Admin\AdminMerchantRiskController::class, 'riskStats'])->name('risk-stats');
    Route::get('/{userId}/risk', [App\Http\Controllers\Admin\AdminMerchantRiskController::class, 'riskDashboard'])->name('risk');
    Route::put('/{userId}/tier', [App\Http\Controllers\Admin\AdminMerchantRiskController::class, 'setTier'])->name('tier');
    Route::post('/{userId}/verify', [App\Http\Controllers\Admin\AdminMerchantRiskController::class, 'verify'])->name('verify');
});

// ============ AMIAL-FEE-ENGINE-001 (v2.12) ============
// لوحة تحكم نسب الأرباح/الرسوم
//
// AMIAL-OPERATOR-RBAC-003: نسبةُ ربحٍ تُغيَّر مرّةً يبقى أثرُها على كلّ
// عمليّةٍ بعدها. فلا تُترك لكلّ من دخل اللوحة — لمدير المنصّة وحده.
Route::prefix('fees')->name('fees.')->middleware('platform:platform.fees.update')
    ->group(function () {
    Route::get('/', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webIndex'])->name('index');
    Route::get('/create', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webCreate'])->name('create');
    Route::get('/profit', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webProfit'])->name('profit');
    Route::post('/', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webStore'])->name('store');
    Route::post('/simulate', [App\Http\Controllers\Admin\FeeSchemeController::class, 'simulate'])->name('simulate');
    Route::get('/history/{code}', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webHistory'])->name('history');
    Route::post('/{id}/deactivate', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webDeactivate'])->name('deactivate');
});

// ============ AMIAL-SENTINEL-001 — Security Sentinel Dashboard ============
Route::prefix('sentinel')->name('sentinel.')->group(function () {
    Route::get('/', [App\Http\Controllers\Admin\SentinelDashboardController::class, 'index'])->name('index');
    Route::post('/block', [App\Http\Controllers\Admin\SentinelDashboardController::class, 'block'])->name('block');
    Route::post('/unblock', [App\Http\Controllers\Admin\SentinelDashboardController::class, 'unblock'])->name('unblock');
});

// ============ AMIAL-EXEC-DASHBOARD-001 — Executive Dashboard ============
Route::prefix('executive')->name('executive.')->group(function () {
    Route::get('/', [App\Http\Controllers\Admin\ExecutiveDashboardController::class, 'index'])->name('index');
    Route::get('/summary', [App\Http\Controllers\Admin\ExecutiveDashboardController::class, 'summary'])->name('summary');
});

// ============ AMIAL-ADMIN-HUB-001 — اللوحات المركزية الأربع ============
Route::prefix('hub')->name('hub.')->middleware('amial.idempotency')->group(function () {
    $hc = App\Http\Controllers\Admin\AdminHubController::class;

    // الصفحات
    Route::get('/customers', [$hc, 'customers'])->name('customers');
    Route::get('/agents', [$hc, 'agents'])->name('agents');
    Route::get('/merchants', [$hc, 'merchants'])->name('merchants');
    // AMIAL-OPERATOR-RBAC-003: الصفحة لا الفعلَ وحده — صفحةٌ تعرض أرصدة
    // المنصّة وحركتها تُسرّب ما لا يجوز، وإن كان زرُّها محروساً.
    Route::get('/finance', [$hc, 'finance'])
        ->middleware('platform:platform.money.move')->name('finance');

    // JSON قوائم
    Route::get('/{slug}/users.json', [$hc, 'usersJson'])
        ->where('slug', 'customers|agents|merchants')->name('users.json');
    Route::get('/{slug}/kyc.json', [$hc, 'kycJson'])
        ->where('slug', 'customers|agents|merchants')->name('kyc.json');
    Route::get('/users/{id}/transactions.json', [$hc, 'userTransactionsJson'])
        ->where('id', '[0-9]+')->name('users.transactions');

    // صفحة تفاصيل الحساب الكاملة (بيانات + وثائق + مخاطر AML + سجل + إضافات الدور)
    Route::get('/account/{id}', [$hc, 'account'])->where('id', '[0-9]+')->name('account');
    Route::get('/users/{id}/detail.json', [$hc, 'accountDetailJson'])
        ->where('id', '[0-9]+')->name('users.detail');

    // إجراءات
    Route::post('/{slug}/users', [$hc, 'storeUser'])
        ->where('slug', 'customers|agents|merchants')->name('users.store');
    Route::post('/users/{id}/toggle-active', [$hc, 'toggleActive'])
        ->where('id', '[0-9]+')->name('users.toggle-active');
    Route::post('/users/{id}/kyc', [$hc, 'kycStatus'])
        ->where('id', '[0-9]+')->name('users.kyc');
    Route::post('/transfer', [$hc, 'transfer'])->name('transfer');
    Route::post('/agents/{id}/credit', [$hc, 'agentCredit'])
        ->where('id', '[0-9]+')->name('agents.credit');

    // AMIAL-AGENT-SUPERVISION-001 — إشراف الإدارة على شبكة شركات الصرافة
    $asc = App\Http\Controllers\Admin\AgentSupervisionController::class;
    Route::get('/agents/network.json', [$asc, 'network'])->name('agents.network');
    Route::get('/agents/branches.json', [$asc, 'branches'])->name('agents.branches');
    Route::get('/agents/movements.json', [$asc, 'movements'])->name('agents.movements');

    // AMIAL-SETTLEMENT-ENGINE-001 — التوازن بين الرصيد والنقد
    Route::get('/agents/settlement.json', [$asc, 'settlementScan'])->name('agents.settlement');
    Route::get('/agents/{id}/settlement.json', [$asc, 'agentSettlement'])
        ->where('id', '[0-9]+')->name('agents.settlement.one');

    // AMIAL-DAILY-SETTLEMENT-001 — إقفال يوم الشبكة والتحوّل ورقاً/رصيداً
    Route::get('/agents/daily.json', [$asc, 'dailyBoard'])->name('agents.daily');
    Route::get('/agents/daily/{ulid}.json', [$asc, 'dailyOne'])->name('agents.daily.one');
    Route::post('/agents/daily/{ulid}/accept', [$asc, 'dailyAccept'])->name('agents.daily.accept');
    Route::post('/agents/daily/{ulid}/reject', [$asc, 'dailyReject'])->name('agents.daily.reject');
    Route::post('/agents/daily/unlock', [$asc, 'dailyUnlock'])->name('agents.daily.unlock');

    // المالية
    // AMIAL-OPERATOR-RBAC-003: شحنُ محفظة وكيلٍ من المنصّة.
    Route::post('/finance/topup', [$hc, 'adminTopup'])
        ->middleware('platform:platform.money.move')->name('finance.topup');
    Route::get('/finance/stats.json', [$hc, 'financeStats'])
        ->middleware('platform:platform.money.move')->name('finance.stats');
    Route::get('/finance/feed.json', [$hc, 'financeFeed'])
        ->middleware('platform:platform.money.move')->name('finance.feed');

    // لوحة الاشتراكات (الباقات) — حقيقية عبر SubscriptionService
    Route::get('/subscriptions', [$hc, 'subscriptions'])->name('subscriptions');
    Route::get('/subscriptions/list.json', [$hc, 'subsList'])->name('subscriptions.list');
    Route::post('/subscriptions/{merchantId}/plan', [$hc, 'subsChangePlan'])
        ->where('merchantId', '[0-9]+')->name('subscriptions.plan');
    Route::post('/subscriptions/{merchantId}/extend', [$hc, 'subsExtend'])
        ->where('merchantId', '[0-9]+')->name('subscriptions.extend');

    // لوحة النزاعات — واجهة فوق مسارات safe-payments الموجودة (JSON)
    Route::get('/disputes', [$hc, 'disputes'])->name('disputes');

    // لوحة التحقق — اعتماد/رفض/حظر الحسابات المسجَّلة ذاتياً (كل الأدوار)
    // AMIAL-ZONE-PANEL-001 — لوحة المناطق (نطاق التشغيل، العالقون، المخالفات)
    Route::prefix('zones')->name('zones.')->group(function () {
        $zc = App\Http\Controllers\Admin\ZoneControlController::class;
        Route::get('/', [$zc, 'index'])->name('index');
        Route::get('/summary.json', [$zc, 'summary'])->name('summary');
        Route::get('/events.json', [$zc, 'events'])->name('events');
        Route::get('/users/{id}/geo-check.json', [$zc, 'geoCheck'])
            ->where('id', '[0-9]+')->name('geo-check');
        Route::post('/users/{id}/reassign', [$zc, 'reassign'])
            ->where('id', '[0-9]+')->name('reassign');
    });

    Route::get('/verification', [$hc, 'verification'])->name('verification');
    Route::get('/verification/list.json', [$hc, 'verificationJson'])->name('verification.list');

    // لوحة التسويات — تسويات الوكلاء (اعتماد/رفض مع دفتر القيود)
    Route::get('/settlements', [$hc, 'settlements'])
        ->middleware('platform:platform.money.move')->name('settlements');
    // والـJSON كذلك: حراسةُ الصفحة وحدها تترك البيانات مفتوحةً لمن يعرف
    // عنوانها — وهو أوّل ما يُجرَّب.
    Route::get('/settlements/list.json', [$hc, 'settlementsJson'])
        ->middleware('platform:platform.money.move')->name('settlements.list');
    Route::post('/settlements/{ulid}/approve', [$hc, 'settlementApprove'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->middleware('platform:platform.money.move')->name('settlements.approve');
    Route::post('/settlements/{ulid}/reject', [$hc, 'settlementReject'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->middleware('platform:platform.money.move')->name('settlements.reject');

    // لوحة الموظفين — طاقم نقاط بيع التجّار (تفعيل/تعطيل)
    Route::get('/staff', [$hc, 'staff'])->name('staff');
    Route::get('/staff/list.json', [$hc, 'staffJson'])->name('staff.list');
    Route::post('/staff/{id}/toggle-active', [$hc, 'staffToggle'])
        ->where('id', '[0-9]+')->name('staff.toggle');

    // لوحة الإعدادات — تحكّم بضغطة زر (بلا كود)
    Route::get('/settings', [$hc, 'settings'])->name('settings');
    Route::post('/settings/flag', [$hc, 'settingsToggle'])->name('settings.flag');
});

// ============ AMIAL-OPS-CONSOLE-001 — حالة التشغيل (فريق الصيانة) ============
//
// العرض يحتاج ops.view، وإعادة التشغيل تحتاج ops.retry — فمن يراقب ليس
// بالضرورة من يتدخّل، وفصلُهما يسمح بمنح المراقبة لمن لا يُؤذن له بالتغيير.
Route::prefix('ops')->name('ops.')->group(function () {
    Route::get('/', [OpsConsoleController::class, 'index'])
        ->middleware('platform:platform.ops.view')->name('index');

    Route::post('/retry', [OpsConsoleController::class, 'retry'])
        ->middleware('platform:platform.ops.retry')->name('retry');

    Route::post('/pdf-doctor', [OpsConsoleController::class, 'pdfDoctor'])
        ->middleware('platform:platform.ops.retry')->name('pdf-doctor');

    // إسناد الأدوار: من يمنح دور مدير المنصّة يمنح كل شيء دفعةً واحدة،
    // فلا يملكه إلا مدير المنصّة. ولذلك رُبط بأخطر صلاحية لا بصلاحية تشغيل.
    Route::get('/roles', [OperatorRolesController::class, 'index'])
        ->middleware('platform:platform.settings.update')->name('roles.index');

    Route::post('/roles/{userId}', [OperatorRolesController::class, 'update'])
        ->where('userId', '[0-9]+')
        ->middleware('platform:platform.settings.update')->name('roles.update');
});

// ============ AMIAL-SUPERVISION-001 — لوحة الإشراف ============
//
// قراءةٌ محضة: الإشراف رقابةٌ على التنفيذ لا تنفيذ، فلا فعل هنا يُغيّر شيئاً.
Route::get('/supervision', [SupervisionController::class, 'index'])
    ->middleware('platform:platform.audit.view')->name('supervision.index');
