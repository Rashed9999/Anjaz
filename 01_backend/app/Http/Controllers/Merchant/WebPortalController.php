<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Services\Access\EntitlementService;
use App\Services\Merchant\MerchantPortalNavigationService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** صفحة الويب تعرض مصادر الخادم القائمة، ولا تُنشئ محرك مالٍ أو POS موازياً. */
class WebPortalController extends Controller
{
    public function index(
        Request $request,
        EntitlementService $entitlements,
        MerchantPortalNavigationService $navigation,
    ): View
    {
        $owner = $request->user('merchant_web');
        $profile = MerchantProfile::where('user_id', $owner->id)->firstOrFail();
        $merchant = Merchant::where('user_id', $owner->id)->first();

        $effectivePlan = A::canonicalPlan($profile->subscription_plan);
        $expired = $effectivePlan !== A::PLAN_FREE
            && $profile->subscription_expires_at !== null
            && $profile->subscription_expires_at->isPast();
        if ($expired) $effectivePlan = A::PLAN_FREE;

        // Generate the route map in PHP rather than nesting route() calls inside Blade @json,
        // whose parser can close on the first nested function call and cause a 500.
        $merchantRoutes = [
            'overview' => route('merchant.web.data.overview'),
            'sector' => route('merchant.web.data.sector'),
            'sectorTypes' => route('merchant.web.data.sector.types'),
            'sectorTypeSave' => route('merchant.web.data.sector.type.update'),
            'sectorProducts' => route('merchant.web.data.sector.products'),
            'sectorBarcodeLookup' => route('merchant.web.data.sector.products.lookup'),
            'sectorCatalogLookup' => route('merchant.web.data.sector.catalog.lookup'),
            'sectorCatalogOptions' => route('merchant.web.data.sector.catalog.options'),
            'sectorCatalogAdd' => route('merchant.web.data.sector.catalog.add', ['kind' => '__KIND__']),
            'sectorProductsUpdate' => route('merchant.web.data.sector.products.update', ['id' => '__ID__']),
            'sectorProductBarcodeAdd' => route('merchant.web.data.sector.products.barcodes.add', ['id' => '__ID__']),
            'sectorProductsCreate' => route('merchant.web.data.sector.products.create'),
            'sectorOperations' => route('merchant.web.data.sector.operations'),
            'sectorSales' => route('merchant.web.data.sector.sales'),
            'sectorSaleDetail' => route('merchant.web.data.sector.sales.show', ['id' => '__ID__']),
            'sectorSaleInvoice' => route('merchant.web.data.sector.sales.invoice', ['id' => '__ID__']),
            'plans' => route('merchant.web.data.plans'),
            'stats' => route('merchant.web.data.stats'),
            'profitReport' => route('merchant.web.data.profit-report'),
            'wallet' => route('merchant.web.data.wallet'),
            'ledger' => route('merchant.web.data.ledger'),
            'walletVerification' => route('merchant.web.data.wallet.verification'),
            'walletOrigins' => route('merchant.web.data.wallet.origins'),
            'debts' => route('merchant.web.data.debts'),
            'debtCustomers' => route('merchant.web.data.debts.customers'),
            'debtCustomersSave' => route('merchant.web.data.debts.customers.save'),
            'debtCollectCash' => route('merchant.web.data.debts.collect.cash', ['id'=>'__ID__']),
            'debtRequestWallet' => route('merchant.web.data.debts.collect.wallet.request', ['id'=>'__ID__']),
            'debtPending' => route('merchant.web.data.debts.collect.pending'),
            'debtConfirmWallet' => route('merchant.web.data.debts.collect.wallet.confirm', ['collection'=>'__ID__']),
            'debtCollectionReceipt' => route('merchant.web.data.debts.collect.receipt', ['collection'=>'__ID__']),
            'debtStatement' => route('merchant.web.data.debts.customers.statement', ['id' => '__ID__']),
            'debtInvoices' => route('merchant.web.data.debts.customers.invoices', ['id' => '__ID__']),
            'debtStatementPdf' => route('merchant.web.data.debts.customers.statement.pdf', ['id' => '__ID__']),
            'products' => route('merchant.web.data.products'),
            'productsCreate' => route('merchant.web.data.products.create'),
            'suppliers' => route('merchant.web.data.suppliers.index'),
            'supplierCreate' => route('merchant.web.data.suppliers.store'),
            'supplierShow' => route('merchant.web.data.suppliers.show', ['id' => '__ID__']),
            'supplierPayment' => route('merchant.web.data.suppliers.payment', ['id' => '__ID__']),
            'purchaseOrders' => route('merchant.web.data.purchase-orders.index'),
            'purchaseOrderCreate' => route('merchant.web.data.purchase-orders.store'),
            'purchaseOrderShow' => route('merchant.web.data.purchase-orders.show', ['id' => '__ID__']),
            'purchaseOrderApprove' => route('merchant.web.data.purchase-orders.approve', ['id' => '__ID__']),
            'purchaseOrderReceive' => route('merchant.web.data.purchase-orders.receive', ['id' => '__ID__']),
            'purchaseOrderCancel' => route('merchant.web.data.purchase-orders.cancel', ['id' => '__ID__']),
            'purchaseReturns' => route('merchant.web.data.purchase-returns.index'),
            'purchaseReturnCreate' => route('merchant.web.data.purchase-returns.store'),
            'purchaseReturnShow' => route('merchant.web.data.purchase-returns.show', ['id' => '__ID__']),
            'purchaseReturnApprove' => route('merchant.web.data.purchase-returns.approve', ['id' => '__ID__']),
            'purchaseReturnReject' => route('merchant.web.data.purchase-returns.reject', ['id' => '__ID__']),
            'expenses' => route('merchant.web.data.expenses.index'),
            'expenseCreate' => route('merchant.web.data.expenses.store'),
            'expenseUpdate' => route('merchant.web.data.expenses.update', ['id' => '__ID__']),
            'expenseDelete' => route('merchant.web.data.expenses.destroy', ['id' => '__ID__']),
            'branches' => route('merchant.web.data.branches'),
            'branchesCreate' => route('merchant.web.data.branches.create'),
            'roles' => route('merchant.web.data.roles'),
            'rolesCreate' => route('merchant.web.data.roles.create'),
            'approvals' => route('merchant.web.data.approvals'),
            'approvalGrant' => route('merchant.web.data.approvals.grant', ['id' => '__ID__']),
            'approvalReject' => route('merchant.web.data.approvals.reject', ['id' => '__ID__']),
            'staff' => route('merchant.web.data.staff'),
            'staffCreate' => route('merchant.web.data.staff.create'),
            'devices' => route('merchant.web.data.devices'),
            'deviceActivation' => route('merchant.web.data.devices.activate'),
            'receipts' => route('merchant.web.data.receipts'),
            'receiptsSave' => route('merchant.web.data.receipts.save'),
            'login' => route('merchant.web.login')
        ];

        return view('merchant-web.dashboard', [
            'merchantRoutes' => $merchantRoutes,
            'storeName' => $merchant?->store_name
                ?: trim((string) $owner->f_name . ' ' . (string) $owner->l_name),
            'businessType' => A::BUSINESS_TYPE_LABELS[$profile->business_type] ?? 'نشاط تجاري',
            'businessTypeCode' => (string) $profile->business_type,
            'plan' => (A::PLAN_LABELS[$effectivePlan] ?? 'مجاني')
                . ($expired ? ' (انتهى الاشتراك المدفوع)' : ''),
            // القائمة لا تُستنتج من المتصفح: القطاع والاستحقاق يُحسمان
            // من المصدر نفسه الذي يحرس الأبواب عند الطلب.
            'portalNavigation' => $navigation->forOwner(
                $profile->business_type,
                $entitlements->manifestFor($owner),
            ),
        ]);
    }
}
