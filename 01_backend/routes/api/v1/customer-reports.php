<?php

use App\Http\Controllers\Api\V1\Customer\CustomerReportController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-CUSTOMER-REPORTS-003 — مسار مستقل حتى لا نوسّع ملف api.php الضخم.
 * نفس سلسلة حماية العميل المستخدمة في بقية مسارات /customer.
 */
Route::middleware([
    'api',
    'inactiveAuthCheck',
    'trackLastActiveAt',
    'auth:api',
    'customerAuth',
    'checkDeviceId',
    'amial.pos-device',
])->prefix('api/v1/customer/reports')->group(function (): void {
    Route::get('/summary', [CustomerReportController::class, 'summary'])
        ->name('api.v1.customer.reports.summary');
});
