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
Route::prefix('safe-payments')->name('safe-payments.')->group(function () {
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

Route::prefix('charity')->name('charity.')->group(function () {
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

    // User risk profiles
    Route::get('/users/{userId}/profile', [AdminAmlController::class, 'showUserProfile'])
        ->where('userId', '[0-9]+')->name('users.profile');
    Route::post('/users/{userId}/override', [AdminAmlController::class, 'setUserOverride'])
        ->where('userId', '[0-9]+')->name('users.override');
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
Route::prefix('settlements')->name('settlements.')->group(function () {
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
Route::prefix('fees')->name('fees.')->group(function () {
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
Route::prefix('hub')->name('hub.')->group(function () {
    $hc = App\Http\Controllers\Admin\AdminHubController::class;

    // الصفحات
    Route::get('/customers', [$hc, 'customers'])->name('customers');
    Route::get('/agents', [$hc, 'agents'])->name('agents');
    Route::get('/merchants', [$hc, 'merchants'])->name('merchants');
    Route::get('/finance', [$hc, 'finance'])->name('finance');

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

    // المالية
    Route::post('/finance/topup', [$hc, 'adminTopup'])->name('finance.topup');
    Route::get('/finance/stats.json', [$hc, 'financeStats'])->name('finance.stats');
    Route::get('/finance/feed.json', [$hc, 'financeFeed'])->name('finance.feed');

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
    Route::get('/settlements', [$hc, 'settlements'])->name('settlements');
    Route::get('/settlements/list.json', [$hc, 'settlementsJson'])->name('settlements.list');
    Route::post('/settlements/{ulid}/approve', [$hc, 'settlementApprove'])
        ->where('ulid', '[A-Z0-9]{26}')->name('settlements.approve');
    Route::post('/settlements/{ulid}/reject', [$hc, 'settlementReject'])
        ->where('ulid', '[A-Z0-9]{26}')->name('settlements.reject');

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
