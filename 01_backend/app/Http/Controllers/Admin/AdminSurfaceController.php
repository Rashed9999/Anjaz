<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillProvider;
use App\Models\BillService;
use App\Models\BillPaymentOrder;
use App\Models\FamilyFund;
use App\Models\PaymentRequest;
use App\Models\PosUser;
use App\Services\BillProviderAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * AMIAL-SURFACE-002 — إظهار الأنظمة اليتيمة (كانت بلا لوحة إدارية):
 * مزوّدو الفواتير، صناديق العائلة، طلبات الأموال، صلاحيات RBAC.
 * عرض من الخادم مباشرةً (بلا JS) — أبسط وأمتن.
 */
class AdminSurfaceController extends Controller
{
    public function __construct(private readonly BillProviderAdminService $billProviderAdmin) {}

    public function billProviders(): View
    {
        $providers = BillProvider::with(['services' => fn ($q) => $q->orderBy('name')])
            ->withCount([
                'orders',
                'orders as pending_orders_count' => fn ($q) => $q->whereIn('status', ['pending', 'processing', 'pending_provider_confirmation']),
                'orders as successful_orders_count' => fn ($q) => $q->where('status', 'success'),
                'orders as failed_orders_count' => fn ($q) => $q->whereIn('status', ['failed', 'reversed']),
                'orders as today_orders_count' => fn ($q) => $q->whereDate('created_at', today()),
            ])->orderBy('name')->get();
        $ordersToday = BillPaymentOrder::whereDate('created_at', today())->count();
        $recentOrders = BillPaymentOrder::with([
                'provider:id,name,display_name_ar,integration_type',
                'service:id,name,display_name_ar',
                'user:id,f_name,l_name,phone',
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return view('admin-views.amial.surface.bill-providers', [
            'providers' => $providers,
            'ordersToday' => $ordersToday,
            'recentOrders' => $recentOrders,
            'canConfigure' => (bool) auth('user')->user()?->hasPlatformPermission('platform.settings.update'),
        ]);
    }

    public function configureBillProvider(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate([
            'endpoint_url' => ['nullable', 'url', 'max:255'],
            'username' => ['nullable', 'string', 'max:120'],
            'account_number' => ['nullable', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'max:255'],
            'api_token' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'timeout_seconds' => ['nullable', 'integer', 'min:3', 'max:60'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        try {
            $this->billProviderAdmin->configure(BillProvider::findOrFail($id), $input, auth('user')->user(), $input['reason'], $request->ip());
            return back()->with('success', 'تم حفظ إعدادات المزوّد. أجرِ اختبار الرصيد قبل التفعيل.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function refreshBillProvider(Request $request, int $id): RedirectResponse
    {
        try {
            $this->billProviderAdmin->refresh(BillProvider::findOrFail($id), auth('user')->user(), $request->ip());
            return back()->with('success', 'اكتمل اختبار المزود وتحديث الرصيد.');
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function toggleBillProvider(Request $request, int $id): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        try {
            $this->billProviderAdmin->toggle(BillProvider::findOrFail($id), auth('user')->user(), $input['reason']);
            return back()->with('success', 'تم تحديث حالة المزوّد.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function saveBillServiceRouting(Request $request, int $id, ?int $serviceId = null): RedirectResponse
    {
        $input = $request->validate([
            'code' => ['required', 'string', 'max:80'],
            'name' => ['required', 'string', 'max:120'],
            'display_name_ar' => ['required', 'string', 'max:160'],
            'service_type' => ['required', 'string', 'max:50'],
            'network_number' => ['required', 'integer', 'min:0'],
            'inquiry_service_number' => ['nullable', 'integer', 'min:0'],
            'payment_service_number' => ['required', 'integer', 'min:0'],
            'payment_mode' => ['required', 'in:amount,offer'],
            'offer_code' => ['nullable', 'string', 'max:120'],
            'regex' => ['nullable', 'string', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);
        try {
            $provider = BillProvider::findOrFail($id);
            $service = $serviceId ? BillService::where('provider_id', $provider->id)->findOrFail($serviceId) : null;
            $this->billProviderAdmin->saveServiceRouting($provider, $service, $input, auth('user')->user(), $input['reason']);
            return back()->with('success', 'تم حفظ توجيه الخدمة.');
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function funds(): View
    {
        $funds = FamilyFund::withCount('members')
            ->orderByDesc('id')->paginate(25);
        return view('admin-views.amial.surface.funds', compact('funds'));
    }

    public function fundDetail(int $id): View
    {
        $fund = FamilyFund::withCount('members')->findOrFail($id);

        $members = DB::table('family_fund_members as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.fund_id', $fund->id)
            ->orderByDesc('m.total_contributed')
            ->get([
                'm.role', 'm.status', 'm.total_contributed', 'm.total_disbursed',
                'm.joined_at', 'u.id as user_id', 'u.f_name', 'u.l_name', 'u.phone',
            ]);

        $txs = DB::table('family_fund_transactions as t')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            ->leftJoin('users as b', 'b.id', '=', 't.beneficiary_user_id')
            ->leftJoin('users as a', 'a.id', '=', 't.approved_by_user_id')
            ->where('t.fund_id', $fund->id)
            ->orderByDesc('t.id')
            ->paginate(50, [
                't.tx_ulid', 't.tx_type', 't.amount', 't.balance_before', 't.balance_after',
                't.note', 't.status', 't.created_at', 't.wallet_transaction_id',
                'u.f_name as actor_f', 'u.l_name as actor_l', 'u.id as actor_id',
                'b.f_name as ben_f', 'b.l_name as ben_l', 'b.id as ben_id',
                'a.f_name as app_f', 'a.id as app_id',
            ]);

        $sums = DB::table('family_fund_transactions')
            ->where('fund_id', $fund->id)
            ->where('status', 'completed')
            ->selectRaw("
                SUM(CASE WHEN tx_type = 'contribute' THEN amount ELSE 0 END) as inflow,
                SUM(CASE WHEN tx_type IN ('disburse_to_member','disburse_to_external')
                    THEN amount ELSE 0 END) as outflow,
                SUM(balance_after - balance_before) as net
            ")->first();

        $inflow = (string) ($sums->inflow ?? '0');
        $outflow = (string) ($sums->outflow ?? '0');
        $derived = (string) ($sums->net ?? '0');
        $stored = (string) ($fund->balance ?? '0');

        return view('admin-views.amial.surface.fund-detail', [
            'fund' => $fund,
            'members' => $members,
            'txs' => $txs,
            'inflow' => $inflow,
            'outflow' => $outflow,
            'derived' => $derived,
            'stored' => $stored,
            'gap' => bccomp($derived, $stored, 4) === 0 ? null : bcsub($stored, $derived, 4),
        ]);
    }

    public function paymentRequests(Request $request): View
    {
        $q = PaymentRequest::with('requester:id,f_name,l_name,phone')->orderByDesc('id');
        if ($request->filled('status')) $q->where('status', $request->query('status'));
        $requests = $q->paginate(25)->withQueryString();
        return view('admin-views.amial.surface.payment-requests', compact('requests'));
    }

    public function rbac(): View
    {
        $managers = PosUser::with('merchant:id,f_name,l_name')
            ->where(function ($q) {
                $q->whereJsonContains('permissions', 'operations_manager')
                  ->orWhereJsonContains('permissions', 'financial_manager');
            })->orderByDesc('id')->get();
        $staffCount = PosUser::count();
        return view('admin-views.amial.surface.rbac', compact('managers', 'staffCount'));
    }
}
