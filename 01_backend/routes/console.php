<?php

use App\Jobs\ReconcilePendingBillOrdersJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes (Laravel 11 style — Schedule via routes/console.php)
|--------------------------------------------------------------------------
|
| تعليمات الدمج: لو الـ console.php الأصلي يحتوي محتوى مختلف،
| ادمج الـ Schedule:: blocks أدناه فقط.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ============================================================
// AMIAL-BILL-PAY-001 (v0.9-C) — Scheduled jobs
// ============================================================

/**
 * يفحص bill orders الـ pending_provider_confirmation كل دقيقة.
 * مهم لاستلام الـ status من المزود حتى لو فشل الـ initial response.
 */
Schedule::job(new ReconcilePendingBillOrdersJob())
    ->everyMinute()
    ->withoutOverlapping(10) // 10 min lock — يمنع overlap لو الـ job أخذ أكثر من دقيقة
    ->onOneServer()           // مهم لو deploy بـ multiple servers
    ->description('AMIAL-BILL-PAY: Reconcile pending bill orders with provider');

// AMIAL-TRANSFER-COOLDOWN-001 (v2.7): تسليم التحويلات بعد انتهاء نافذة الإلغاء
Schedule::job(new \App\Jobs\ReleasePendingTransfersJob())
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer()
    ->description('AMIAL-TRANSFER-COOLDOWN: Release pending transfers past cooldown');

// مستقبلاً نضيف هنا:
// - Schedule::command('amial:family-fund:cleanup-expired-invitations')->daily();
// - Schedule::command('amial:legal:cleanup-orphan-acceptances')->weekly();
// - Schedule::command('amial:audit:archive-old-records')->monthlyOn(1, '02:00');

// AMIAL-REPORTS-001 (v2.11): تنظيف التقارير المنتهية يومياً
Schedule::call(function () {
    app(\App\Services\ReportService::class)->cleanupExpired();
})->daily()->description('AMIAL-REPORTS: Cleanup expired report exports');

// ============================================================
// AMIAL-SCALE-FEES-001 — تسوية رسوم المنصّة (كل دقيقة)
// AMIAL-CUSTOMER-WITHDRAW-001 — فكّ حجز السحوبات المنتهية (كل دقيقة)
// ============================================================
Schedule::command('amial:reconcile-fees')->everyMinute()->withoutOverlapping();

Schedule::call(function () {
    app(\App\Services\CustomerWithdrawService::class)->expireStale();
})->everyMinute()->name('amial-expire-withdrawals')->withoutOverlapping();
