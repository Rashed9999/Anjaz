<?php

use App\Http\Controllers\Merchant\WebAuthController as Login;
use App\Http\Controllers\Merchant\WebPortalController as Portal;
use App\Http\Controllers\Merchant\WebDashboardController as Dashboard;
use App\Http\Controllers\Merchant\WebSalesController as SalesDirectory;
use App\Http\Controllers\Merchant\WebProductsController as ProductsDirectory;
use App\Http\Controllers\Api\V1\Amial\CreditCollectionController as Collections;
use App\Http\Controllers\Merchant\WebPlansController as Plans;
use App\Http\Controllers\Merchant\WebSectorController as Sector;
use App\Http\Controllers\Merchant\WebFinanceController as Finance;
use App\Http\Controllers\Merchant\WebApprovalController as Approvals;
use App\Http\Controllers\Merchant\WebAssetController as Assets;
use App\Http\Controllers\Merchant\WebProcurementDocumentController as ProcurementDocuments;
use App\Http\Controllers\Api\V1\Amial\CustomerCreditController as Credits;
use App\Http\Controllers\Api\V1\Amial\BranchController;
use App\Http\Controllers\Api\V1\Amial\CashierController;
use App\Http\Controllers\Api\V1\Amial\MerchantController;
use App\Http\Controllers\Api\V1\Amial\MerchantOperationsCenterController as Operations;
use App\Http\Controllers\Api\V1\Amial\MerchantReceiptSettingsController as Receipts;
use App\Http\Controllers\Api\V1\Amial\MerchantStaffController as Staff;
use App\Http\Controllers\Api\V1\Amial\PosDeviceController as Devices;
use App\Http\Controllers\Api\V1\Amial\SupplierController as Suppliers;
use App\Http\Controllers\Api\V1\Amial\ExpenseController as Expenses;
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
        Route::get('/dashboard-v2', [Dashboard::class, 'show'])->name('dashboard-v2');
        Route::prefix('/report-exports')->name('report-exports.')->group(function () {
            $reports = \App\Http\Controllers\Api\V1\Amial\ReportController::class;
            Route::get('/', [$reports, 'index'])->name('index');
            Route::post('/', [$reports, 'request'])->middleware('throttle:15,1')->name('request');
            Route::get('/{ulid}/status', [$reports, 'status'])->where('ulid', '[A-Z0-9]{26}')->name('status');
            Route::get('/{ulid}/download', [$reports, 'download'])->where('ulid', '[A-Z0-9]{26}')->name('download');
        });
        Route::prefix('/integrations/api-keys')->name('integrations.api-keys.')->group(function () {
            $apiKeys = \App\Http\Controllers\Api\V1\Amial\MerchantApiKeyController::class;
            Route::get('/', [$apiKeys, 'index'])->name('index');
            Route::post('/', [$apiKeys, 'store'])->middleware('throttle:20,1')->name('store');
            Route::post('/{id}/toggle', [$apiKeys, 'toggle'])->whereNumber('id')
                ->middleware('throttle:30,1')->name('toggle');
            Route::delete('/{id}', [$apiKeys, 'destroy'])->whereNumber('id')
                ->middleware('throttle:20,1')->name('destroy');
        });
        Route::get('/sales-v2', [SalesDirectory::class, 'index'])->name('sales-v2');
        Route::get('/products-v2', [ProductsDirectory::class, 'index'])->name('products-v2');
        Route::get('/plans', [Plans::class, 'show'])->name('plans');
        Route::get('/sector', [Sector::class, 'overview'])->name('sector');
        Route::get('/sector/types', [Sector::class, 'businessTypes'])->name('sector.types');
        Route::put('/sector/type', [Sector::class, 'updateBusinessType'])
            ->middleware('throttle:10,1')->name('sector.type.update');
        Route::get('/sector/products', [Sector::class, 'products'])->name('sector.products');
        Route::get('/sector/products/lookup', [Sector::class, 'lookupBarcode'])
            ->middleware('throttle:120,1')->name('sector.products.lookup');
        Route::get('/sector/catalog/lookup', [Sector::class, 'catalogueLookup'])
            ->middleware('throttle:60,1')->name('sector.catalog.lookup');
        Route::get('/sector/catalog/options', [Sector::class, 'catalogueOptions'])->name('sector.catalog.options');
        Route::post('/sector/catalog/{kind}', [Sector::class, 'addCatalogueOption'])
            ->where('kind', 'categories|brands|units')->middleware('throttle:30,1')->name('sector.catalog.add');
        Route::put('/sector/products/{id}', [Sector::class, 'updateProduct'])
            ->whereNumber('id')->middleware('throttle:30,1')->name('sector.products.update');
        Route::get('/sector/products/{id}/inventory', [Sector::class, 'productInventory'])
            ->whereNumber('id')->name('sector.products.inventory');
        Route::post('/sector/products/{id}/inventory', [Sector::class, 'receiveProductInventory'])
            ->whereNumber('id')->middleware('throttle:30,1')->name('sector.products.inventory.receive');
        Route::post('/sector/products/{id}/units', [Sector::class, 'saveWholesaleProductUnit'])
            ->whereNumber('id')->middleware('throttle:30,1')->name('sector.products.units.save');
        Route::post('/sector/products/{id}/barcodes', [Sector::class, 'addProductBarcode'])
            ->whereNumber('id')->middleware('throttle:30,1')->name('sector.products.barcodes.add');
        Route::post('/sector/products', [Sector::class, 'createProduct'])
            ->middleware(['amial.usage:add_product', 'throttle:30,1'])->name('sector.products.create');
        Route::get('/sector/operations', [Sector::class, 'operations'])->name('sector.operations');
        Route::get('/sector/customers', [Sector::class, 'customers'])->name('sector.customers');
        Route::get('/sector/customers/{id}/profile', [Sector::class, 'customerProfile'])
            ->whereNumber('id')->name('sector.customers.profile');
        Route::post('/sector/customers', [Sector::class, 'createCustomer'])
            ->middleware(['capability:customers', 'throttle:30,1'])->name('sector.customers.create');
        Route::put('/sector/customers/{id}', [Sector::class, 'updateCustomer'])
            ->whereNumber('id')->middleware(['capability:customers', 'throttle:30,1'])
            ->name('sector.customers.update');
        Route::get('/sector/returns', [Sector::class, 'returns'])->name('sector.returns');
        Route::get('/sector/sales/{id}/return-info', [Sector::class, 'returnInfo'])
            ->name('sector.returns.info');
        Route::post('/sector/sales/{id}/returns', [Sector::class, 'createSaleReturn'])
            ->middleware(['amial.idempotency', 'amial.rate-limit:merchant_web_return,30,1'])
            ->name('sector.returns.create');
        Route::post('/sector/returns/{id}/resolve', [Sector::class, 'resolveSaleReturn'])
            ->whereNumber('id')->middleware(['amial.idempotency', 'amial.rate-limit:merchant_web_return_resolve,30,1'])
            ->name('sector.returns.resolve');
        Route::post('/sector/returns/{id}/settle', [Sector::class, 'settleSaleReturn'])
            ->whereNumber('id')->middleware(['amial.idempotency', 'amial.rate-limit:merchant_web_return_settle,20,1'])
            ->name('sector.returns.settle');
        Route::get('/sector/sales', [Sector::class, 'sales'])->name('sector.sales');
        Route::get('/sector/sales/{id}', [Sector::class, 'sale'])
            ->where('id', '[A-Za-z0-9-]+')->name('sector.sales.show');
        Route::get('/sector/sales/{id}/invoice', [Sector::class, 'saleInvoice'])
            ->where('id', '[A-Za-z0-9-]+')->middleware('throttle:15,1')->name('sector.sales.invoice');
        Route::get('/roles', [Operations::class, 'roles'])->name('roles');
        Route::post('/roles', [Operations::class, 'createRole'])
            ->middleware(['capability:employees', 'throttle:20,1'])->name('roles.create');

        Route::get('/stats', [MerchantController::class, 'dailyStats'])->name('stats');
        Route::get('/profit-report', [CashierController::class, 'profitReport'])
            ->middleware('capability:profit_reports')->name('profit-report');
        Route::get('/wallet', [MerchantController::class, 'financialReport'])->name('wallet');
        Route::get('/ledger', [MerchantController::class, 'ledger'])->name('ledger');
        // Shared owner wallet: same financial ledger as the app.
        Route::get('/wallet-verification', [Finance::class, 'walletVerification'])
            ->name('wallet.verification');
        Route::get('/wallet-origins', [Finance::class, 'walletOrigins'])
            ->name('wallet.origins');

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
        Route::post('/debts/customers/{id}/collect-cash', [Collections::class, 'collectCash'])
            ->whereNumber('id')->middleware('throttle:20,1')->name('debts.collect.cash');
        Route::post('/debts/customers/{id}/request-wallet', [Collections::class, 'requestWallet'])
            ->whereNumber('id')->middleware('throttle:20,1')->name('debts.collect.wallet.request');
        Route::get('/debts/collections/pending', [Collections::class, 'list'])
            ->name('debts.collect.pending');
        Route::post('/debts/collections/{collection}/confirm', [Collections::class, 'confirmWallet'])
            ->whereNumber('collection')->middleware('throttle:30,1')->name('debts.collect.wallet.confirm');
        Route::get('/debts/collections/{collection}', [Collections::class, 'status'])
            ->whereNumber('collection')->name('debts.collect.status');
        Route::get('/debts/collections/{collection}/receipt', [Collections::class, 'receipt'])
            ->whereNumber('collection')->middleware('throttle:15,1')->name('debts.collect.receipt');

        Route::get('/products', [CashierController::class, 'products'])->name('products');
        Route::post('/products', [CashierController::class, 'addProduct'])
            ->middleware(['capability:products', 'throttle:30,1'])->name('products.create');

        // AMIAL-MERCHANT-WEB-PROCUREMENT-001 — الموردون والمشتريات انتقلت
        // إلى بوابة المالك، لكن مصدر الحقيقة يبقى SupplierController نفسه.
        // كل كتابة مالية تحمل idempotency، والقدرة تُفحص على الخادم لا في الواجهة.
        Route::prefix('suppliers')->name('suppliers.')->middleware('capability:suppliers')->group(function () {
            Route::get('/', [Suppliers::class, 'index'])->name('index');
            Route::post('/', [Suppliers::class, 'store'])
                ->middleware(['amial.idempotency', 'throttle:20,1'])->name('store');
            Route::get('/{id}', [Suppliers::class, 'show'])->whereNumber('id')->name('show');
            Route::get('/{id}/statement/pdf', [ProcurementDocuments::class, 'supplierStatement'])
                ->whereNumber('id')->middleware('throttle:15,1')->name('statement.pdf');
            Route::post('/{id}/payment', [Suppliers::class, 'payment'])
                ->whereNumber('id')
                ->middleware(['amial.idempotency', 'throttle:30,1'])->name('payment');
            Route::post('/{id}/credit-refund', [Suppliers::class, 'creditRefund'])
                ->whereNumber('id')
                ->middleware(['amial.idempotency', 'throttle:30,1'])->name('credit-refund');
            Route::post('/{id}/wallet-payment', [Suppliers::class, 'walletPayment'])
                ->whereNumber('id')
                ->middleware(['amial.idempotency', 'throttle:20,1'])->name('wallet-payment');
        });
        Route::prefix('purchase-orders')->name('purchase-orders.')->middleware('capability:purchases')->group(function () {
            Route::get('/', [Suppliers::class, 'poIndex'])->name('index');
            Route::post('/', [Suppliers::class, 'poStore'])
                ->middleware(['amial.idempotency', 'throttle:30,1'])->name('store');
            Route::get('/{id}', [Suppliers::class, 'poShow'])->whereNumber('id')->name('show');
            Route::get('/{id}/pdf', [ProcurementDocuments::class, 'purchaseOrder'])
                ->whereNumber('id')->middleware('throttle:15,1')->name('pdf');
            Route::post('/{id}/approve', [Suppliers::class, 'poApprove'])
                ->whereNumber('id')->middleware('amial.idempotency')->name('approve');
            Route::post('/{id}/receive', [Suppliers::class, 'poReceive'])
                ->whereNumber('id')
                ->middleware(['amial.idempotency', 'throttle:30,1'])->name('receive');
            Route::post('/{id}/cancel', [Suppliers::class, 'poCancel'])
                ->whereNumber('id')->middleware('amial.idempotency')->name('cancel');
        });
        Route::get('/supplier-payments/{entryUlid}/pdf', [ProcurementDocuments::class, 'supplierPayment'])
            ->where('entryUlid', '[0-9A-Za-z]{26}')
            ->middleware(['capability:suppliers', 'throttle:15,1'])
            ->name('supplier-payments.pdf');

        Route::prefix('purchase-returns')->name('purchase-returns.')->middleware('capability:purchases')->group(function () {
            Route::get('/', [Suppliers::class, 'prIndex'])->name('index');
            Route::post('/', [Suppliers::class, 'prStore'])->middleware('amial.idempotency')->name('store');
            Route::get('/{id}', [Suppliers::class, 'prShow'])->whereNumber('id')->name('show');
            Route::post('/{id}/approve', [Suppliers::class, 'prApprove'])
                ->whereNumber('id')->middleware('amial.idempotency')->name('approve');
            Route::post('/{id}/reject', [Suppliers::class, 'prReject'])
                ->whereNumber('id')->middleware('amial.idempotency')->name('reject');
        });

        // المصروفات من بوابة المالك نفسها؛ لا شاشة Flutter موازية بعد نقل التاجر للويب.
        Route::prefix('expenses')->name('expenses.')->middleware('capability:expenses')->group(function () {
            Route::get('/', [Expenses::class, 'index'])->name('index');
            Route::post('/', [Expenses::class, 'store'])->middleware('amial.idempotency')->name('store');
            Route::post('/{id}', [Expenses::class, 'update'])
                ->whereNumber('id')->middleware('amial.idempotency')->name('update');
            Route::delete('/{id}', [Expenses::class, 'destroy'])
                ->whereNumber('id')->middleware('amial.idempotency')->name('destroy');
        });

        Route::prefix('assets')->name('assets.')->middleware('capability:expenses')->group(function () {
            Route::get('/', [Assets::class, 'index'])->name('index');
            Route::get('/{id}', [Assets::class, 'show'])->whereNumber('id')->name('show');
            Route::post('/opening', [Assets::class, 'storeOpening'])
                ->middleware(['amial.idempotency', 'throttle:20,1'])->name('opening');
            Route::post('/depreciation', [Assets::class, 'postDepreciation'])
                ->middleware(['amial.idempotency', 'throttle:10,1'])->name('depreciation');
            Route::post('/{id}/dispose', [Assets::class, 'dispose'])
                ->whereNumber('id')->middleware(['amial.idempotency', 'throttle:10,1'])->name('dispose');
        });

        Route::get('/branches', [BranchController::class, 'index'])->name('branches');
        Route::post('/branches', [BranchController::class, 'store'])
            ->middleware(['capability:branches', 'throttle:10,1'])->name('branches.create');

        // طلبات اعتماد POS: الموظف يطلب من مسار الفعل، والمالك يقرر هنا.
        Route::get('/approvals', [Approvals::class, 'index'])->name('approvals');
        Route::post('/approvals/{id}/grant', [Approvals::class, 'grant'])
            ->whereNumber('id')->middleware('throttle:30,1')->name('approvals.grant');
        Route::post('/approvals/{id}/reject', [Approvals::class, 'reject'])
            ->whereNumber('id')->middleware('throttle:30,1')->name('approvals.reject');

        Route::get('/staff', [Staff::class, 'index'])->middleware('capability:employees')->name('staff');
        Route::get('/staff-performance', [Staff::class, 'performance'])
            ->middleware('capability:employees')->name('staff.performance');
        Route::post('/staff', [Staff::class, 'store'])
            ->middleware(['capability:employees', 'throttle:20,1'])->name('staff.create');
        Route::post('/staff/{id}/toggle', [Staff::class, 'toggle'])
            ->where('id', '[0-9]+')->middleware('capability:employees')->name('staff.toggle');
        Route::post('/staff/{id}/branch', [Staff::class, 'assignBranch'])
            ->whereNumber('id')->middleware(['capability:employees', 'throttle:30,1'])->name('staff.branch');
        Route::post('/staff/{id}/role', [Staff::class, 'setRole'])
            ->whereNumber('id')->middleware(['capability:employees', 'throttle:30,1'])->name('staff.role');

        Route::get('/devices', [Devices::class, 'index'])
            ->middleware('capability:multi_pos')->name('devices');
        Route::post('/devices/activation-codes', [Devices::class, 'createActivationCode'])
            ->middleware(['capability:multi_pos', 'throttle:10,1'])->name('devices.activate');
        Route::patch('/devices/{id}', [Devices::class, 'update'])
            ->whereNumber('id')->middleware(['capability:multi_pos', 'throttle:30,1'])->name('devices.update');
        Route::delete('/devices/{id}', [Devices::class, 'destroy'])
            ->whereNumber('id')->middleware(['capability:multi_pos', 'throttle:20,1'])->name('devices.destroy');

        Route::get('/receipt-settings', [Receipts::class, 'show'])->name('receipts');
        Route::post('/receipt-settings', [Receipts::class, 'save'])
            ->middleware('throttle:20,1')->name('receipts.save');
    });
});
