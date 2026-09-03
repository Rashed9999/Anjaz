<?php

use App\Http\Controllers\Admin\AccountRecoveryController;
use App\Http\Controllers\Admin\AdminAmlController;
use App\Http\Controllers\Admin\AdminCharityController;
use App\Http\Controllers\Admin\AdminSafePaymentController;
use App\Http\Controllers\Admin\AuditDecisionsController;
use App\Http\Controllers\Admin\LegalTermsController;
use App\Http\Controllers\Admin\OperatorRolesController;
use App\Http\Controllers\Admin\OperatorWorkspaceController;
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

// ══════════════════════════════════════════════════════════════════════
//  AMIAL-I18N-001 — **تبديلُ لغة اللوحة.**
//
//  اللوحةُ عربيّةٌ افتراضاً الآن، **والتبديلُ حقٌّ لا ميزة**: من يقرأ
//  الإنجليزيّة أسرعَ يبدّل، ومن يشارك شاشتَه مع مورّدٍ أجنبيٍّ يبدّل.
//
//  ولا صلاحيّةَ عليه: **تفضيلُ عرضٍ لا فعلٌ إداريّ** — لا يقرأ بياناً
//  ولا يكتبه، ولا يظهر في التدقيق. وحصرُه بصلاحيّةٍ يجعل من لا يملكها
//  حبيسَ لغةٍ لا يقرؤها.
// ══════════════════════════════════════════════════════════════════════
Route::post('/locale', function (\Illuminate\Http\Request $request) {
    $wanted = (string) $request->input('locale');

    // **ولا تُقبل لغةٌ لا قاموسَ لها** — وإلّا فُرِّغت اللوحةُ من نصوصها
    // بقيمةٍ يكتبها المتصفّح. (القاعدة الثامنة: ما يأتي من الطلب يُفحص.)
    abort_unless(in_array($wanted, ['ar', 'en'], true), 422);

    session(['local' => $wanted]);

    return back();
})->name('locale');

// ============ Zone Management ============
//
// AMIAL-ZONE-RBAC-001 — **كانت بلا صلاحيّةٍ إطلاقاً.**
//
// و`update` تُغيّر محافظاتِ التشغيل — أي **حدَّ الحركة على كلّ الحسابات
// دفعةً واحدة**، لا على حساب. فتُفرَد بصلاحيّةٍ لا تُمنح إلّا لمدير
// المنصّة، وتُفصَل القراءةُ عنها.
Route::prefix('zones')->name('zones.')->group(function () {
    Route::get('/', [ZoneManagementController::class, 'index'])
        ->middleware('platform:platform.zones.view')->name('index');
    Route::post('/update', [ZoneManagementController::class, 'update'])
        ->middleware('platform:platform.zones.policy.update')->name('update');
});

// ============ Legal Terms ============
// AMIAL-ADMIN-DOORS-001 — **نصٌّ يوافق عليه كلُّ عميلٍ ليس إعداداً عاديّاً.**
Route::prefix('legal')->name('legal.')->group(function () {
    Route::get('/', [LegalTermsController::class, 'webIndex'])
        ->middleware('platform:platform.ops.view')->name('index');
    Route::get('/create', [LegalTermsController::class, 'webCreate'])
        ->middleware('platform:platform.settings.update')->name('create');
    Route::post('/', [LegalTermsController::class, 'webStore'])
        ->middleware('platform:platform.settings.update')->name('store');
    Route::get('/{id}', [LegalTermsController::class, 'webShow'])
        ->where('id', '[0-9]+')
        ->name('show');
});

// ============ Account Recovery ============
// AMIAL-ADMIN-DOORS-001 — **استعادةُ حسابٍ تُسلّم مالَ صاحبِه لمن يدّعيه.**
// فهي من جنس اعتماد التوثيق لا من جنس قراءة تذكرة.
Route::prefix('recovery')->name('recovery.')
    ->middleware('platform:platform.approvals.decide')->group(function () {
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
// AMIAL-AUDIT-DETAIL-002 — القائمةُ والتفصيلُ والتصدير، وكلُّها قراءةٌ
// محضة تحت `platform.audit.view`: سجلُّ التدقيق لا يُعدَّل ولا يُحذف،
// وقراءتُه اطّلاعٌ على كلّ ما جرى في المنصّة فلا تُترك بلا صلاحيّة.
Route::middleware('platform:platform.audit.view')->group(function () {
    Route::get('/audit', [AuditDecisionsController::class, 'index'])->name('audit.index');
    Route::get('/audit/export.csv', [AuditDecisionsController::class, 'export'])->name('audit.export');
    Route::get('/audit/{id}.json', [AuditDecisionsController::class, 'show'])
        ->where('id', '[0-9]+')->name('audit.show');
});

// ============ Security Events ============
Route::get('/security-events', [SecurityEventsController::class, 'index'])
    ->middleware('platform:platform.audit.view')->name('security-events.index');

// ============ AMIAL-SAFE-PAYMENT-001 (v1.1) — Disputes resolution ============
// النزاع يكشف بيانات ومعاملات حساسة؛ القراءة ليست حق كل موظف، والحسم حركة
// مال فعلية. حارس admin يثبت أنه موظف، وplatform يثبت أنه يملك الفعل نفسه.
Route::prefix('safe-payments')->name('safe-payments.')->group(function () {
    Route::get('/', [AdminSafePaymentController::class, 'index'])
        ->middleware('platform:platform.transactions.view')->name('index');
    // AMIAL-SAFEPAY-EVIDENCE-001 — قبل مسار {ulid} كي لا يبتلعه
    Route::get('/evidence/{id}/file', [AdminSafePaymentController::class, 'evidenceFile'])
        ->where('id', '[0-9]+')->middleware('platform:platform.transactions.view')->name('evidence-file');
    Route::get('/{ulid}', [AdminSafePaymentController::class, 'show'])
        ->where('ulid', '[A-Z0-9]{26}')->middleware('platform:platform.transactions.view')->name('show');
    Route::post('/{ulid}/release', [AdminSafePaymentController::class, 'resolveRelease'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->middleware(['platform:platform.disputes.decide', 'amial.idempotency'])->name('release');
    Route::post('/{ulid}/refund', [AdminSafePaymentController::class, 'resolveRefund'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->middleware(['platform:platform.disputes.decide', 'amial.idempotency'])->name('refund');
    Route::post('/{ulid}/partial', [AdminSafePaymentController::class, 'resolvePartial'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->middleware(['platform:platform.disputes.decide', 'amial.idempotency'])->name('partial');
});

// ============ AMIAL-DONATIONS-001 (v1.2) ============
// AMIAL-SURFACE-002 — لوحات الأنظمة اليتيمة الأربع
Route::prefix('surface')->name('surface.')->group(function () {
    $sc = App\Http\Controllers\Admin\AdminSurfaceController::class;
    Route::get('/bill-providers', [$sc, 'billProviders'])
        ->middleware('platform:platform.settings.update')->name('bill-providers');
    Route::post('/bill-providers/{id}/toggle', [$sc, 'toggleBillProvider'])->where('id', '[0-9]+')
        ->middleware('platform:platform.settings.update')->name('bill-providers.toggle');
    Route::get('/funds', [$sc, 'funds'])
        ->middleware('platform:platform.transactions.view')->name('funds');
    // AMIAL-FUND-DETAIL-001 — «أين اختفى المال ومن سحبه؟». والصلاحيّةُ
    // `audit.view`: قراءةُ حركةِ صندوقٍ اطّلاعٌ على مالِ أُسرةٍ بعينها.
    Route::get('/funds/{id}', [$sc, 'fundDetail'])->where('id', '[0-9]+')
        ->middleware('platform:platform.audit.view')->name('funds.detail');
    Route::get('/payment-requests', [$sc, 'paymentRequests'])
        ->middleware('platform:platform.transactions.view')->name('payment-requests');
    Route::get('/rbac', [$sc, 'rbac'])
        ->middleware('platform:platform.settings.update')->name('rbac');
});

Route::prefix('charity')->name('charity.')->middleware(['platform:platform.transactions.view', 'amial.idempotency'])->group(function () {
    // AMIAL-CHARITY-ADMIN-UI-001: صفحة اللوحة (الواجهة)
    Route::view('/', 'admin-views.amial.charity.index')->name('page');
    // Organizations
    Route::get('/organizations', [AdminCharityController::class, 'indexOrgs'])->name('orgs.index');
    Route::post('/organizations', [AdminCharityController::class, 'createOrg'])
        ->middleware('platform:platform.approvals.decide')->name('orgs.create');
    Route::get('/organizations/{ulid}', [AdminCharityController::class, 'showOrg'])
        ->where('ulid', '[A-Z0-9]{26}')->middleware('platform:platform.audit.view')->name('orgs.show');
    Route::post('/organizations/{ulid}/verify', [AdminCharityController::class, 'verifyOrg'])
        ->where('ulid', '[A-Z0-9]{26}')->middleware('platform:platform.approvals.decide')->name('orgs.verify');
    Route::post('/organizations/{ulid}/reject', [AdminCharityController::class, 'rejectOrg'])
        ->where('ulid', '[A-Z0-9]{26}')->middleware('platform:platform.approvals.decide')->name('orgs.reject');
    Route::post('/organizations/{ulid}/suspend', [AdminCharityController::class, 'suspendOrg'])
        ->where('ulid', '[A-Z0-9]{26}')->middleware('platform:platform.approvals.decide')->name('orgs.suspend');

    // AMIAL-CHARITY-META-001 — التصنيفات. كانت الشاشةُ تناديه وهو معدوم.
    Route::get('/categories', [AdminCharityController::class, 'categories'])->name('categories');

    // AMIAL-CHARITY-UPLOAD-001 — رفعُ صورةٍ من الجهاز بدل لصق رابط.
    Route::post('/uploads', [AdminCharityController::class, 'uploadImage'])
        ->middleware('platform:platform.approvals.decide')->name('uploads');

    // Campaigns
    Route::get('/campaigns', [AdminCharityController::class, 'indexCampaigns'])->name('campaigns.index');
    Route::post('/organizations/{orgUlid}/campaigns', [AdminCharityController::class, 'createCampaign'])
        ->where('orgUlid', '[A-Z0-9]{26}')->middleware('platform:platform.approvals.decide')->name('campaigns.create');
    Route::post('/campaigns/{ulid}/approve', [AdminCharityController::class, 'approveCampaign'])
        ->where('ulid', '[A-Z0-9]{26}')->middleware('platform:platform.approvals.decide')->name('campaigns.approve');
    Route::post('/campaigns/{ulid}/pause', [AdminCharityController::class, 'pauseCampaign'])
        ->where('ulid', '[A-Z0-9]{26}')->middleware('platform:platform.approvals.decide')->name('campaigns.pause');
    Route::post('/campaigns/{ulid}/delete', [AdminCharityController::class, 'deleteCampaign'])
        ->where('ulid', '[A-Z0-9]{26}')->middleware('platform:platform.approvals.decide')->name('campaigns.delete');

    // AMIAL-CHARITY-DONORS-001 — «يجب إظهار المتبرّعين». والجدولُ مكتوبٌ
    // منذ بُنيت الحملات ولا نقطةَ تقرؤه للإدارة.
    // **هواتفُ المتبرّعين بياناتٌ شخصيّة.** كانت تُردّ لأيّ مديرٍ بلا
    // صلاحيّة، بينما تفصيلُ صندوق العائلة محروسٌ بـ`platform.audit.view`.
    // تفاوتٌ بلا سبب — والأشدُّ هو الصواب.
    Route::get('/campaigns/{ulid}/donors', [AdminCharityController::class, 'campaignDonors'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->middleware('platform:platform.audit.view')->name('campaigns.donors');

    // Settlements
    Route::get('/settlements', [AdminCharityController::class, 'indexSettlements'])->name('settlements.index');
    Route::post('/settlements/generate', [AdminCharityController::class, 'generateSettlement'])
        ->middleware('platform:platform.money.move')->name('settlements.generate');
    // **بابان لفعلٍ واحد، أحدُهما محروسٌ والآخرُ لا** — القاعدة الرابعة.
    // هذا يقلب التسويةَ إلى «مصروفة» **بلا قيدٍ في الدفتر**، وهو عينُ
    // الثغرة التي عولجت في `payout`. فيُحرَس بالصلاحيّة نفسِها حتّى
    // يُزال، ولا يُترك باباً خلفيّاً يلتفّ على القيد.
    Route::post('/settlements/{ulid}/transferred', [AdminCharityController::class, 'markTransferred'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->middleware('platform:platform.money.move')->name('settlements.transferred');

    // AMIAL-CHARITY-PAYOUT-001 — «طريقة سحب المال … إلى محفظة أميال باي أو
    // عبر وكيل». وهو الوحيد هنا الذي **يُحرّك مالاً** — فيُحرَس بصلاحيّة
    // تحريك المال لا بصلاحيّة تحرير المحتوى.
    Route::post('/settlements/{ulid}/payout', [AdminCharityController::class, 'payoutSettlement'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->middleware('platform:platform.money.move')->name('settlements.payout');
});

// ============ AMIAL-AML-001 (v1.4) ============
// AMIAL-ADMIN-DOORS-001 — بلاغاتُ الاشتباه وقوائمُ العقوبات ليست قراءةَ دعم.
Route::prefix('aml')->name('aml.')
    ->middleware('platform:platform.audit.view')->group(function () {
    // AMIAL-AML-PANEL-001 — الصفحة. كلّ ما تحتها كان JSON بلا مُشغِّل.
    Route::get('/', [AdminAmlController::class, 'page'])->name('page');

    // Rules
    Route::get('/rules', [AdminAmlController::class, 'indexRules'])->name('rules.index');
    Route::get('/rules/{id}', [AdminAmlController::class, 'showRule'])->name('rules.show');
    Route::post('/rules/{id}/toggle', [AdminAmlController::class, 'toggleRule'])->middleware('platform:platform.aml.decide')->name('rules.toggle');
    Route::patch('/rules/{id}', [AdminAmlController::class, 'updateRule'])->middleware('platform:platform.aml.decide')->name('rules.update');

    // Flagged transactions
    Route::get('/flagged', [AdminAmlController::class, 'indexFlagged'])->name('flagged.index');
    Route::get('/flagged/{ulid}', [AdminAmlController::class, 'showFlagged'])
        ->where('ulid', '[A-Z0-9]{26}')->name('flagged.show');
    Route::post('/flagged/{ulid}/approve', [AdminAmlController::class, 'approveFlagged'])
        ->where('ulid', '[A-Z0-9]{26}')->middleware('platform:platform.aml.decide')->name('flagged.approve');
    Route::post('/flagged/{ulid}/reject', [AdminAmlController::class, 'rejectFlagged'])
        ->where('ulid', '[A-Z0-9]{26}')->middleware('platform:platform.aml.decide')->name('flagged.reject');

    // Alerts
    Route::get('/alerts', [AdminAmlController::class, 'indexAlerts'])->name('alerts.index');
    Route::post('/alerts/{ulid}/resolve', [AdminAmlController::class, 'resolveAlert'])
        ->where('ulid', '[A-Z0-9]{26}')->middleware('platform:platform.aml.decide')->name('alerts.resolve');

    // AMIAL-AML-DASHBOARD-001 — المؤشّرات والتبويبات ٢ و٣ و٦
    Route::get('/dashboard', [AdminAmlController::class, 'dashboard'])->name('dashboard');
    Route::get('/large-transactions', [AdminAmlController::class, 'largeTransactions'])->name('large.index');
    Route::get('/structuring', [AdminAmlController::class, 'structuring'])->name('structuring.index');
    Route::get('/sanctions', [AdminAmlController::class, 'sanctions'])->name('sanctions.index');
    Route::post('/sanctions/{id}/review', [AdminAmlController::class, 'reviewSanction'])
        ->where('id', '[0-9]+')->middleware('platform:platform.aml.investigate')->name('sanctions.review');

    // AMIAL-AML-INVESTIGATION-001 — مركز التحقيقات (الفصل ١٠، التبويب ٧)
    Route::get('/investigations', [AdminAmlController::class, 'indexInvestigations'])->name('investigations.index');
    Route::post('/investigations', [AdminAmlController::class, 'openInvestigation'])->middleware('platform:platform.aml.investigate')->name('investigations.open');
    Route::get('/investigations/{id}', [AdminAmlController::class, 'showInvestigation'])
        ->where('id', '[0-9]+')->name('investigations.show');
    Route::post('/investigations/{id}/evidence', [AdminAmlController::class, 'investigationEvidence'])
        ->where('id', '[0-9]+')->middleware('platform:platform.aml.investigate')->name('investigations.evidence');
    Route::post('/investigations/{id}/action', [AdminAmlController::class, 'investigationAction'])
        ->where('id', '[0-9]+')->middleware('platform:platform.aml.investigate')->name('investigations.action');
    Route::post('/investigations/{id}/close', [AdminAmlController::class, 'closeInvestigation'])
        ->where('id', '[0-9]+')->middleware('platform:platform.aml.investigate')->name('investigations.close');
    Route::post('/investigations/{id}/reopen', [AdminAmlController::class, 'reopenInvestigation'])
        ->where('id', '[0-9]+')->middleware('platform:platform.aml.investigate')->name('investigations.reopen');
    Route::post('/investigations/{id}/str', [AdminAmlController::class, 'generateStr'])
        ->where('id', '[0-9]+')->middleware('platform:platform.aml.decide')->name('investigations.str');

    // AMIAL-AML-REGREPORT-001 — التقارير التنظيمية (الفصل ١٠، التبويب ٨)
    Route::get('/reports', [AdminAmlController::class, 'indexReports'])->name('reports.index');
    Route::post('/reports/ctr', [AdminAmlController::class, 'generateCtr'])->middleware('platform:platform.aml.decide')->name('reports.ctr');
    Route::post('/reports/{id}/submit', [AdminAmlController::class, 'submitReport'])
        ->where('id', '[0-9]+')->middleware('platform:platform.aml.decide')->name('reports.submit');

    // User risk profiles
    Route::get('/users/{userId}/profile', [AdminAmlController::class, 'showUserProfile'])
        ->where('userId', '[0-9]+')->name('users.profile');
    Route::post('/users/{userId}/override', [AdminAmlController::class, 'setUserOverride'])
        ->where('userId', '[0-9]+')->middleware('platform:platform.aml.decide')->name('users.override');
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
        // AMIAL-CUSTOMER-IDEMPOTENCY-001 (‏من دفعة Codex — أُبقيت عند
        // استعادة هذا الملفّ): فعلُ مركز العملاء يُجمّد ويُفكّ ويُعيد PIN.
        // وضغطتان متتاليتان بلا مفتاح تفرّدٍ تُنفَّذان مرّتين.
        Route::post('/{id}/action', [$cc, 'act'])->where('id', '[0-9]+')
            ->middleware('amial.idempotency')->name('action');
    });

// ملفات تسجيل الموظف والنماذج الورقية: بوابتان منفصلتان للقراءة والكتابة؛
// ولا توجد هنا صلاحية اعتماد KYC أو فتح محفظة.
Route::prefix('registration-dossiers')->name('registration-dossiers.')->group(function () {
    $rd = App\Http\Controllers\Admin\RegistrationDossierController::class;
    Route::get('/', [$rd, 'page'])->middleware('platform:platform.registrations.view')->name('page');
    Route::get('/index', [$rd, 'index'])->middleware('platform:platform.registrations.view')->name('index');
    Route::post('/', [$rd, 'store'])->middleware('platform:platform.registrations.create')->name('store');
    Route::get('/{reference}', [$rd, 'show'])->middleware('platform:platform.registrations.view')->name('show');
    Route::get('/{reference}/pdf', [$rd, 'pdf'])->middleware('platform:platform.registrations.view')->name('pdf');
    Route::get('/{reference}/paper', [$rd, 'paper'])->middleware('platform:platform.registrations.view')->name('paper');
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

// ============ AMIAL-MERCHANT-CENTER-001 — مركز التاجر (٣٦٠°) ============
//
// **أميال منصّة مالية لا ERP للتاجر.** فلا أصناف ولا مخزون ولا موردين
// هنا — إلّا بإذن اطّلاع مؤقّت بسبب مكتوب وأجل، ويُسجَّل.
//
// ══════════════════════════════════════════════════════════════════════
// **وصلاحيّاتُ فريق أميال نفسِه مقسومةٌ هنا** — AMIAL-OPERATOR-RBAC-003.
//
// «لا يجوز أن يستطيع كلّ موظّف في أميال رؤية كلّ شيء.» وكان المركزُ
// كلُّه على صلاحيّتين: `customers.view` لكلّ قراءة و`audit.view` لكلّ
// ما يمسّ المال. فصار:
//
//   دعم العملاء  → الملفّ · حالة الحساب · التذاكر · العمليّات الأساسيّة
//   المالية      → الأرصدة · كشف الحساب · التسويات · العمولات
//   المخاطر      → الإشارات · الأجهزة · التدقيق · التجميد الأمنيّ
//   الامتثال     → التوثيق · الوثائق · المراجعات · القيود
//   مدير النظام  → الكلّ — ومعه وحدَه تغييرُ الباقات
//
// **وصلاحيّةُ كلّ مسارٍ هنا هي نفسُها المكتوبة في
// `MerchantCenterService::SECTIONS`** — ولو اختلفتا لظهر تبويبٌ لا
// يُفتح، أو انفتح مسارٌ بلا تبويب. يمنعهما حارسٌ في المجموعة.
Route::prefix('merchant-center')->name('merchant-center.')->group(function () {
    $mc = App\Http\Controllers\Admin\MerchantCenterController::class;

    // القاعدة: من يرى ملفّ عميلٍ يرى ملفّ تاجر — وهي أدنى بوّابة.
    Route::middleware('platform:platform.customers.view')->group(function () use ($mc) {
        Route::get('/{id}', [$mc, 'page'])->where('id', '[0-9]+')->name('page');
        Route::get('/{id}/overview', [$mc, 'overview'])->where('id', '[0-9]+')->name('overview');
        Route::get('/{id}/operations', [$mc, 'operations'])->where('id', '[0-9]+')->name('operations');
        Route::get('/{id}/operations/{type}', [$mc, 'operationsOfType'])
            ->where(['id' => '[0-9]+', 'type' => '[a-z_]+'])->name('operations.type');
        Route::get('/{id}/staff', [$mc, 'staff'])->where('id', '[0-9]+')->name('staff');
        Route::get('/{id}/support', [$mc, 'support'])->where('id', '[0-9]+')->name('support');
        Route::get('/{id}/subscription', [$mc, 'subscription'])->where('id', '[0-9]+')->name('subscription');
        // AMIAL-ADMIN-DOORS-002 — إضافةُ ملاحظةٍ فعلٌ، ولها صلاحيّتُها
        // المبنيّةُ سلفاً (`customers.notes.create`) — وكانت خلف قراءة.
        Route::post('/{id}/note', [$mc, 'addNote'])->where('id', '[0-9]+')
            ->middleware('platform:platform.customers.notes.create')->name('note');
    });

    // ── المال: لفريق المالية ومدير النظام وحدهما ──
    // «Customer Support لا يستطيع: تعديل التسويات · تعديل الأرصدة ·
    //  تغيير العمولات» — وقراءتُها كذلك ليست من عمله.
    Route::middleware('platform:platform.merchants.money')->group(function () use ($mc) {
        Route::get('/{id}/money', [$mc, 'money'])->where('id', '[0-9]+')->name('money');
        Route::get('/{id}/statement', [$mc, 'statement'])->where('id', '[0-9]+')->name('statement');
        Route::get('/{id}/settlements', [$mc, 'settlements'])->where('id', '[0-9]+')->name('settlements');
    });

    // ── المخاطر والأجهزة: لفريق المخاطر ──
    Route::middleware('platform:platform.merchants.risk')->group(function () use ($mc) {
        Route::get('/{id}/risk', [$mc, 'risk'])->where('id', '[0-9]+')->name('risk');
        Route::get('/{id}/devices', [$mc, 'devices'])->where('id', '[0-9]+')->name('devices');
        // AMIAL-RISK-TIER-DOOR-001 — `risk.tier` كان مُعلَناً في
        // `MerchantAdminAction::ACTIONS` بلا مسارٍ يفعله.
        // ورفعُ درجة خطرِ تاجرٍ يُغيّر ما يُسمح له به — قرارُ مخاطر لا قراءةً.
        Route::post('/{id}/risk-tier', [$mc, 'setRiskTier'])
            ->where('id', '[0-9]+')
            ->middleware('platform:platform.risk.investigations.create')->name('risk.tier');
    });

    // ── الامتثال والتوثيق: لفريق الامتثال ──
    // «ولا أريد أن يكون هذا مختلطاً مع الاشتراكات.»
    Route::middleware('platform:platform.merchants.compliance')->group(function () use ($mc) {
        Route::get('/{id}/compliance', [$mc, 'compliance'])->where('id', '[0-9]+')->name('compliance');
    });

    // ── سجلّ التدقيق: للمخاطر والامتثال والإشراف ──
    Route::middleware('platform:platform.audit.view')->group(function () use ($mc) {
        Route::get('/{id}/audit', [$mc, 'auditTrail'])->where('id', '[0-9]+')->name('audit');
    });

    // ── إذن الاطّلاع التشغيليّ: البابُ الوحيد إلى تفاصيل التاجر ──
    // ولا يملكه الدعم ولا المالية: فتحُ ERP التاجر قرارُ تحقيقٍ لا خدمة.
    Route::middleware('platform:platform.merchants.investigate')->group(function () use ($mc) {
        Route::get('/{id}/operational-detail', [$mc, 'operationalDetail'])
            ->where('id', '[0-9]+')->name('operational-detail');
        Route::post('/{id}/access', [$mc, 'grantAccess'])->where('id', '[0-9]+')->name('access.grant');
        Route::delete('/{id}/access/{grantId}', [$mc, 'revokeAccess'])
            ->where(['id' => '[0-9]+', 'grantId' => '[0-9]+'])->name('access.revoke');
    });

    // ── التذاكر: عملُ الدعم ──
    Route::middleware('platform:platform.tickets.manage')->group(function () use ($mc) {
        Route::post('/{id}/ticket', [$mc, 'openTicket'])->where('id', '[0-9]+')->name('ticket');
    });

    // الأفعال الأمنيّة — «Risk يستطيع: التجميد الأمني»
    Route::middleware('platform:platform.customers.freeze')->group(function () use ($mc) {
        Route::post('/{id}/freeze', [$mc, 'toggleFreeze'])->where('id', '[0-9]+')->name('freeze');
        Route::post('/{id}/revoke-sessions', [$mc, 'revokeSessions'])
            ->where('id', '[0-9]+')->name('sessions.revoke');
        Route::post('/{id}/devices/{deviceId}/toggle', [$mc, 'toggleDevice'])
            ->where(['id' => '[0-9]+', 'deviceId' => '[0-9]+'])->name('device.toggle');
        Route::post('/{id}/staff/{posId}/disable', [$mc, 'disableStaff'])
            ->where(['id' => '[0-9]+', 'posId' => '[0-9]+'])->name('staff.disable');
    });

    Route::middleware('platform:platform.settings.manage')->group(function () use ($mc) {
        Route::post('/{id}/plan', [$mc, 'changePlan'])->where('id', '[0-9]+')->name('plan');
    });
});

// ============ AMIAL-ENTITLEMENTS-001 — مركز الباقات والقدرات ============
//
// **التسعيرُ يُجرَّب أكثر من مرّة في الشهور الأولى**، وكلُّ تجربةٍ بنشرةٍ
// تعني أنّك لن تجرّب. فالمصفوفةُ تُحرَّر من هنا وتُخزَّن فوق افتراضيّ
// الشيفرة. والصلاحيّة `platform.settings.manage` — تغييرُ ما يُباع قرارُ
// إدارةٍ لا قراءةُ تدقيق.
Route::prefix('entitlements')->name('entitlements.')
    ->middleware('platform:platform.settings.manage')
    ->group(function () {
        $ec = App\Http\Controllers\Admin\EntitlementCenterController::class;

        Route::get('/', [$ec, 'page'])->name('page');
        Route::get('/matrix', [$ec, 'matrix'])->name('matrix');
        Route::post('/matrix', [$ec, 'setCell'])->name('matrix.set');
        Route::delete('/matrix', [$ec, 'resetCell'])->name('matrix.reset');
        Route::get('/health', [$ec, 'health'])->name('health');

        Route::get('/merchants/{id}', [$ec, 'merchant'])
            ->where('id', '[0-9]+')->name('merchant');
        Route::post('/merchants/{id}/override', [$ec, 'setOverride'])
            ->where('id', '[0-9]+')->name('merchant.override');
        Route::delete('/merchants/{id}/override/{overrideId}', [$ec, 'removeOverride'])
            ->where(['id' => '[0-9]+', 'overrideId' => '[0-9]+'])->name('merchant.override.remove');
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
        // AMIAL-RECON-CASES-DOOR-001 — **قضايا المصالحة: مبنيّةٌ ولا باب.**
        //
        // `reconciliationCases()` قائمةٌ في المتحكّم، و`ReconciliationCaseService`
        // تُنشئ قضيّةً لكلّ فرقٍ يجده التشغيلُ الليليّ — **ولا مسارَ يصل إلى
        // القراءة**. فالفروقُ تُكتشَف وتُسجَّل ولا يراها أحد: مصالحةٌ تعمل
        // في الظلام. (‏القاعدة ١٢ في أخطر موضعها.)
        Route::get('/reconciliation-cases', [$lc, 'reconciliationCases'])->name('reconciliation-cases');
        // AMIAL-LEDGER-FLOW-COVERAGE-001 — «كلُّ ريال» تُقاس أو لا تُقال.
        Route::get('/flow-coverage', [$lc, 'flowCoverage'])->name('flow-coverage');
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
        // AMIAL-CATALOG-IMAGE-001 — رفعُ صورة الصنف مصغَّرةً إلى ٤٠٠ بكسل.
        // **قبل `/{id}`**: وإلّا التقطه `[0-9]+`… لا، لا يلتقطه — لكنّ
        // ترتيبَ المسارات النصّيّة قبل المتغيّرة عادةٌ تمنع مفاجأةً لاحقة.
        Route::post('/images', [$cc, 'uploadImage'])->name('images');
        Route::get('/{id}', [$cc, 'show'])->where('id', '[0-9]+')->name('show');
        Route::post('/{id}/review', [$cc, 'review'])->where('id', '[0-9]+')->name('review');
    });

// AMIAL-WA-LIMIT-001 — سقفُ المال عبر بوت واتساب.
//
// AMIAL-MONEY-KEY-SPLIT-001 — **كانت خلف `money.move` ولا تُحرّك ريالاً.**
// وحجّةُ ذلك كانت «رفعُ السقف يسمح بحركةٍ كانت ممنوعة» — وهي حجّةٌ
// تصحّ على كلّ إعدادٍ في المنصّة، فتجعل مفتاحَ تحريك المال شرطاً لضبط
// أيّ حدّ. وهذا وجهُ التركيز الأخفى: **المفتاحُ العامُّ يُجبر على
// توسيع من يحمله**. فصارت ضبطَ إعدادٍ كما هي.
Route::prefix('whatsapp')->name('whatsapp.')->middleware('platform:platform.settings.update')
    ->group(function () {
        $wl = App\Http\Controllers\Admin\WhatsappLimitController::class;
        Route::get('/limits', [$wl, 'page'])->name('limits.page');
        Route::get('/limits/show', [$wl, 'show'])->name('limits.show');
        Route::post('/limits', [$wl, 'save'])->name('limits.save');
    });

// AMIAL-MULTI-CURRENCY-002 — أسعار الصرف.
//
// **والقراءةُ تُفصَل عن الكتابة** (AMIAL-MONEY-KEY-SPLIT-001): وضعتُ
// المسارات الثلاثة أوّلاً خلف `platform.money.move` بحجّة أنّ السعرَ يمسّ
// المال — **فأمسكها `MoneyKeySplitTest`**، وهو محقّ: من أراد أن **يقرأ**
// السعرَ وجب حينها منحُه أن **يُحرّك** المال، فيتّسع حاملو المفتاح بدل
// أن يضيقوا. وهي الحجّةُ نفسُها التي تصحّ على كلّ إعدادٍ في المنصّة.
//
// فالقراءةُ `platform.money.view`، والكتابةُ وحدَها `platform.money.move`:
// رفعُ سعر الدولار عشرةَ أضعافٍ يُضاعف رصيدَ كلّ من يصرف بعده — **فيُحرّك
// مالاً في كلّ محفظةٍ أجنبيّة دفعةً واحدة**. ومن يملك تغييرَ السعر يملك
// المال.
Route::prefix('fx')->name('fx.')->group(function () {
    $fx = App\Http\Controllers\Admin\FxRateController::class;
    Route::middleware('platform:platform.money.view')->group(function () use ($fx) {
        Route::get('/rates', [$fx, 'page'])->name('rates.page');
        Route::get('/rates/show', [$fx, 'show'])->name('rates.show');
    });
    Route::middleware('platform:platform.money.move')
        ->post('/rates', [$fx, 'save'])->name('rates.save');
});

// AMIAL-MERCHANT-PAY-002 — مركز فواتير التجّار.
// يُقرأ من `payment_requests` نفسِه الذي يكتب فيه التطبيق — جذرٌ واحدٌ
// يُقرأ من زاويتين، لا حقيقتان تفترقان.
Route::prefix('invoices')->name('invoices.')->middleware('platform:platform.money.view')
    ->group(function () {
        $ic = App\Http\Controllers\Admin\MerchantInvoiceCenterController::class;
        Route::get('/', [$ic, 'page'])->name('page');
        Route::get('/stats', [$ic, 'stats'])->name('stats');
        Route::get('/rows', [$ic, 'rows'])->name('rows');
        Route::get('/export', [$ic, 'export'])->name('export');
        Route::get('/{id}', [$ic, 'show'])->where('id', '[0-9]+')->name('show');
        // إلغاءُ فاتورةٍ فعلٌ يمسّ مستحقَّ تاجر — لا يكفيه أن تُقرأ.
        Route::post('/{id}/cancel', [$ic, 'cancel'])->where('id', '[0-9]+')
            ->middleware('platform:platform.money.move')->name('cancel');
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
// AMIAL-ADMIN-DOORS-001 — تسوياتُ الشركاء مالٌ يخرج من المنصّة.
// AMIAL-MONEY-KEY-SPLIT-001 — القراءةُ بـ`money.view`، والقرارُ يزيد
// عليها `settlements.decide`. وكانت الأربعُ قراءاتٍ خلف مفتاح تحريك
// المال، فمن أراد أن يرى لوحةَ تسوياتِ الشركاء وجب منحُه إيّاه.
Route::prefix('partner-settlements')->name('partner-settlements.')
    ->middleware(['amial.idempotency', 'platform:platform.money.view'])->group(function () {
    $st = App\Http\Controllers\Api\V1\Amial\SettlementController::class;

    Route::get('/', [$st, 'page'])->name('page');
    Route::get('/list', [$st, 'index'])->name('list');
    Route::get('/dashboard', [$st, 'dashboard'])->name('dashboard');
    Route::get('/{id}', [$st, 'show'])->where('id', '[0-9]+')->name('show');

    Route::middleware('platform:platform.settlements.decide')->group(function () use ($st) {
        Route::post('/{id}/submit', [$st, 'submit'])->where('id', '[0-9]+')->name('submit');
        Route::post('/{id}/approve', [$st, 'approve'])->where('id', '[0-9]+')->name('approve');
        Route::post('/{id}/reject', [$st, 'reject'])->where('id', '[0-9]+')->name('reject');
        Route::post('/{id}/process', [$st, 'process'])->where('id', '[0-9]+')->name('process');
        Route::post('/{id}/complete', [$st, 'complete'])->where('id', '[0-9]+')->name('complete');
        Route::post('/{id}/cancel', [$st, 'cancel'])->where('id', '[0-9]+')->name('cancel');
    });
});

// ============ AMIAL-KYC-PANEL-001 — مراجعة مستندات الهوية ============
//
// كانت هذه النقاط مسجَّلة على سطح الـAPI وحده، فبقي المستند يصل بلا مراجع.
// فصل القراءة عن القرار: المراجع يرى المستندات بـ`customers.kyc.view`،
// أمّا اعتمادها أو رفضها فيبقى قراراً حساساً خلف `customers.freeze`.
Route::prefix('kyc')->name('kyc.')->group(function () {
    $kyc = App\Http\Controllers\Api\V1\Amial\KycDocumentController::class;

    Route::get('/', [$kyc, 'page'])->middleware('platform:platform.customers.kyc.view')->name('page');
    Route::get('/queue', [$kyc, 'queue'])->middleware('platform:platform.customers.kyc.view')->name('queue');
    Route::get('/activation-queue', [$kyc, 'activationQueue'])
        ->middleware('platform:platform.customers.kyc.view')->name('activation-queue');
    Route::get('/documents/{id}/file', [$kyc, 'file'])
        ->where('id', '[0-9]+')->middleware('platform:platform.customers.kyc.view')->name('file');
    // AMIAL-KYC-OCR-001 — الحقول المستخرَجة وإقرارها
    Route::get('/documents/{id}/ocr', [$kyc, 'ocr'])
        ->where('id', '[0-9]+')->middleware('platform:platform.customers.kyc.view')->name('ocr');
    Route::post('/documents/{id}/fields', [$kyc, 'confirmFields'])
        ->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('fields');
    Route::post('/documents/{id}/reread', [$kyc, 'reread'])
        ->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('reread');

    Route::post('/documents/{id}/approve', [$kyc, 'approve'])
        ->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('approve');
    Route::post('/documents/{id}/reject', [$kyc, 'reject'])
        ->where('id', '[0-9]+')->middleware('platform:platform.customers.freeze')->name('reject');
    Route::post('/users/{id}/activate', [$kyc, 'activateAccount'])
        ->where('id', '[0-9]+')->middleware('platform:platform.approvals.decide')->name('activate');

    // ══════════════════════════════════════════════════════════════════
    // AMIAL-PROFILE-CHANGE-003 — طلباتُ تحديث البيانات.
    //
    // **ولا مسارَ `update` ولا `edit` ها هنا عمداً.** من يستطيع كتابةَ
    // `identification_number` مباشرةً يستطيع تحويلَ حسابٍ موثَّقٍ إلى
    // شخصٍ آخرَ ثمّ سحبَ رصيده. فالأفعالُ: يُفتح الطلبُ، ويحسمه مراجع —
    // **والقيمةُ يملؤها صاحبُ الحساب من التطبيق**.
    //
    // والصلاحيّةُ هي `platform.customers.freeze` نفسُها: تغييرُ بيانٍ في
    // ملفٍّ موثَّقٍ من جنس القرارات التي تمسّ حسابَ العميل.
    // ══════════════════════════════════════════════════════════════════
    $pcr = App\Http\Controllers\Admin\ProfileChangeRequestController::class;

    Route::get('/change-requests', [$pcr, 'page'])
        ->middleware('platform:platform.customers.freeze')->name('changes.page');
    Route::post('/change-requests', [$pcr, 'open'])
        ->middleware('platform:platform.customers.freeze')->name('changes.open');
    Route::post('/change-requests/{id}/decide', [$pcr, 'decide'])
        ->where('id', '[0-9]+')
        ->middleware('platform:platform.customers.freeze')->name('changes.decide');
    Route::get('/identity-state/{userId}', [$pcr, 'identityState'])
        ->where('userId', '[0-9]+')
        ->middleware('platform:platform.customers.freeze')->name('identity.state');
});

// ============ AMIAL-2FA-001 (v1.8) ============
Route::prefix('2fa')->name('2fa.')->group(function () {
    // AMIAL-2FA-DOOR-001 — الشاشةُ التي لم تكن: خمسُ نقاطٍ تعمل بلا مدخل.
    Route::get('/', [App\Http\Controllers\Admin\Admin2FAController::class, 'page'])->name('page');
    Route::get('/status', [App\Http\Controllers\Admin\Admin2FAController::class, 'status'])->name('status');
    Route::post('/setup', [App\Http\Controllers\Admin\Admin2FAController::class, 'setup'])->name('setup');
    Route::post('/confirm', [App\Http\Controllers\Admin\Admin2FAController::class, 'confirm'])->name('confirm');
    Route::post('/disable', [App\Http\Controllers\Admin\Admin2FAController::class, 'disable'])->name('disable');
    Route::post('/regenerate-recovery-codes', [App\Http\Controllers\Admin\Admin2FAController::class, 'regenerateRecoveryCodes'])->name('regenerate');
});

// ============ AMIAL-ZONE-ASSIGN-001 (v2.0) ============
//
// AMIAL-ZONE-RBAC-001 — **أربعةُ مساراتٍ بلا صلاحيّةٍ واحدة.**
//
// ونقلُ حسابٍ بين نطاقين ليس تصنيفاً إداريّاً: `EnforceZonePolicy` تقرأ
// النطاقَ فتسمح بالحركة أو تمنعها. فالنقلُ **يفتح أو يُغلق حركةَ مالِ
// صاحبِه** — وكان يفعله كلُّ من يدخل اللوحة.
//
// و`assign` تُفرَد عن `assign-from-kyc`: الأولى إسنادٌ يدويٌّ **يخالف
// الوثيقة** (وهي التجاوز)، والثانية إسنادٌ منها. وجمعُهما في صلاحيّةٍ
// واحدةٍ يجعل منحَ المقيَّدة منحاً للمطلقة.
Route::prefix('zone')->name('zone.')->group(function () {
    Route::post('/assign', [App\Http\Controllers\Admin\AdminZoneController::class, 'assign'])
        ->middleware('platform:platform.zones.override')->name('assign');
    Route::post('/assign-from-kyc', [App\Http\Controllers\Admin\AdminZoneController::class, 'assignFromKyc'])
        ->middleware('platform:platform.zones.assign')->name('assign-kyc');
    // سجلُّ نطاقِ **شخصٍ بعينه** — اطّلاعٌ على تاريخِ فرد، لا على لوحة.
    Route::get('/logs/{userId}', [App\Http\Controllers\Admin\AdminZoneController::class, 'logs'])
        ->middleware('platform:platform.zones.audit.view')->name('logs');
    Route::get('/stats', [App\Http\Controllers\Admin\AdminZoneController::class, 'stats'])
        ->middleware('platform:platform.zones.view')->name('stats');
});

// ============ AMIAL-AGENT-NETWORK-001 (v2.4) ============
//
// AMIAL-AGENT-NETWORK-DOOR-001 — **كانت بلا صلاحيّةٍ إطلاقاً.**
//
// خمسةُ مساراتٍ مسجَّلةٌ منذ v2.4 بلا `platform:` واحد: فأيُّ حسابِ
// إدارة — موظّفُ دعمٍ أو صيانة — يعتمد وكيلاً فيفتح له الشبّاك، أو
// يُوقفه فيُغلق شبّاكه، أو يرفع حدَّه اليوميّ. وكلُّها قراراتُ مالٍ
// ومخاطر.
//
// ولم يظهر ذلك لأنّ **لا شاشةَ تناديها**: مبنيٌّ ولا يُوصل إليه —
// فالثغرةُ نامت مع الميزة. والآن وقد صار لها زرٌّ، صار لها حارس.
//
// والقراءةُ بـ`customers.view`، والقرارُ بـ`customers.freeze`: اعتمادُ
// وكيلٍ وإيقافُه من جنس فتح الحساب وإغلاقه. والحدودُ بـ`money.move`:
// رفعُ حدّ الإيداع اليوميّ يزيد ما يمرّ من مالٍ في اليوم.
Route::prefix('agents')->name('agents.')->group(function () {
    $anc = App\Http\Controllers\Admin\AdminAgentNetworkController::class;

    Route::middleware('platform:platform.customers.view')->group(function () use ($anc) {
        Route::get('/', [$anc, 'index'])->name('index');
        Route::get('/network-stats', [$anc, 'networkStats'])->name('network-stats');
    });

    Route::middleware('platform:platform.customers.freeze')->group(function () use ($anc) {
        Route::post('/{userId}/approve', [$anc, 'approve'])->name('approve');
        Route::post('/{userId}/suspend', [$anc, 'suspend'])->name('suspend');
    });

    Route::middleware('platform:platform.money.move')
        ->put('/{userId}/limits', [$anc, 'updateLimits'])->name('limits');
});
// AMIAL-OPERATOR-RBAC-003: اعتمادُ تسويةٍ تحريكُ مالٍ حقيقيّ.
Route::prefix('settlements')->name('settlements.')->middleware(['platform:platform.money.view', 'amial.idempotency'])
    ->group(function () {
    Route::get('/pending', [App\Http\Controllers\Admin\AdminAgentNetworkController::class, 'pendingSettlements'])->name('pending');
    // **والقرارُ يزيد على القراءة ولا يكتفي بها.** أوّلُ تعديلٍ في
    // AMIAL-MONEY-KEY-SPLIT-001 نقل صلاحيّةَ المجموعة إلى `money.view`،
    // فصار اعتمادُ تسويةٍ خلف **قراءةٍ فقط** — وهو ما تمنعه القسمةُ
    // نفسُها. فيُضاف القرارُ صراحةً على كلّ مسارِ كتابةٍ فيها.
    Route::post('/{ulid}/approve', [App\Http\Controllers\Admin\AdminAgentNetworkController::class, 'approveSettlement'])
        ->middleware('platform:platform.settlements.decide')->name('approve');

    // AMIAL-CASH-HANDOVER-001 — الساقُ الورقيّة. والتأكيدُ تحريكُ عهدةٍ
    // فعليّة، فهو تحت الصلاحيّة نفسِها التي يُعتمَد بها المال.
    Route::get('/handovers/pending', [App\Http\Controllers\Admin\AdminAgentNetworkController::class, 'pendingHandovers'])->name('handovers.pending');
    Route::post('/handovers/{ulid}/confirm', [App\Http\Controllers\Admin\AdminAgentNetworkController::class, 'confirmHandover'])
        ->middleware('platform:platform.settlements.decide')->name('handovers.confirm');
    Route::post('/handovers/{ulid}/dispute', [App\Http\Controllers\Admin\AdminAgentNetworkController::class, 'disputeHandover'])
        ->middleware('platform:platform.settlements.decide')->name('handovers.dispute');
});

// ============ AMIAL-MERCHANT-RISK-001 (v2.10) ============
// AMIAL-ADMIN-DOORS-002 — القراءةُ مخاطرُ تاجر، والشريحةُ تُغيّر حدودَه
// ورسومَه، والتوثيقُ قرارُ اعتماد. وكانت الخمسةُ بلا صلاحيّة.
Route::prefix('merchants')->name('merchants.')->group(function () {
    Route::get('/high-risk', [App\Http\Controllers\Admin\AdminMerchantRiskController::class, 'highRisk'])
        ->middleware('platform:platform.merchants.risk')->name('high-risk');
    Route::get('/risk-stats', [App\Http\Controllers\Admin\AdminMerchantRiskController::class, 'riskStats'])
        ->middleware('platform:platform.merchants.risk')->name('risk-stats');
    Route::get('/{userId}/risk', [App\Http\Controllers\Admin\AdminMerchantRiskController::class, 'riskDashboard'])
        ->middleware('platform:platform.merchants.risk')->name('risk');
    Route::put('/{userId}/tier', [App\Http\Controllers\Admin\AdminMerchantRiskController::class, 'setTier'])
        ->middleware('platform:platform.merchants.compliance')->name('tier');
    Route::post('/{userId}/verify', [App\Http\Controllers\Admin\AdminMerchantRiskController::class, 'verify'])
        ->middleware('platform:platform.approvals.decide')->name('verify');
});

// ============ AMIAL-FEE-ENGINE-001 (v2.12) ============
// لوحة تحكم نسب الأرباح/الرسوم
//
// AMIAL-OPERATOR-RBAC-003: نسبةُ ربحٍ تُغيَّر مرّةً يبقى أثرُها على كلّ
// عمليّةٍ بعدها. فلا تُترك لكلّ من دخل اللوحة — لمدير المنصّة وحده.
//
// ══════════════════════════════════════════════════════════════════════
// **AMIAL-FEE-TRUTH-010 — والقراءةُ فُصلت عن الكتابة.**
//
// كانت المجموعةُ كلُّها خلف `platform.fees.update`. فمن أراد أن **يقرأ**
// تسعيرةً أو يفتح تقريرَ الأرباح — محاسبٌ يراجع، أو مشرفٌ يتحقّق من
// شكوى — لزمه إذنُ **تغيير** الرسوم. فإمّا يُمنع من الاطّلاع، وإمّا
// يُعطى مفتاحَ تغيير المال كلِّه. **وأقلُّ صلاحيّةٍ تكفي** (`amial-rbac`).
//
// وتقريرُ الأرباح خاصّةً **لا يغيّر شيئاً**: فوضعُه خلف إذن التعديل
// يُغري بمنح إذن التعديل لمن يحتاج تقريراً.
Route::prefix('fees')->name('fees.')->group(function () {

    // ── القراءة ────────────────────────────────────────────────────
    Route::middleware('platform:platform.fees.view')->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webIndex'])->name('index');
        Route::get('/profit', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webProfit'])->name('profit');
        Route::get('/history/{code?}', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webHistory'])->name('history');
        Route::get('/operations', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webOperations'])->name('operations');
        Route::get('/policies', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webPolicies'])->name('policies');
        Route::get('/drill', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webDrill'])->name('drill');
    });

    // ── الكتابة ────────────────────────────────────────────────────
    //
    // **والمحاكي كتابةٌ لا قراءة**: هو الشاشةُ التي تُجرَّب فيها نسخةٌ
    // قبل حفظها، ومن لا يملك حقَّ الحفظ لا حاجةَ له بها.
    Route::middleware('platform:platform.fees.update')->group(function () {
        Route::get('/create', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webCreate'])->name('create');
        Route::post('/', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webStore'])->name('store');
        Route::post('/simulate', [App\Http\Controllers\Admin\FeeSchemeController::class, 'simulate'])->name('simulate');
        Route::post('/{id}/deactivate', [App\Http\Controllers\Admin\FeeSchemeController::class, 'webDeactivate'])->name('deactivate');
    });
});

// ============ AMIAL-SENTINEL-001 — Security Sentinel Dashboard ============
// AMIAL-ADMIN-DOORS-001 — الحجبُ وفكُّه قرارُ مخاطر، والقراءةُ اطّلاعٌ عليها.
Route::prefix('sentinel')->name('sentinel.')
    ->middleware('platform:platform.audit.view')->group(function () {
    Route::get('/', [App\Http\Controllers\Admin\SentinelDashboardController::class, 'index'])->name('index');
    Route::post('/block', [App\Http\Controllers\Admin\SentinelDashboardController::class, 'block'])
        ->middleware('platform:platform.security.act')->name('block');
    Route::post('/unblock', [App\Http\Controllers\Admin\SentinelDashboardController::class, 'unblock'])->middleware('platform:platform.security.act')->name('unblock');
});

// ============ AMIAL-EXEC-DASHBOARD-001 — Executive Dashboard ============
Route::prefix('executive')->name('executive.')->group(function () {
    Route::get('/', [App\Http\Controllers\Admin\ExecutiveDashboardController::class, 'index'])
        ->middleware('platform:platform.audit.view')->name('index');
    Route::get('/summary', [App\Http\Controllers\Admin\ExecutiveDashboardController::class, 'summary'])
        ->middleware('platform:platform.audit.view')->name('summary');
});

// ============ AMIAL-ADMIN-HUB-001 — اللوحات المركزية الأربع ============
Route::prefix('hub')->name('hub.')->middleware('amial.idempotency')->group(function () {
    $hc = App\Http\Controllers\Admin\AdminHubController::class;

    // الصفحات
    Route::get('/customers', [$hc, 'customers'])
        ->middleware('platform:platform.customers.view')->name('customers');
    Route::get('/agents', [$hc, 'agents'])
        ->middleware('platform:platform.customers.view')->name('agents');
    Route::get('/merchants', [$hc, 'merchants'])
        ->middleware('platform:platform.merchants.compliance')->name('merchants');
    // AMIAL-OPERATOR-RBAC-003: الصفحة لا الفعلَ وحده — صفحةٌ تعرض أرصدة
    // المنصّة وحركتها تُسرّب ما لا يجوز، وإن كان زرُّها محروساً.
    Route::get('/finance', [$hc, 'finance'])
        ->middleware('platform:platform.money.view')->name('finance');

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
        ->where('slug', 'customers|agents|merchants')
        ->middleware('platform:platform.customers.lifecycle.manage')->name('users.store');
    Route::post('/users/{id}/toggle-active', [$hc, 'toggleActive'])
        ->where('id', '[0-9]+')
        ->middleware('platform:platform.customers.lifecycle.manage')->name('users.toggle-active');
    // تغييرُ حالة التوثيق قرارُ اعتمادٍ لا تعديلُ حقل.
    Route::post('/users/{id}/kyc', [$hc, 'kycStatus'])
        ->where('id', '[0-9]+')
        ->middleware('platform:platform.approvals.decide')->name('users.kyc');
    // ══════════════════════════════════════════════════════════════
    // **استُعيدت الثلاثةُ بعد أن حُذفت في دفعةٍ لاحقة.**
    //
    // وهي مساراتُ نافذة «✏️ تعديل» في مركز التجّار كلُّها: الشاشةُ
    // تنادي `readiness.json` لتملأ النافذة، و`profile` لتحفظ،
    // و`documents` لترفع. فبحذفها تُفتَح النافذةُ ولا تمتلئ أبداً
    // (‏٤٠٤ صامتة) — وهو ما وصل بنصّه: «تعديل لم يفتح».
    // ══════════════════════════════════════════════════════════════
    // ══════════════════════════════════════════════════════════════
    // AMIAL-ADMIN-EDIT-001 — **بابا الرفع والتعديل، وكانا مفقودين.**
    //
    // الرفعُ كان للعميل وحدَه من تطبيقه، فحسابٌ سُجّل بلا وثائق يبقى
    // مقفلاً أبداً: لا وثائق ⇒ لا اعتماد ⇒ `zone_code = UNKNOWN` ⇒
    // «حسابُ المستلم لم يُوثَّق بعد».
    //
    // **والصلاحيّتان مختلفتان عمداً:**
    //   · الرفعُ يُجهّز الملفّ — `customers.kyc.request`
    //   · والتعديلُ يمسّ بياناتِ حسابٍ قائم — `customers.lifecycle.manage`
    //
    // فمن يُجهّز ليس بالضرورة من يُعدّل، ومن يُعدّل ليس من يعتمد
    // (`approvals.decide` أعلاه). ثلاثُ صلاحيّاتٍ لثلاثة أفعال.
    // ══════════════════════════════════════════════════════════════
    // **وقبل الفعلِ تُقال الحالة.** «هل إذا رفعتُ سوف يستقبل؟» سؤالٌ
    // يُجاب عليه بالقياس لا بالتجربة: هذه تقرأ الاكتمالَ من المصدر
    // نفسِه الذي يسأله قرارُ الاعتماد، وتقول ما ينقص بالاسم.
    Route::get('/users/{id}/readiness.json', [$hc, 'accountReadinessJson'])
        ->where('id', '[0-9]+')
        ->middleware('platform:platform.customers.view')->name('users.readiness');

    Route::post('/users/{id}/documents', [$hc, 'uploadDocument'])
        ->where('id', '[0-9]+')
        ->middleware('platform:platform.customers.kyc.request')->name('users.documents.upload');

    Route::post('/users/{id}/profile', [$hc, 'updateProfile'])
        ->where('id', '[0-9]+')
        ->middleware('platform:platform.customers.lifecycle.manage')->name('users.profile.update');

    // ══════════════════════════════════════════════════════════════
    // AMIAL-ADMIN-DOORS-002 — **مساراتُ مالٍ كانت بلا صلاحيّةٍ إطلاقاً.**
    //
    // و`finance/topup` أسفلَ هذه الكتلة بأسطر **محروسةٌ بـ`money.move`
    // منذ AMIAL-OPERATOR-RBAC-003** — فحُرس جارٌ ونُسي جاران يفعلان
    // الشيءَ نفسَه: تحويلُ محفظةٍ من الإدارة، وشحنُ محفظة وكيل.
    //
    // **وهذا نمطُ الثغرة الأخطر:** لا تظهر في مسحٍ يسأل «أفي القطاع
    // حراسة؟» — فالجوابُ نعم. تظهر في مسحٍ يسأل **«أكلُّ بابٍ فيه
    // محروس؟»**.
    // ══════════════════════════════════════════════════════════════
    Route::post('/transfer', [$hc, 'transfer'])
        ->middleware('platform:platform.treasury.issue')->name('transfer');
    Route::post('/agents/{id}/credit', [$hc, 'agentCredit'])
        ->where('id', '[0-9]+')
        ->middleware('platform:platform.treasury.issue')->name('agents.credit');

    // AMIAL-AGENT-SUPERVISION-001 — إشراف الإدارة على شبكة شركات الصرافة
    $asc = App\Http\Controllers\Admin\AgentSupervisionController::class;
    // قراءاتُ الإشراف تُظهر مواقعَ النقد والرصيد في الشبكة كلِّها —
    // اطّلاعُ رقابةٍ لا اطّلاعُ دعم.
    Route::get('/agents/network.json', [$asc, 'network'])
        ->middleware('platform:platform.audit.view')->name('agents.network');
    Route::get('/agents/branches.json', [$asc, 'branches'])
        ->middleware('platform:platform.audit.view')->name('agents.branches');
    Route::get('/agents/movements.json', [$asc, 'movements'])
        ->middleware('platform:platform.audit.view')->name('agents.movements');

    // AMIAL-SETTLEMENT-ENGINE-001 — التوازن بين الرصيد والنقد
    Route::get('/agents/settlement.json', [$asc, 'settlementScan'])
        ->middleware('platform:platform.audit.view')->name('agents.settlement');
    Route::get('/agents/{id}/settlement.json', [$asc, 'agentSettlement'])
        ->where('id', '[0-9]+')
        ->middleware('platform:platform.audit.view')->name('agents.settlement.one');

    // AMIAL-DAILY-SETTLEMENT-001 — إقفال يوم الشبكة والتحوّل ورقاً/رصيداً
    Route::get('/agents/daily.json', [$asc, 'dailyBoard'])
        ->middleware('platform:platform.audit.view')->name('agents.daily');
    Route::get('/agents/daily/{ulid}.json', [$asc, 'dailyOne'])
        ->middleware('platform:platform.audit.view')->name('agents.daily.one');
    // **والقبولُ والرفضُ وفكُّ القفل يُقفلون يومَ شبكةٍ بمالِه** — قرارُ
    // مالٍ لا اطّلاع، فيلحقان بـ`settlements.approve` في صلاحيّتهما.
    Route::post('/agents/daily/{ulid}/accept', [$asc, 'dailyAccept'])
        ->middleware('platform:platform.settlements.decide')->name('agents.daily.accept');
    Route::post('/agents/daily/{ulid}/reject', [$asc, 'dailyReject'])
        ->middleware('platform:platform.settlements.decide')->name('agents.daily.reject');
    Route::post('/agents/daily/unlock', [$asc, 'dailyUnlock'])
        ->middleware('platform:platform.settlements.decide')->name('agents.daily.unlock');

    // المالية
    // AMIAL-OPERATOR-RBAC-003: شحنُ محفظة وكيلٍ من المنصّة.
    Route::post('/finance/topup', [$hc, 'adminTopup'])
        ->middleware('platform:platform.treasury.issue')->name('finance.topup');
    Route::get('/finance/stats.json', [$hc, 'financeStats'])
        ->middleware('platform:platform.money.view')->name('finance.stats');
    Route::get('/finance/feed.json', [$hc, 'financeFeed'])
        ->middleware('platform:platform.money.view')->name('finance.feed');

    // لوحة الاشتراكات (الباقات) — حقيقية عبر SubscriptionService
    Route::get('/subscriptions', [$hc, 'subscriptions'])
        ->middleware('platform:platform.settings.manage')->name('subscriptions');
    Route::get('/subscriptions/list.json', [$hc, 'subsList'])->name('subscriptions.list');
    Route::post('/subscriptions/{merchantId}/plan', [$hc, 'subsChangePlan'])
        ->where('merchantId', '[0-9]+')
        ->middleware('platform:platform.settings.manage')->name('subscriptions.plan');
    Route::post('/subscriptions/{merchantId}/extend', [$hc, 'subsExtend'])
        ->where('merchantId', '[0-9]+')
        ->middleware('platform:platform.settings.manage')->name('subscriptions.extend');

    // لوحة النزاعات — واجهة فوق مسارات safe-payments الموجودة (JSON)
    Route::get('/disputes', [$hc, 'disputes'])
        ->middleware('platform:platform.transactions.view')->name('disputes');

    // لوحة التحقق — اعتماد/رفض/حظر الحسابات المسجَّلة ذاتياً (كل الأدوار)
    // AMIAL-ZONE-PANEL-001 — لوحة المناطق (نطاق التشغيل، العالقون، المخالفات)
    //
    // AMIAL-ZONE-RBAC-001 — **وحراسةُ الصفحة وحدَها لا تكفي.**
    //
    // مسارا `summary.json` و`events.json` يُخرجان البياناتِ نفسَها لمن
    // يعرف عنوانَهما — وهو أوّلُ ما يُجرَّب. فيُحرَس كلٌّ منها بنفسه.
    Route::prefix('zones')->name('zones.')->group(function () {
        $zc = App\Http\Controllers\Admin\ZoneControlController::class;
        Route::get('/', [$zc, 'index'])
            ->middleware('platform:platform.zones.view')->name('index');
        Route::get('/summary.json', [$zc, 'summary'])
            ->middleware('platform:platform.zones.view')->name('summary');
        Route::get('/events.json', [$zc, 'events'])
            ->middleware('platform:platform.zones.view')->name('events');
        // فحصُ **شخصٍ بعينه** جغرافيّاً — اطّلاعٌ على فرد، لا تشغيلُ لوحة.
        Route::get('/users/{id}/geo-check.json', [$zc, 'geoCheck'])
            ->where('id', '[0-9]+')
            ->middleware('platform:platform.zones.audit.view')->name('geo-check');
        // إعادةُ الإسناد تمرّ من محافظة السكن المسجَّلة — فهي `assign`
        // لا `override`.
        Route::post('/users/{id}/reassign', [$zc, 'reassign'])
            ->where('id', '[0-9]+')
            ->middleware('platform:platform.zones.assign')->name('reassign');
    });

    Route::get('/verification', [$hc, 'verification'])
        ->middleware('platform:platform.approvals.decide')->name('verification');
    Route::get('/verification/list.json', [$hc, 'verificationJson'])->name('verification.list');

    // لوحة التسويات — تسويات الوكلاء (اعتماد/رفض مع دفتر القيود)
    Route::get('/settlements', [$hc, 'settlements'])
        ->middleware('platform:platform.money.view')->name('settlements');
    // والـJSON كذلك: حراسةُ الصفحة وحدها تترك البيانات مفتوحةً لمن يعرف
    // عنوانها — وهو أوّل ما يُجرَّب.
    Route::get('/settlements/list.json', [$hc, 'settlementsJson'])
        ->middleware('platform:platform.money.view')->name('settlements.list');
    Route::post('/settlements/{ulid}/approve', [$hc, 'settlementApprove'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->middleware('platform:platform.settlements.decide')->name('settlements.approve');
    Route::post('/settlements/{ulid}/reject', [$hc, 'settlementReject'])
        ->where('ulid', '[A-Z0-9]{26}')
        ->middleware('platform:platform.settlements.decide')->name('settlements.reject');

    // لوحة الموظفين — طاقم نقاط بيع التجّار (تفعيل/تعطيل)
    Route::get('/staff', [$hc, 'staff'])
        ->middleware('platform:platform.merchants.compliance')->name('staff');
    Route::get('/staff/list.json', [$hc, 'staffJson'])->name('staff.list');
    Route::post('/staff/{id}/toggle-active', [$hc, 'staffToggle'])
        ->where('id', '[0-9]+')
        ->middleware('platform:platform.merchants.compliance')->name('staff.toggle');

    // لوحة الإعدادات — تحكّم بضغطة زر (بلا كود)
    Route::get('/settings', [$hc, 'settings'])
        ->middleware('platform:platform.settings.update')->name('settings');
    // مفتاحُ ميزةٍ يُطفئ خدمةً على المنصّة كلِّها.
    Route::post('/settings/flag', [$hc, 'settingsToggle'])
        ->middleware('platform:platform.settings.update')->name('settings.flag');
});

// ============ AMIAL-OPS-CONSOLE-001 — حالة التشغيل (فريق الصيانة) ============
//
// العرض يحتاج ops.view، وإعادة التشغيل تحتاج ops.retry — فمن يراقب ليس
// بالضرورة من يتدخّل، وفصلُهما يسمح بمنح المراقبة لمن لا يُؤذن له بالتغيير.
Route::get('/workspace', [OperatorWorkspaceController::class, 'index'])->name('workspace.index');

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
        ->middleware('platform:platform.staff.view')->name('roles.index');

    Route::post('/roles/{userId}', [OperatorRolesController::class, 'update'])
        ->where('userId', '[0-9]+')
        ->middleware('platform:platform.staff.manage')->name('roles.update');

    // AMIAL-OPERATOR-CREATE-001 — إنشاءُ موظّفِ منصّةٍ بأدواره.
    // وبالصلاحيّة نفسِها: من يُسند الأدوار هو من يُنشئ من يحملها.
    Route::post('/operators', [OperatorRolesController::class, 'store'])
        ->middleware('platform:platform.staff.manage')->name('operators.store');
});

// ============ AMIAL-SUPERVISION-001 — لوحة الإشراف ============
//
// قراءةٌ محضة: الإشراف رقابةٌ على التنفيذ لا تنفيذ، فلا فعل هنا يُغيّر شيئاً.
Route::get('/supervision', [SupervisionController::class, 'index'])
    ->middleware('platform:platform.audit.view')->name('supervision.index');

// ============ AMIAL-OBSERVABILITY-001 — مركز صحّة النظام ============
//
// **الثمن:** ثلاثةُ أعطالٍ في يومٍ واحدٍ وصلت عبر صاحب المشروع لا عبر
// جهاز. وقِيس أنّ المنصّةَ بلا نقطةِ صحّةٍ ولا تتبّعِ أخطاءٍ ولا سجلِّ
// توفّر — **فصاحبُ المشروع هو جهازُ الرصد.**
//
// وتحت `platform.audit.view`: الصحّةُ رقابةٌ لا تنفيذ. وتغييرُ حالة عطلٍ
// فعلٌ إداريٌّ يُدقَّق، فله صلاحيّتُه.
Route::get('/system/health', [\App\Http\Controllers\Admin\SystemHealthController::class, 'index'])
    ->middleware('platform:platform.audit.view')->name('system.health');

Route::post('/system/errors/{id}', [\App\Http\Controllers\Admin\SystemHealthController::class, 'updateError'])
    ->where('id', '[0-9]+')
    ->middleware('platform:platform.security.act')->name('system.errors.update');

// ══════════════════════════════════════════════════════════════════════
// SAHER-FOUNDATION-008 — ساهر
// ══════════════════════════════════════════════════════════════════════
//
// **والصلاحيّاتُ ثلاثٌ لا واحدة، والفرقُ مقصود:**
//
//   · `saher.view`          — يفتح الرادار ويرى الأعداد والعناوين
//   · `saher.findings.view` — يفتح تفصيلَ اكتشاف
//   · `saher.scan.run`      — يشغّل جولةً بيده
//
// والدليلُ نفسُه خلف `saher.evidence.view` **ويُفحص في المتحكّم لا على
// المسار**: الصفحةُ تُفتح ويُحجب الدليلُ وحدَه، فمن يراقب يرى العدد ومن
// يُصلح يرى الموضع. وحاجزٌ على المسار كلِّه يشلّ الأوّل ليحمي الثاني.
Route::get('/saher', [\App\Http\Controllers\Admin\SaherController::class, 'index'])
    ->middleware('platform:saher.view')->name('saher.index');

Route::get('/saher/findings/{id}', [\App\Http\Controllers\Admin\SaherController::class, 'show'])
    ->where('id', '[0-9]+')
    ->middleware('platform:saher.findings.view')->name('saher.show');

// **والفرزُ فعلٌ لا قراءة.** حالاتُ `HUMAN_HELD` كانت مبنيّةً وتنجو من
// كلّ مسح، والصلاحيّةُ معرَّفةً — **ولا مسارَ يضعها**. فبقيت ٩٦ نتيجةً
// تُقرأ من الصفر في كلّ تدقيق. (النمطُ نفسُه داخل الأداة التي تكشفه.)
Route::post('/saher/findings/{id}/rule', [\App\Http\Controllers\Admin\SaherController::class, 'rule'])
    ->where('id', '[0-9]+')
    ->middleware('platform:saher.findings.suppress')->name('saher.rule');

// **وتشغيلُ فحصٍ فعلٌ لا قراءة** — يُسجَّل على فاعله ويحتاج صلاحيّتَه.
Route::post('/saher/scan', [\App\Http\Controllers\Admin\SaherController::class, 'scan'])
    ->middleware('platform:saher.scan.run')->name('saher.scan');
