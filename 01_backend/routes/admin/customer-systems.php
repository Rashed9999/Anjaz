<?php

use App\Http\Controllers\Admin\CustomerSystemsCenterController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-CUSTOMER-SYSTEMS-CENTER-001
 *
 * سطح رقابي موحد فوق مصادر الحقيقة القائمة. لا يكتب أرصدة ولا يغير
 * سياسات من هنا؛ الأوامر تقود إلى الشاشات الأصلية المحكومة بصلاحياتها.
 */
Route::middleware([
        'web',
        'admin',
        'amial.force-pin-change',
        'platform:platform.audit.view',
    ])
    ->prefix('admin/amial/customer-systems')
    ->name('admin.amial.customer-systems.')
    ->group(function () {
        Route::get('/', [CustomerSystemsCenterController::class, 'index'])
            ->name('index');
        Route::get('/snapshot.json', [CustomerSystemsCenterController::class, 'snapshot'])
            ->name('snapshot');
    });
