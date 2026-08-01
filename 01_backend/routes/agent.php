<?php

use App\Http\Controllers\Agent\AgentCounterController;
use App\Http\Controllers\Agent\AgentPortalController;
use App\Http\Controllers\Agent\AgentStaffController;
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

// عامّ: الدخول وحده.
Route::get('/login', [$c, 'loginPage'])->name('login');
Route::post('/login', [$c, 'login'])->name('login.submit');

Route::middleware('agent.portal')->group(function () use ($c, $counter, $staff) {
    Route::get('/logout', [$c, 'logout'])->name('logout');
    Route::post('/logout', [$c, 'logout'])->name('logout.post');

    Route::get('/', [$c, 'dashboard'])->name('dashboard');

    // ── الشبّاك: لا معرّف فرعٍ في أيّ مسار ────────────────────────────
    Route::prefix('counter')->name('counter.')->group(function () use ($counter, $c) {
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
        Route::get('/shifts', [$staff, 'shifts'])->name('shifts');
        Route::post('/shifts/{id}/close', [$staff, 'closeShift'])->where('id', '[0-9]+')->name('shifts.close');
    });

    // ── إدارة الشركة: فروعٌ ونقدٌ وتقارير ─────────────────────────────
    Route::get('/overview', [$c, 'overview'])->name('overview');
    Route::get('/branches/{id}/till', [$c, 'branchTill'])->where('id', '[0-9]+')->name('branch.till');
    Route::get('/branches/{id}/commissions', [$c, 'commissions'])->where('id', '[0-9]+')->name('branch.commissions');
    Route::get('/branches/{id}/report', [$c, 'dailyReport'])->where('id', '[0-9]+')->name('branch.report');
    Route::post('/branches/{id}/hours', [$c, 'setWorkingHours'])->where('id', '[0-9]+')->name('branch.hours');
    Route::get('/settlements', [$c, 'settlements'])->name('settlements');
    Route::post('/settlements/payout', [$c, 'requestPayout'])->name('settlements.payout');

    Route::post('/branches', [$c, 'createBranch'])->name('branch.create');
    Route::post('/branches/{id}/fund', [$c, 'fundBranch'])->where('id', '[0-9]+')->name('branch.fund');
    Route::post('/branches/{id}/cash', [$c, 'moveCash'])->where('id', '[0-9]+')->name('branch.cash');
    Route::post('/branches/{id}/count', [$c, 'countTill'])->where('id', '[0-9]+')->name('branch.count');
});
