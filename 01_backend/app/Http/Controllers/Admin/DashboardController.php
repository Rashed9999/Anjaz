<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\EMoney;
use App\Models\Transaction;
use App\Models\User;
use App\Traits\UploadSizeHelperTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    use UploadSizeHelperTrait;

    public function __construct(
        private User        $user,
        private EMoney      $eMoney,
        private Transaction $transaction
    ) {}

    public function dashboard(Request $request): View
    {
        $data = [];

        $topAgents = $this->transaction->with('user')
            ->agent()
            ->select(['user_id', DB::raw("(SUM(debit) + SUM(credit)) as total_transaction")])
            ->orderBy("total_transaction", 'desc')
            ->groupBy('user_id')
            ->take(6)
            ->get();

        $topCustomers = $this->transaction->with('user')
            ->customer()
            ->select(['user_id', DB::raw("(SUM(debit) + SUM(credit)) as total_transaction")])
            ->orderBy("total_transaction", 'desc')
            ->groupBy('user_id')
            ->take(4)
            ->get();

        $topTransactions = $this->transaction->with('user')
            ->notAdmin()
            ->where('ref_trans_id', null)
            ->select(['user_id', DB::raw("(SUM(debit) + SUM(credit)) as total_transaction")])
            ->orderBy("total_transaction", 'desc')
            ->groupBy('user_id')
            ->take(20)
            ->get();

        $data['top_agents'] = $topAgents;
        $data['top_customers'] = $topCustomers;
        $data['top_transactions'] = $topTransactions;

        // AMIAL-DASH-TRUTH-001 — الأرقام تُحسب في خدمةٍ واحدةٍ صحيحة.
        //
        // ما كان هنا يعرض على المدير ثلاثة أرقامٍ كاذبة:
        //   • «حجم السنة» = أعلى مستخدمٍ في كلّ شهر (groupBy ثمّ first)
        //   • وحدودُ الشهر `Y-m-30` — فبراير تاريخٌ لا يوجد، و٧ أشهرٍ
        //     يسقط يومُها الأخير صامتاً
        //   • و«أرباح الرسوم» ربحُ من يفتح اللوحة (Auth::id) لا المنصّة
        //
        // والتفصيل والقياس في `AdminDashboardService`.
        $dash = app(\App\Services\AdminDashboardService::class);

        $balance = $dash->balances();
        $transaction = $dash->monthlyVolume();

        // AMIAL-ADMIN-DASH-002: عدّادات حقيقية للوحة (عملاء/وكلاء/تجار + اليوم)
        $data['counts'] = [
            'customers' => $this->user->where('type', 2)->count(),
            'agents' => $this->user->where('type', 1)->count(),
            'merchants' => $this->user->where('type', 4)->count(),
        ];
        $data['today'] = $dash->today();

        return view('admin-views.dashboard', compact('balance', 'transaction', 'data'));
    }

    public function getBalanceStat(): array
    {
        $usedBalance = $this->eMoney->with('user')
            ->where('user_id', '!=', Helpers::get_admin_id())
            ->sum('current_balance');

        $unusedBalance = $this->eMoney->with('user')
            ->where('user_id', Helpers::get_admin_id())
            ->sum('current_balance');

        $totalBalance = $this->transaction->where('user_id', Helpers::get_admin_id())->where('transaction_type', CASH_IN)->sum('credit');
        // AMIAL-DASH-TRUTH-001: كان `Auth::id()` — ربحُ من يفتح الصفحة لا
        // المنصّة، وبقيّةُ الدالّة تستعمل `get_admin_id()`. هويّتان في دالّة.
        // ويُحسب الآن من مصدره (`transactions.charge`) لا من عمودٍ مخزَّن.
        $chargeEarned = app(\App\Services\AdminDashboardService::class)->feesEarned();
        $pendingBalance = $this->eMoney->with('user')->sum('pending_balance');

        $balance = [];
        $balance['total_balance'] = $totalBalance;
        $balance['used_balance'] = $usedBalance + $pendingBalance;
        $balance['unused_balance'] = $unusedBalance;
        $balance['total_earned'] = $chargeEarned;

        return $balance;
    }

    public function settings(): View
    {
        return view('admin-views.settings');
    }

    public function settingsUpdate(Request $request): RedirectResponse
    {
        $check = $this->validateUploadedFile($request, ['image']);
        if ($check !== true) {
            return $check;
        }

        $request->validate(
            [
                'first_name' => 'required',
                'last_name' => 'required',
                'phone' => 'required',
                'image' => 'nullable|image|max:'. $this->maxImageSizeKB .'|mimes:' . implode(',', array_column(IMAGE_EXTENSIONS, 'key')),
            ]
        );

        $admin = $this->user->find(auth('user')->id());
        $admin->f_name = $request->first_name;
        $admin->l_name = $request->last_name;
        $admin->email = $request->email;
        $admin->phone = $request->phone;
        $admin->image = $request->has('image') ? Helpers::update('admin/', $admin->image, APPLICATION_IMAGE_FORMAT, $request->file('image')) : $admin->image;
        $admin->save();
        Toastr::success(translate('Admin updated successfully!'));
        return back();
    }

    public function settingsPasswordUpdate(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => 'required|same:confirm_password|min:8',
            'confirm_password' => 'required',
        ]);
        $admin = $this->user->find(auth('user')->id());
        $admin->password = bcrypt($request['password']);
        $admin->save();
        Toastr::success('Admin password updated successfully!');
        return back();
    }
}
