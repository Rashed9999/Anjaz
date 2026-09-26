<?php

use App\Http\Controllers\Merchant\WebAuthController as Login;
use App\Http\Controllers\Merchant\WebPortalController as Portal;
use App\Http\Controllers\Merchant\WebPlansController as Plans;
use App\Http\Controllers\Merchant\WebSectorController as Sector;
use App\Http\Controllers\Merchant\WebFinanceController as Finance;
use App\Http\Controllers\Api\V1\Amial\CustomerCreditController as Credits;
use App\Http\Controllers\Api\V1\Amial\BranchController;
use App\Http\Controllers\Api\V1\Amial\CashierController;
use App\Http\Controllers\Api\V1\Amial\MerchantController;
use App\Http\Controllers\Api\V1\Amial\MerchantOperationsCenterController as Operations;
use App\Http\Controllers\Api\V1\Amial\MerchantReceiptSettingsController as Receipts;
use App\Http\Controllers\Api\V1\Amial\MerchantStaffController as Staff;
use App\Http\Controllers\Api\V1\Amial\PosDeviceController as Devices;
use Illuminate\Support\Facades\Route;

Route::get('/login', [Login::class, 'login'])->name('login');
Route::post('/login', [Login::class, 'submit'])->middleware('throttle:10,1')->name('login.submit');

Route::middleware('merchant.web')->group(function () {
    Route::get('/', [Portal::class, 'index'])->name('dashboard');
    Route::post('/logout', [Login::class, 'logout'])->name('logout');

    // نفس وحدات API المستخدمة في التطبيق، دون نسخ منطق التجارة أو الدفتر.
    // يُطبّق فحص ملكية المنشأة أولاً في merchant.web، ثم فحص الباقة في كل باب.
    Route::prefix('data')->name('data.')->group(function () {
        Route::get('/overview', [Operations::class, 'summary'])->name('overview');
        Route::get('/plans', [Plans::class, 'show'])->name('plans');
        Route::get('/sector', [Sector::class, 'overview'])->name('sector');
        Route::get('/sector/products', [Sector::class, 'products'])->name('sector.products');
        Route::post('/sector/products', [Sector::class, 'createProduct'])
            ->middleware(['amial.usage:add_product', 'throttle:30,1'])->name('sector.products.create');
        Route::get('/sector/operations', [Sector::class, 'operations'])->name('sector.operations');
        Route::get('/roles', [Operations::class, 'roles'])->name('roles');
        Route::post('/roles', [Operations::class, 'createRole'])
            ->middleware(['capability:employees', 'throttle:20,1'])->name('roles.create');

        Route::get('/stats', [MerchantController::class, 'dailyStats'])->name('stats');
        Route::get('/wallet', [MerchantController::class, 'financialReport'])->name('wallet');
        Route::get('/ledger', [MerchantController::class, 'ledger'])->name('ledger');
        // Shared owner wallet: same financial ledger as the app.
        Route::get('/wallet-verification', [Finance::class, 'walletVerification'])
            ->name('wallet.verification');

        // One debt account per merchant/customer across web, POS and client app.
        Route::get('/debts', [Credits::class, 'dashboard'])->name('debts');
        Route::get('/debts/customers', [Credits::class, 'listCustomers'])->name('debts.customers');
        Route::post('/debts/customers', [Credits::class, 'upsertCustomer'])
            ->middleware('throttle:10,1')->name('debts.customers.save');
        Route::get('/debts/customers/{id}', [Credits::class, 'showCustomer'])
            ->whereNumber('id')->name('debts.customers.show');
        Route::get('/debts/customers/{id}/statement', [Credits::class, 'statement'])
            ->whereNumber('id')->name('debts.customers.statement');
        Route::get('/debts/customers/{id}/invoices', [Finance::class, 'debtInvoices'])
            ->whereNumber('id')->name('debts.customers.invoices');
        Route::get('/debts/customers/{id}/statement/pdf', [Credits::class, 'statementPdf'])
            ->whereNumber('id')->middleware('throttle:10,1')->name('debts.customers.statement.pdf');

        Route::get('/products', [CashierController::class, 'products'])->name('products');
        Route::post('/products', [CashierController::class, 'addProduct'])
            ->middleware(['capability:products', 'throttle:30,1'])->name('products.create');

        Route::get('/branches', [BranchController::class, 'index'])->name('branches');
        Route::post('/branches', [BranchController::class, 'store'])
            ->middleware(['capability:branches', 'throttle:10,1'])->name('branches.create');

        Route::get('/staff', [Staff::class, 'index'])->middleware('capability:employees')->name('staff');
        Route::post('/staff', [Staff::class, 'store'])
            ->middleware(['capability:employees', 'throttle:20,1'])->name('staff.create');
        Route::post('/staff/{id}/toggle', [Staff::class, 'toggle'])
            ->where('id', '[0-9]+')->middleware('capability:employees')->name('staff.toggle');

        Route::get('/devices', [Devices::class, 'index'])
            ->middleware('capability:multi_pos')->name('devices');
        Route::post('/devices/activation-codes', [Devices::class, 'createActivationCode'])
            ->middleware(['capability:multi_pos', 'throttle:10,1'])->name('devices.activate');

        Route::get('/receipt-settings', [Receipts::class, 'show'])->name('receipts');
        Route::post('/receipt-settings', [Receipts::class, 'save'])
            ->middleware('throttle:20,1')->name('receipts.save');
    });
});
