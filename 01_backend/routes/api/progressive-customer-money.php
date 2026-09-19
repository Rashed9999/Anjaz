<?php

use App\Http\Controllers\Api\V1\Amial\ProgressiveCustomerMoneyController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-PROGRESSIVE-MONEY-ROUTES-001
 *
 * يُحمّل هذا الملف بعد routes/api/v1/api.php تحت نفس prefix: api/v1.
 * لذلك يستبدل الأبواب المالية الأربعة القديمة فقط، ويبقى كل ما عداها من
 * 6cash كما هو. لا مسار مالي موازٍ ولا حاجة لتغيير Flutter أو أي عميل API.
 */
Route::prefix('customer')
    ->middleware([
        'deviceVerify',
        'inactiveAuthCheck',
        'trackLastActiveAt',
        'auth:api',
        // P0-LEGAL: لا حركة مالية قبل قبول أحدث إصدار منشور.
        'amial.terms',
        'customerAuth',
        'checkDeviceId',
        'amial.pos-device',
    ])
    ->group(function () {
        Route::post('send-money', [ProgressiveCustomerMoneyController::class, 'sendMoney'])
            ->middleware(['amial.zone:send_money', 'amial.idempotency']);

        Route::post('cash-out', [ProgressiveCustomerMoneyController::class, 'cashOut'])
            ->middleware(['amial.zone:cash_out', 'amial.idempotency']);

        Route::post('request-money', [ProgressiveCustomerMoneyController::class, 'requestMoney'])
            ->middleware(['amial.zone:request_money', 'amial.idempotency']);

        Route::post('request-money/{slug}', [ProgressiveCustomerMoneyController::class, 'requestMoneyStatus'])
            ->whereIn('slug', ['approve', 'deny'])
            ->middleware('amial.idempotency');
    });
