<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\UploadSizeHelperTrait;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use UploadSizeHelperTrait;

    public function __construct(private User $user) {}

    public function dashboard(Request $request): View
    {
        $data = [];

        $viewer = auth('user')->user();
        $can = static fn (string $permission): bool => (bool) $viewer?->hasPlatformPermission($permission);
        $canTransactions = $can('platform.transactions.view');
        $canCustomers = $can('platform.customers.view');

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

        // ══════════════════════════════════════════════════════════════
        // AMIAL-ADMIN-DOORS-001 — **مجاميعُ المنصّة خلف صلاحيّتها.**
        //
        // **الثمن:** أنشأ صاحبُ المشروع حسابَ دعمٍ ودخل به فرأى حجمَ
        // معاملات السنة كاملاً، ورسماً بيانيّاً شهريّاً، **وأعلى العملاء
        // والوكلاء تعاملاً بالأسماء والمبالغ**.
        //
        // واللوحةُ كانت تعمل كما صُمّمت: تحجب الأرصدةَ بـ`money.move`،
        // وتبني الباقيَ على `transactions.view` — **ودورُ الدعم يملكها**.
        //
        // والخلطُ في الحبّة نفسِها: `transactions.view` مبنيّةٌ لـ«أرِني
        // حركةَ هذا العميل» وهي عملُ الدعم اليوميّ؛ أمّا حجمُ المنصّة
        // وترتيبُ عملائها فسؤالُ إدارة. ومن يعرف من أكبرُ عملائنا وكم
        // يُحرّكون يملك ما يُباع — **ولا يحتاج الأرصدةَ ليضرّ**.
        // ══════════════════════════════════════════════════════════════
        $canAnalytics = $can('platform.analytics.view');

        $balance = $can('platform.money.move') ? $dash->balances() : [];
        $transaction = $canAnalytics ? $dash->monthlyVolume() : [];
        $leaders = ($canAnalytics && $canCustomers) ? $dash->leaderboards() : [];
        $data['top_agents'] = $leaders['agents'] ?? [];
        $data['top_customers'] = $leaders['customers'] ?? [];

        // AMIAL-ADMIN-DASH-002: عدّادات حقيقية للوحة (عملاء/وكلاء/تجار + اليوم)
        //
        // والعدّادُ مجموعٌ أيضاً — «كم عميلاً في المنصّة» ليس «أرِني هذا
        // العميل». فيتبع `analytics.view` لا `customers.view`.
        $data['counts'] = $canAnalytics ? $dash->populationCounts() : [];
        $data['today'] = $canAnalytics ? $dash->today() : null;
        $data['attention'] = $dash->attentionQueue([
            'kyc' => $can('platform.approvals.decide'),
            'tickets' => $can('platform.tickets.manage'),
            'approvals' => $can('platform.approvals.decide'),
            'audit' => $can('platform.audit.view'),
        ]);

        return view('admin-views.dashboard', compact('balance', 'transaction', 'data'));
    }

    public function getBalanceStat(): array
    {
        // لا حسابٍ ثانٍ قد ينحرف عن لوحة التحكم؛ مصدر واحد للأرصدة.
        return app(\App\Services\AdminDashboardService::class)->balances();
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
