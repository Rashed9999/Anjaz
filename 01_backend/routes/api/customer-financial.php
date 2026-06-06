<?php

/**
 * AMIAL-ZONE-001 hotfix (v0.7-A.1)
 *
 * هذا الملف يحدد كيف يجب أن تبدو الـ routes المالية للعميل بعد تطبيق
 * كل الـ amial middlewares. إنه **drop-in replacement** لمجموعة routes
 * العميل المالية في routes/api.php.
 *
 * تعليمات الدمج:
 *   1. افتح routes/api.php الأصلي
 *   2. ابحث عن المجموعة التي تحتوي:
 *      - POST /send-money
 *      - POST /cash-out
 *      - POST /request-money
 *      - POST /withdraw
 *      - POST /add-money
 *   3. استبدلها بالكتلة أدناه (مع الاحتفاظ بـ controller imports الموجودة)
 *   4. أضف هذا الـ require في routes/api.php:
 *        require base_path('routes/api/customer-financial.php');
 *      (وتأكد أن لا يوجد double-registration للـ routes)
 */

use App\Http\Controllers\Api\V1\Customer\TransactionController;
use Illuminate\Support\Facades\Route;

/*
 * ترتيب الـ middlewares مقصود:
 *
 *   auth:api          ← التأكد من المصادقة
 *   amial.terms       ← التأكد من قبول السياسة (TERMS_ACCEPTANCE_REQUIRED)
 *   amial.idempotency ← منع تكرار الطلب (DUPLICATE_TRANSACTION)
 *   amial.zone:X      ← فحص الـ zone للـ action المعينة (TX_ZONE_BLOCKED)
 *   trackLastActiveAt ← تتبع آخر نشاط (موجود قديماً)
 *
 * كل middleware يفشل بأقل تكلفة ممكنة قبل الوصول للقلب المالي.
 */

Route::prefix('v1/customer')
    ->middleware([
        'auth:api',
        'trackLastActiveAt',
        'amial.terms',
        'amial.idempotency',
    ])
    ->group(function () {

        Route::post('/send-money', [TransactionController::class, 'sendMoney'])
            ->middleware('amial.zone:send_money')
            ->name('customer.send-money');

        Route::post('/cash-out', [TransactionController::class, 'cashOut'])
            ->middleware('amial.zone:cash_out')
            ->name('customer.cash-out');

        Route::post('/request-money', [TransactionController::class, 'requestMoney'])
            ->middleware('amial.zone:request_money')
            ->name('customer.request-money');

        Route::post('/add-money', [TransactionController::class, 'addMoney'])
            ->middleware('amial.zone:add_money')
            ->name('customer.add-money');

        // Withdraw — controller منفصل في الأصلي، لو في customer/WithdrawController:
        // Route::post('/withdraw', [WithdrawController::class, 'request'])
        //     ->middleware('amial.zone:withdraw');

        // ملاحظة: GET endpoints (view balance, history, ...) لا تحتاج amial.zone
        // لأن العرض read-only مسموح خارج SOUTH.
    });
