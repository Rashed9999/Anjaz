<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillProvider;
use App\Models\BillPaymentOrder;
use App\Models\FamilyFund;
use App\Models\PaymentRequest;
use App\Models\PosUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AMIAL-SURFACE-002 — إظهار الأنظمة اليتيمة (كانت بلا لوحة إدارية):
 * مزوّدو الفواتير، صناديق العائلة، طلبات الأموال، صلاحيات RBAC.
 * عرض من الخادم مباشرةً (بلا JS) — أبسط وأمتن.
 */
class AdminSurfaceController extends Controller
{
    public function billProviders(): View
    {
        $providers = BillProvider::withCount('orders')->orderBy('name')->get();
        $ordersToday = BillPaymentOrder::whereDate('created_at', today())->count();
        return view('admin-views.amial.surface.bill-providers', compact('providers', 'ordersToday'));
    }

    public function toggleBillProvider(int $id): RedirectResponse
    {
        $p = BillProvider::findOrFail($id);
        $p->update(['is_active' => ! $p->is_active]);
        return back()->with('success', 'تم تحديث حالة المزوّد');
    }

    public function funds(): View
    {
        $funds = FamilyFund::withCount('members')
            ->orderByDesc('id')->paginate(25);
        return view('admin-views.amial.surface.funds', compact('funds'));
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
