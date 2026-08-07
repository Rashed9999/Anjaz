<?php

use App\Http\Controllers\Agent\AgentCounterController;
use App\Http\Controllers\Agent\AgentPortalController;
use App\Http\Controllers\Agent\AgentStaffController;
use App\Http\Controllers\Agent\AgentTellerController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-AGENT-PORTAL-001 / AMIAL-AGENT-STAFF-001 — بوّابة شركة الصرافة.
 *
 * **لاحظ ما ليس في مسارات الشبّاك: معرّف الفرع.**
 *
 * كانت `POST /branches/{id}/deposit`، فكان لا بدّ للواجهة أن تسأل «اختر
 * الفرع» قبل كلّ عملية. وذلك لم يكن عيباً في الواجهة بل في الصلاحيات:
 * معرّفٌ يأتي من المتصفّح يُغيَّر، فيعمل صرّافُ المكلا على درج سيئون.
 *
 * فصارت `POST /counter/deposit` بلا معرّف: الفرع من الورديّة، والورديّة من
 * حساب الداخل. وما لا يُقبل من الطلب لا يحتاج فحصاً.
 */

$c = AgentPortalController::class;
$counter = AgentCounterController::class;
$staff = AgentStaffController::class;
$teller = AgentTellerController::class;

// عامّ: الدخول وحده.
Route::get('/login', [$c, 'loginPage'])->name('login');
Route::post('/login', [$c, 'login'])->name('login.submit');

Route::middleware('agent.portal')->group(function () use ($c, $counter, $staff, $teller) {
    Route::get('/logout', [$c, 'logout'])->name('logout');
    Route::post('/logout', [$c, 'logout'])->name('logout.post');

    Route::get('/', [$c, 'dashboard'])->name('dashboard');

    // ── الشبّاك: لا معرّف فرعٍ في أيّ مسار ────────────────────────────
    Route::prefix('counter')->name('counter.')->middleware('amial.idempotency')->group(function () use ($counter, $c) {
        Route::get('/state', [$counter, 'state'])->name('state');
        Route::post('/shift/open', [$counter, 'openShift'])->name('shift.open');
        Route::post('/shift/close', [$counter, 'closeShift'])->name('shift.close');
        Route::post('/shift/refill', [$counter, 'refill'])->name('shift.refill');
        Route::get('/customer', [$c, 'findCustomer'])->name('customer');
        Route::post('/deposit', [$counter, 'deposit'])->name('deposit');
        Route::post('/withdraw', [$counter, 'withdraw'])->name('withdraw');

        // السحب برمز العملية الذي يُصدره العميل من تطبيقه — لا برقم هاتفه.
        Route::get('/withdrawal', [$counter, 'lookupWithdrawal'])->name('withdrawal.lookup');
        Route::post('/withdrawal', [$counter, 'withdrawByCode'])->name('withdrawal.execute');
    });

    // ── الموظّفون والورديّات ──────────────────────────────────────────
    Route::prefix('staff')->name('staff.')->group(function () use ($staff) {
        Route::get('/', [$staff, 'index'])->name('index');
        Route::post('/', [$staff, 'store'])->name('store');
        Route::post('/{id}/active', [$staff, 'setActive'])->where('id', '[0-9]+')->name('active');
        Route::post('/{id}/password', [$staff, 'resetPassword'])->where('id', '[0-9]+')->name('password');
        Route::post('/{id}/limits', [$staff, 'updateLimits'])->where('id', '[0-9]+')->name('limits');
        Route::get('/shifts', [$staff, 'shifts'])->name('shifts');
        Route::post('/shifts/{id}/close', [$staff, 'closeShift'])->where('id', '[0-9]+')->name('shifts.close');

        // ملفُّ التسوية لا يُغلق بنفسه: قرارُ الفرق يُنسب إلى إنسان.
        Route::get('/shifts/{id}/statement', [$staff, 'shiftStatement'])->where('id', '[0-9]+')->name('shifts.statement');
        Route::post('/shifts/{id}/review', [$staff, 'reviewShift'])->where('id', '[0-9]+')->name('shifts.review');

        // ملفّ الموظّف الموحَّد + سجلّ عمليات الشركة.
        Route::get('/{id}/profile', [$staff, 'profile'])->where('id', '[0-9]+')->name('profile');

        // واتساب الموظّف: قناةُ **استعلامٍ** لا تنفيذ — انظر AgentWhatsappService.
        Route::post('/{id}/whatsapp', [$staff, 'linkWhatsapp'])->where('id', '[0-9]+')->name('wa.link');
        Route::post('/{id}/whatsapp/unlink', [$staff, 'unlinkWhatsapp'])->where('id', '[0-9]+')->name('wa.unlink');
    });

    Route::get('/operations', [$staff, 'operations'])->name('operations');

    // ── مساحةُ عمل الصرّاف (AMIAL-TELLER-WS-001) ───────────────────────
    //
    // **ولا معرّفَ فرعٍ ولا معرّفَ موظّفٍ في أيٍّ منها**: النطاق من هويّة
    // الداخل. وطلبُ موافقةٍ يحمل معرّف طالبه من المتصفّح يعني صرّافاً
    // يطلب باسم زميله.
    Route::prefix('teller')->name('teller.')->group(function () use ($teller) {
        Route::get('/workspace', [$teller, 'workspace'])->name('workspace');
        Route::get('/assess', [$teller, 'assess'])->name('assess');
        Route::post('/request', [$teller, 'submitRequest'])->name('request');
        Route::get('/requests', [$teller, 'pendingRequests'])->name('requests');
        Route::post('/requests/{id}/decide', [$teller, 'decideRequest'])
            ->where('id', '[0-9]+')->name('requests.decide');
        Route::post('/panic', [$teller, 'panic'])->name('panic');
        Route::post('/event', [$teller, 'logEvent'])->name('event');

        // ساعاتُ الدوام — قراءةُ كشفي، واستراحةٌ داخل ورديّتي.
        Route::get('/timesheet', [$teller, 'timesheet'])->name('timesheet');
        Route::post('/break', [$teller, 'toggleBreak'])->name('break');
    });

    // ── كشوف الصرّاف: قراءةٌ لا عمليّة ────────────────────────────────
    //
    // **وهي خارج بادئة `counter` عمداً.** حارسُ الشبّاك يمنع أيّ مسارٍ فيه
    // معرّفٌ من المتصفّح، لأنّ مسارات الشبّاك **تُحرّك مالاً**: معرّفٌ يُغيَّر
    // يعني صرّافاً يعمل على درجٍ ليس درجه.
    //
    // وهذه قراءةُ مستندٍ يملكه صاحبه، والمعرّف يُفحص ملكيّةً قبل أيّ ردّ
    // (`myStatement` تردّ ٤٠٤ لورديّةِ زميل). فلا تُوضع تحت الحارس
    // فتُضعفه، ولا تُخفى منه بحيلةٍ في شكل الرابط.
    // إيصالُ الشبّاك: صفحةٌ تُطبَع. خارج بادئة `counter` لأنّها قراءةُ
    // مستندٍ لا عمليّةُ مال — والنطاق يُفحص بالفرع قبل أيّ عرض.
    Route::get('/receipt/{number}', [$counter, 'receipt'])
        ->where('number', '[A-Za-z0-9\-]{4,40}')->name('counter.receipt');

    Route::get('/my-statements', [$counter, 'myStatements'])->name('counter.statements');
    Route::get('/my-statements/{id}', [$counter, 'myStatement'])
        ->where('id', '[0-9]+')->name('counter.statement');

    // ── إدارة الشركة: فروعٌ ونقدٌ وتقارير ─────────────────────────────
    Route::get('/overview', [$c, 'overview'])->name('overview');
    Route::get('/branches/{id}/till', [$c, 'branchTill'])->where('id', '[0-9]+')->name('branch.till');
    Route::get('/branches/{id}/commissions', [$c, 'commissions'])->where('id', '[0-9]+')->name('branch.commissions');
    Route::get('/branches/{id}/report', [$c, 'dailyReport'])->where('id', '[0-9]+')->name('branch.report');
    Route::post('/branches/{id}/hours', [$c, 'setWorkingHours'])->where('id', '[0-9]+')->name('branch.hours');
    Route::get('/reports', [$c, 'reports'])->name('reports');

    // ── الإعدادات (AMIAL-AGENT-SETTINGS-001) ──────────────────────────
    //
    // وفيها ما كان مبنيّاً بلا شاشة: ساعاتُ عمل الفرع لها نقطةُ نهاية منذ
    // شهور ولا زرّ لها، وحدُّ تنبيه النقد المنخفض — الذي تُبنى عليه
    // تنبيهات واتساب كلُّها — لم يكن يُضبط من أيّ مكان.
    Route::get('/settings', [$c, 'settings'])->name('settings');
    Route::post('/settings/password', [$c, 'changeMyPassword'])->name('settings.password');
    Route::post('/branches/{id}/thresholds', [$c, 'setBranchThresholds'])
        ->where('id', '[0-9]+')->name('branch.thresholds');
    Route::post('/announcements', [$c, 'createAnnouncement'])->name('announcements.create');
    Route::post('/announcements/{id}/toggle', [$c, 'toggleAnnouncement'])
        ->where('id', '[0-9]+')->name('announcements.toggle');
    Route::get('/settlements', [$c, 'settlements'])->name('settlements');

    // التسوية اليوميّة مع أميال — نافذةٌ ليليّة يُقفل فيها اليوم.
    Route::get('/daily-settlement', [$c, 'dailySettlement'])->name('daily.settlement');
    Route::post('/daily-settlement', [$c, 'submitDailySettlement'])->middleware('amial.idempotency')->name('daily.settlement.submit');
    Route::post('/settlements/payout', [$c, 'requestPayout'])->middleware('amial.idempotency')->name('settlements.payout');

    Route::post('/branches', [$c, 'createBranch'])->name('branch.create');
    Route::post('/branches/{id}/fund', [$c, 'fundBranch'])->where('id', '[0-9]+')->middleware('amial.idempotency')->name('branch.fund');
    Route::post('/branches/{id}/collect', [$c, 'collectBranch'])->where('id', '[0-9]+')->middleware('amial.idempotency')->name('branch.collect');
    Route::get('/branch-settlement', [$c, 'branchSettlement'])->name('branch.settlement');
    Route::post('/branches/{id}/cash', [$c, 'moveCash'])->where('id', '[0-9]+')->name('branch.cash');
    Route::post('/branches/{id}/count', [$c, 'countTill'])->where('id', '[0-9]+')->name('branch.count');
});
