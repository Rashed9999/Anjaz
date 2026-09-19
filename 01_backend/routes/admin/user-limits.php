<?php

use App\Http\Controllers\Admin\UserLimitCenterController;
use Illuminate\Support\Facades\Route;

/**
 * AMIAL-USER-LIMIT-CENTER-002
 *
 * مركز واحد لسياسة حدود مستويات العميل والاستثناءات الفردية.
 * القراءة منفصلة عن تعديل عميل، وتعديل سياسة مستوى كامل يحتاج settings.update.
 */
Route::middleware(['web', 'admin', 'amial.force-pin-change'])
    ->prefix('admin/amial/hub/limits')
    ->name('admin.amial.hub.limits.')
    ->group(function () {
        Route::middleware('platform:platform.customers.view')->group(function () {
            Route::get('/', [UserLimitCenterController::class, 'index'])->name('index');
            Route::get('/overview.json', [UserLimitCenterController::class, 'overview'])->name('overview');
            Route::get('/users.json', [UserLimitCenterController::class, 'users'])->name('users');
        });

        Route::post('/users/{userId}', [UserLimitCenterController::class, 'update'])
            ->where('userId', '[0-9]+')
            ->middleware('amial.idempotency')
            ->name('users.update');

        Route::post('/tiers/{tier}', [UserLimitCenterController::class, 'updateTierPolicy'])
            ->where('tier', '[1-3]')
            ->middleware(['platform:platform.settings.update', 'amial.idempotency'])
            ->name('tiers.update');
    });
