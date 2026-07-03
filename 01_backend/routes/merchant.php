<?php

use App\Http\Controllers\Merchant\Auth\LoginController;
use App\Http\Controllers\Merchant\DashboardController;
use App\Http\Controllers\Merchant\BusinessSettingsController;
use App\Http\Controllers\Merchant\TransactionController;
use App\Http\Controllers\Merchant\WithdrawController;
use Illuminate\Support\Facades\Route;

Route::group(['namespace' => 'Merchant', 'as' => 'merchant.'], function () {
    Route::group(['namespace' => 'Auth', 'prefix' => 'auth', 'as' => 'auth.'], function () {
        Route::get('/code/captcha/{tmp}', [LoginController::class, 'captcha'])->name('default-captcha');
        Route::get('login', [LoginController::class, 'login'])->name('login');
        Route::post('login', [LoginController::class, 'submit']);
        Route::get('logout', [LoginController::class, 'logout'])->name('logout');
    });

    Route::group(['middleware' => ['merchant']], function () {
        Route::get('/', [DashboardController::class, 'dashboard'])->name('dashboard');
        Route::get('settings', [DashboardController::class, 'settings'])->name('settings');
        Route::post('settings', [DashboardController::class, 'settingsUpdate']);
        Route::post('settings-password', [DashboardController::class, 'settingsPasswordUpdate'])->name('settings-password');

        Route::group(['prefix' => 'business-settings', 'as' => 'business-settings.'], function () {
            Route::get('shop-settings', [BusinessSettingsController::class, 'shopIndex'])->name('shop-settings');
            Route::post('shop-settings-update', [BusinessSettingsController::class, 'shopUpdate'])->name('shop-settings-update');
            Route::get('integration-settings', [BusinessSettingsController::class, 'integrationIndex'])->name('integration-settings');
            Route::post('integration-settings-update', [BusinessSettingsController::class, 'integrationUpdate'])->name('integration-settings-update');
        });

        Route::get('/transaction', [TransactionController::class, 'transaction'])->name('transaction');

        Route::group(['prefix' => 'withdraw', 'as' => 'withdraw.'], function () {
            Route::get('/list', [WithdrawController::class, 'list'])->name('list');
            Route::get('/request', [WithdrawController::class, 'withdrawRequests'])->name('request');
            Route::post('/request-store', [WithdrawController::class, 'withdrawRequestStore'])->name('request-store');
            Route::get('/method-data', [WithdrawController::class, 'withdrawMethod'])->name('method-data');
            Route::get('download', [WithdrawController::class, 'download'])->name('download');
        });
    });
});
