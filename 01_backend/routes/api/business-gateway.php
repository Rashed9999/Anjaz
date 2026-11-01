<?php

use App\Http\Controllers\Api\V1\Business\BusinessGatewayController;
use Illuminate\Support\Facades\Route;

/*
 * AMIAL-BUSINESS-GATEWAY-001 — قناة «أميال أعمال» (خادمٌ لخادم).
 * البادئة api/v1/business تُضاف في bootstrap/app.php.
 *
 * لا مصادقة مستخدم هنا: كل طلب موقّع بسرّ القناة، والرابط من ترويسته.
 * ولا نقطة تُحرّك مالاً: نيّة الدفع تُنشئ طلب QR يدفعه العميل من تطبيقه.
 */
Route::middleware(['amial.business.signature', 'throttle:120,1'])
    ->name('amial.business.')
    ->group(function () {
        Route::get('/link', [BusinessGatewayController::class, 'link'])->name('link');
        Route::get('/wallet', [BusinessGatewayController::class, 'wallet'])->name('wallet');
        Route::get('/fees/quote', [BusinessGatewayController::class, 'feeQuote'])->name('fees.quote');
        Route::get('/entitlements', [BusinessGatewayController::class, 'entitlements'])->name('entitlements');
        Route::post('/audit-events', [BusinessGatewayController::class, 'auditEvent'])->name('audit-events');

        Route::post('/payment-intents', [BusinessGatewayController::class, 'createIntent'])
            ->name('payment-intents.create');
        Route::get('/payment-intents/{intent}', [BusinessGatewayController::class, 'showIntent'])
            ->where('intent', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('payment-intents.show');
        // AMIAL-BUSINESS-MIGRATION-001 — تصدير للقراءة فقط لترحيل التاجر.
        Route::get('/migration/summary', [BusinessGatewayController::class, 'migrationSummary'])->name('migration.summary');
        Route::get('/migration/{section}', [BusinessGatewayController::class, 'migrationPage'])
            ->whereIn('section', ['products', 'customers', 'suppliers'])->name('migration.page');

        // AMIAL-BUSINESS-CREDIT-001 — الآجل: التسليم · مرآة الديون · التحقق من صاحب الرقم.
        Route::post('/credit/handover', [BusinessGatewayController::class, 'creditHandover'])->name('credit.handover');
        Route::put('/customer-debts/{contactRef}', [BusinessGatewayController::class, 'upsertCustomerDebt'])
            ->where('contactRef', '[A-Za-z0-9_.:-]{1,64}')->name('customer-debts.upsert');
        // AMIAL-BUSINESS-REFUND-001 — الاسترداد يُطلب هنا ويُعتمد من التاجر نفسه.
        Route::post('/refunds', [BusinessGatewayController::class, 'requestRefund'])->name('refunds.request');
        Route::get('/refunds/{refund}', [BusinessGatewayController::class, 'showRefund'])
            ->where('refund', '[0-9A-HJKMNP-TV-Z]{26}')->name('refunds.show');
        Route::post('/customers/resolve', [BusinessGatewayController::class, 'resolveCustomer'])->name('customers.resolve');

        Route::post('/payment-intents/{intent}/cancel', [BusinessGatewayController::class, 'cancelIntent'])
            ->where('intent', '[0-9A-HJKMNP-TV-Z]{26}')
            ->name('payment-intents.cancel');
    });
