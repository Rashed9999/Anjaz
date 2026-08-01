<?php

use App\Http\Controllers\Agent\AgentPortalController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-AGENT-PORTAL-001 — بوّابة الوكيل.
 *
 * وكلاء أميال شركات صرافة، وموظّفوها على الكمبيوتر لا الهاتف. وهذه بوّابتهم
 * — منفصلةٌ عن لوحة الإدارة بجمهورها ومصادقتها وصلاحياتها.
 */

$c = AgentPortalController::class;

// عامّ: الدخول وحده.
Route::get('/login', [$c, 'loginPage'])->name('login');
Route::post('/login', [$c, 'login'])->name('login.submit');

Route::middleware('agent.portal')->group(function () use ($c) {
    Route::get('/logout', [$c, 'logout'])->name('logout');

    Route::get('/', [$c, 'dashboard'])->name('dashboard');

    // بيانات اللوحة
    Route::get('/overview', [$c, 'overview'])->name('overview');
    Route::get('/branches/{id}/till', [$c, 'branchTill'])->where('id', '[0-9]+')->name('branch.till');

    // الشبّاك: بحث ثمّ إيداع أو سحب
    Route::get('/customer', [$c, 'findCustomer'])->name('customer.find');
    Route::post('/branches/{id}/deposit', [$c, 'deposit'])->where('id', '[0-9]+')->name('deposit');
    Route::post('/branches/{id}/withdraw', [$c, 'withdraw'])->where('id', '[0-9]+')->name('withdraw');

    // العمولات والتسويات والتقارير (الفصل ٠٣)
    Route::get('/branches/{id}/commissions', [$c, 'commissions'])->where('id', '[0-9]+')->name('branch.commissions');
    Route::get('/branches/{id}/report', [$c, 'dailyReport'])->where('id', '[0-9]+')->name('branch.report');
    Route::post('/branches/{id}/hours', [$c, 'setWorkingHours'])->where('id', '[0-9]+')->name('branch.hours');
    Route::get('/settlements', [$c, 'settlements'])->name('settlements');

    // إدارة الفروع والنقد
    Route::post('/branches', [$c, 'createBranch'])->name('branch.create');
    Route::post('/branches/{id}/fund', [$c, 'fundBranch'])->where('id', '[0-9]+')->name('branch.fund');
    Route::post('/branches/{id}/cash', [$c, 'moveCash'])->where('id', '[0-9]+')->name('branch.cash');
    Route::post('/branches/{id}/count', [$c, 'countTill'])->where('id', '[0-9]+')->name('branch.count');
});
