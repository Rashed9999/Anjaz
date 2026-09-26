<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\MerchantProfile;
use App\Support\Access\AccessConstants as A;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/** صفحة الويب تعرض مصادر الخادم القائمة، ولا تُنشئ محرك مالٍ أو POS موازياً. */
class WebPortalController extends Controller
{
    public function index(Request $request): View
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
            'sectorProducts' => route('merchant.web.data.sector.products'),
            'sectorProductsCreate' => route('merchant.web.data.sector.products.create'),
            'sectorOperations' => route('merchant.web.data.sector.operations'),
            'plans' => route('merchant.web.data.plans'),
            'stats' => route('merchant.web.data.stats'),
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
            'branches' => route('merchant.web.data.branches'),
            'branchesCreate' => route('merchant.web.data.branches.create'),
            'roles' => route('merchant.web.data.roles'),
            'rolesCreate' => route('merchant.web.data.roles.create'),
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
        ]);
    }
}
