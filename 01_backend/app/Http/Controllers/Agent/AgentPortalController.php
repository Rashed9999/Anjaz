<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentBranchService;
use App\Services\AgentNetworkService;
use App\Services\AgentCounterService;
use App\Services\AgentReportService;
use App\Services\AgentStaffService;
use App\Services\AgentTillService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * AMIAL-AGENT-PORTAL-001 — بوّابة الوكيل.
 *
 * وكلاء أميال شركات صرافة، وموظّفوها يعملون على **الكمبيوتر لا الهاتف**.
 * فالتطبيق وحده لا يكفيهم.
 *
 * **وكلّ استعلامٍ هنا محصورٌ بفروع الداخل** — يُقرأ الحصر من الوسيط لا
 * يُبنى من معطىً في الطلب: معرّفُ فرعٍ يأتي من المتصفّح يمكن تغييره،
 * وشركةُ صرافةٍ ترى خزنة منافستها كارثةٌ تجارية قبل أن تكون خرقاً أمنياً.
 */
class AgentPortalController extends Controller
{
    public function __construct(
        private readonly AgentBranchService $branches,
        private readonly AgentTillService $till,
        private readonly AgentCounterService $counter,
        private readonly AgentReportService $reports,
    ) {
    }

    // ── الدخول ──────────────────────────────────────────────────────────

    public function loginPage()
    {
        if (Auth::guard('agent_staff')->check()) {
            return redirect()->route('agent.dashboard');
        }

        return view('agent-views.login');
    }

    /**
     * دخولٌ واحدٌ لبابين.
     *
     * موظّف الشركة يدخل برمزه (`MKL-014`)، وصاحب الشركة يدخل بهاتفه ما دام
     * لم يُنشئ موظّفيه بعد. وحقلٌ واحد للاثنين لأنّ حقلين يجعلان الصرّاف
     * يقف كلّ صباحٍ أمام سؤالٍ لا يعنيه.
     */
    public function login(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $id = trim((string) $request->input('username'));
        $password = (string) $request->input('password');

        // رمزُ الموظّف أوّلاً: هو الحالة الغالبة في شركةٍ لها آلاف الموظّفين.
        $staff = AgentStaff::whereRaw('UPPER(username) = ?', [mb_strtoupper($id)])->first();

        if ($staff) {
            if (!Hash::check($password, (string) $staff->password)) {
                return $this->loginFailed($request);
            }

            if (!$staff->is_active) {
                return back()->withErrors(['username' => 'الحساب معطَّل — راجع إدارة شركتك'])->withInput();
            }

            // شركةٌ أُوقفت لا يعمل موظّفوها — وإلّا بقي الشبّاك يخدم بعد
            // إيقاف الشركة نفسها.
            $company = User::find($staff->agent_user_id);
            if (!$company || (int) $company->is_active !== 1) {
                return back()->withErrors(['username' => 'حساب الشركة موقوف — راجع إدارة أميال'])->withInput();
            }

            Auth::guard('user')->logout();
            Auth::guard('agent_staff')->login($staff, (bool) $request->boolean('remember'));
            $request->session()->regenerate();

            $staff->forceFill(['last_login_at' => now()])->save();

            return redirect()->route('agent.dashboard');
        }

        // حساب الشركة نفسه بالهاتف.
        $phone = preg_replace('/\D+/', '', $id);
        $user = $phone !== '' ? User::where('phone', $phone)->first() : null;

        if (!$user || !Hash::check($password, (string) $user->password)) {
            return $this->loginFailed($request);
        }

        if ((int) $user->type !== AGENT_TYPE) {
            return back()->withErrors(['username' => 'هذه البوّابة للوكلاء وموظّفيهم'])->withInput();
        }

        if ((int) ($user->is_temp_blocked ?? 0) === 1) {
            return back()->withErrors(['username' => 'الحساب موقوف — راجع إدارة أميال'])->withInput();
        }

        // **لا تُفتح جلسةٌ على حارس `user` من هذه البوّابة أبداً.**
        //
        // كان صاحب الشركة يدخل هنا على حارس `user` — وهو **نفس حارس جلسة
        // لوحة الإدارة**. فمن يدخل بوّابة الوكيل ثمّ يفتح لوحة الإدارة في
        // المتصفّح نفسه يقع في حلقةٍ لا تنتهي:
        //
        //     /admin            → «لستَ مديراً» → /admin/auth/login
        //     /admin/auth/login → «أنت داخلٌ»   → /admin
        //
        // فتتعطّل لوحة الإدارة كلّها ولا يظهر سببٌ في أيّ سجلّ. وهذا ليس
        // عطلاً في لوحة الإدارة: هو تسرّبُ جلسةٍ من بوّابةٍ إلى أخرى.
        //
        // والعلاج أنّ للبوّابة حارسها الخاصّ، فيُفتح لصاحب الشركة حسابُ
        // «إدارةٍ عامّة» عليه — كما لأيّ موظّفٍ عنده. وكلمةُ سرّه تبقى كلمة
        // سرّ حساب الشركة، ولا تُخزَّن مرّتين.
        $hq = app(AgentStaffService::class)->ensurePortalAccount($user);

        Auth::guard('user')->logout();
        Auth::guard('agent_staff')->login($hq, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        $hq->forceFill(['last_login_at' => now()])->save();

        return redirect()->route('agent.dashboard');
    }

    /**
     * رسالةٌ واحدة لكلّ أسباب الفشل.
     *
     * «الرمز غير مسجَّل» تُخبر من يجرّب أنّ الرمز صحيح فيبقى له كلمة السرّ
     * وحدها — ورموز الموظّفين متسلسلة يسهل تخمينها.
     */
    private function loginFailed(Request $request)
    {
        return back()->withErrors(['username' => 'بيانات الدخول غير صحيحة'])->withInput();
    }


    public function logout(Request $request)
    {
        Auth::guard('agent_staff')->logout();
        Auth::guard('user')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('agent.login');
    }

    // ── الصفحات ─────────────────────────────────────────────────────────

    public function dashboard(Request $request)
    {
        $staff = $request->attributes->get('agent_staff');

        return view('agent-views.dashboard', [
            'role' => (string) $request->attributes->get('portal_role'),
            'staffName' => $staff?->name ?? trim(
                (Auth::guard('user')->user()->f_name ?? '') . ' ' .
                (Auth::guard('user')->user()->l_name ?? '')
            ),
            'staffUsername' => $staff?->username,
            'roleLabel' => $staff?->roleLabel() ?? 'حساب الشركة',
            'branchName' => $staff?->branch?->name,
        ]);
    }


    /**
     * حساب الشركة الذي تُنسَب إليه عمليات البوّابة.
     *
     * **العطل الذي أدخل هذه الدالّة.** كان الكود يستعمل `$request->user()`
     * في ستّة مواضع. وهي تقرأ الحارس الافتراضيّ (`user`) — وموظّف شركة
     * الصرافة يدخل بحارس `agent_staff`. فترجع `null`.
     *
     * والنتيجة أنّ **كلّ إدارة الفروع والنقد كانت ميّتةً لمن يدخل برمز
     * موظّف**: إنشاء فرع، شحن رصيده، توريد نقد، جرد الدرج — أربعتها تسقط
     * بـ500. وتعمل كلّها لمن يدخل بهاتف الشركة.
     *
     * ولم يكشفه شيء لأنّ اختبار السلسلة دخل بالهاتف: المسار الذي جرّبتُه
     * كان المسار السليم، والمسار الذي يسلكه المستعمل الحقيقيّ هو الآخر.
     *
     * والجذر يأتي من الوسيط لا من الحارس — فيصحّ في الحالتين.
     */
    private function companyUser(Request $request): ?User
    {
        return User::find((int) $request->attributes->get('agent_root_id'));
    }

    // ── البيانات ────────────────────────────────────────────────────────

    public function overview(Request $request): JsonResponse
    {
        $rootId = (int) $request->attributes->get('agent_root_id');
        $agent = User::find($rootId);

        $branches = $this->branches->listFor($agent);

        // الإجماليّان يُجمعان كلٌّ على حدة ولا يُخلطان: مجموعُهما رقمٌ بلا
        // معنى — نقدٌ ورقيّ ورصيدٌ إلكترونيّ ليسا من جنسٍ واحد وإن كانا
        // بالعملة نفسها.
        $totalCash = array_reduce($branches, fn ($c, $b) => bcadd($c, $b['cash_on_hand'], 4), '0');
        $totalEmoney = array_reduce($branches, fn ($c, $b) => bcadd($c, $b['emoney_balance'], 4), '0');

        return $this->ok([
            'agent' => [
                'id' => (int) $agent->id,
                'name' => trim((string) ($agent->f_name . ' ' . $agent->l_name)),
                'phone' => (string) $agent->phone,
                'is_branch_account' => (bool) $request->attributes->get('is_branch_account'),
            ],
            'own_balance' => (string) (EMoney::where('user_id', $agent->id)
                ->value('current_balance') ?? '0'),
            'branches' => $branches,
            'totals' => [
                'cash_on_hand' => $totalCash,
                'emoney' => $totalEmoney,
                'branches' => count($branches),
                'branches_active' => count(array_filter($branches, fn ($b) => $b['is_active'] ?? true)),
                'low_cash_branches' => count(array_filter($branches, fn ($b) => $b['cash_is_low'])),
            ],
            // ما تسأل عنه الإدارة العامّة كلّ صباح — لا الأرصدة وحدها.
            'today' => $this->todaySnapshot($rootId, array_column($branches, 'id')),
        ]);
    }

    /**
     * لقطةُ اليوم لشركة الصرافة.
     *
     * الأرصدة تقول «كم عندنا»، وهذه تقول «ماذا حدث». ومديرُ شركةٍ يرى
     * الأرصدة وحدها لا يعرف أنّ فرعاً لم يفتح شبّاكاً اليوم، ولا أنّ ورديّةً
     * أُغلقت بعجز.
     *
     * @param  array<int>  $branchIds
     */
    private function todaySnapshot(int $rootId, array $branchIds): array
    {
        $today = now()->toDateString();
        $from = $today . ' 00:00:00';
        $to = $today . ' 23:59:59';

        $staff = \App\Models\Agent\AgentStaff::where('agent_user_id', $rootId);

        $shiftsToday = \App\Models\Agent\AgentShift::whereIn('branch_id', $branchIds)
            ->whereBetween('opened_at', [$from, $to])->get();

        $openNow = \App\Models\Agent\AgentShift::whereIn('branch_id', $branchIds)
            ->where('status', \App\Models\Agent\AgentShift::STATUS_OPEN)->get();

        $moves = \App\Models\Agent\AgentCashMovement::whereIn('branch_id', $branchIds)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])->get();

        $sum = fn ($c) => (string) $c->reduce(fn ($a, $m) => bcadd($a, (string) $m->amount, 4), '0');

        // العجز والفائض يُعدّان معاً ولا يُقاصّان: فرعٌ نقص خمسةً وآخرُ زاد
        // خمسةً ليسا «صفراً» — هما حادثتان تستحقّان سؤالين.
        $variances = $shiftsToday->whereNotNull('variance')
            ->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) !== 0);

        return [
            'date' => $today,
            'staff_total' => (clone $staff)->count(),
            'staff_active' => (clone $staff)->where('is_active', true)->count(),
            'tellers' => (clone $staff)->where('role', \App\Models\Agent\AgentStaff::ROLE_TELLER)->count(),

            'shifts_opened' => $shiftsToday->count(),
            'shifts_open_now' => $openNow->count(),
            'drawers_cash' => (string) $openNow->reduce(
                fn ($a, $s) => bcadd($a, (string) $s->cash_on_hand, 4), '0'),

            'deposits_count' => $moves->where('reason', 'customer_deposit')->count(),
            'deposits_total' => $sum($moves->where('reason', 'customer_deposit')),
            'withdrawals_count' => $moves->where('reason', 'customer_withdraw')->count(),
            'withdrawals_total' => $sum($moves->where('reason', 'customer_withdraw')),

            'shifts_with_variance' => $variances->count(),
            'variance_total' => (string) $variances->reduce(
                fn ($a, $s) => bcadd($a, (string) $s->variance, 4), '0'),

            // فرعٌ لم يفتح شبّاكاً اليوم لا يخدم أحداً مهما كان رصيده.
            'branches_idle' => count(array_diff($branchIds, $shiftsToday->pluck('branch_id')->all())),
        ];
    }

    public function branchTill(Request $request, int $id): JsonResponse
    {
        $branch = $this->authorizedBranch($request, $id);

        return $this->ok([
            'summary' => $this->till->summary($branch),
            'movements' => $this->till->movements($branch),
        ]);
    }

    // ── الشبّاك ─────────────────────────────────────────────────────────

    /** بحثٌ عن العميل قبل العملية — بالهاتف وحده لا بالاسم. */
    public function findCustomer(Request $request): JsonResponse
    {
        $phone = preg_replace('/\D+/', '', (string) $request->query('phone', ''));

        if (mb_strlen($phone) < 9) {
            return $this->error('أدخل رقم هاتف كاملاً', 422);
        }

        $customer = User::where('phone', $phone)->first();
        if (!$customer) {
            return $this->error('لا عميل بهذا الرقم', 404);
        }

        $status = app(\App\Services\CustomerStatusResolver::class)->resolve($customer);

        // **لا يُعرَض رصيد العميل لموظّف الفرع.**
        //
        // موظّف الشبّاك يحتاج أن يعرف من يقف أمامه وهل يُخدَم، لا كم يملك.
        // وعرضُ الرصيد يجعل كلّ موظّفٍ في كلّ فرعٍ يرى ثروة كلّ من يمرّ به —
        // وهي بياناتٌ لا يحتاجها لأداء العملية، والسحب يُرفض من نفسه إن لم
        // يكفِ الرصيد.
        return $this->ok([
            'customer' => [
                'id' => (int) $customer->id,
                'name' => trim((string) ($customer->f_name . ' ' . $customer->l_name)) ?: '—',
                'phone' => (string) $customer->phone,
                'status' => $status['status'],
                'status_label' => $status['label'],
                'severity' => $status['severity'],
                'can_transact' => !in_array($status['status'], [
                    \App\Services\CustomerStatusResolver::BLACKLISTED,
                    \App\Services\CustomerStatusResolver::CLOSED,
                    \App\Services\CustomerStatusResolver::DECEASED,
                    \App\Services\CustomerStatusResolver::FROZEN,
                ], true),
            ],
        ]);
    }

    public function deposit(Request $request, int $id): JsonResponse
    {
        return $this->counterOp($request, $id, 'deposit');
    }

    public function withdraw(Request $request, int $id): JsonResponse
    {
        return $this->counterOp($request, $id, 'withdraw');
    }

    private function counterOp(Request $request, int $id, string $op): JsonResponse
    {
        $request->validate([
            'customer_phone' => 'required|string',
            'amount' => 'required|numeric|min:1',
            'note' => 'sometimes|nullable|string|max:500',
        ]);

        $branch = $this->authorizedBranch($request, $id);

        $phone = preg_replace('/\D+/', '', (string) $request->input('customer_phone'));
        $customer = User::where('phone', $phone)->first();

        if (!$customer) {
            return $this->error('لا عميل بهذا الرقم', 404);
        }

        try {
            $out = $op === 'deposit'
                ? $this->counter->deposit($branch, $customer, (string) $request->input('amount'),
                    $this->companyUser($request), $request->input('note'))
                : $this->counter->withdraw($branch, $customer, (string) $request->input('amount'),
                    $this->companyUser($request), $request->input('note'));
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok($out, $op === 'deposit'
            ? "تمّ الإيداع — المرجع {$out['reference']}"
            : "تمّ السحب — سلّم العميل {$out['amount']} نقداً. المرجع {$out['reference']}");
    }

    // ── العمولات والتسويات والتقارير (الفصل ٠٣) ─────────────────────────

    public function commissions(Request $request, int $id): JsonResponse
    {
        $branch = $this->authorizedBranch($request, $id);

        return $this->ok($this->reports->commissions(
            $branch, $request->query('from'), $request->query('to'),
        ));
    }

    public function settlements(Request $request): JsonResponse
    {
        // التسويات على مستوى الشركة الأمّ لا الفرع: الأرباح تُسوّى مع من
        // تعاقد مع أميال، لا مع كلّ شبّاك.
        $agent = User::find((int) $request->attributes->get('agent_root_id'));

        return $this->ok($this->reports->settlements($agent));
    }

    /**
     * طلب صرف رصيدٍ إلكترونيّ نقداً — الاتّجاه المعاكس للشحن.
     *
     * **كان غائباً بالكامل.** وكيلٌ يخدم سحوبات العملاء طول اليوم يدفع نقداً
     * ورقيّاً ويستقبل رصيداً إلكترونيّاً: فيمتلئ رصيدُه ويفرغ درجُه. وبلا هذا
     * الطلب يقف عاجزاً — رصيدٌ لا يستطيع صرفه، ونقدٌ لا يكفي لخدمة أحد.
     * فيتوقّف عن السحب، ثمّ يتوقّف عن العمل معنا.
     *
     * ولوحة التسويات كانت تعرض ثلاثة أنواع (`topup`/`payout`/`reconciliation`)
     * ولا مُنشئَ إلّا للأوّل.
     */
    public function requestPayout(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        // الإدارة العامّة وحدها تطلب الصرف: مالُ الشركة لا يصرفه صرّاف.
        if ((string) $request->attributes->get('portal_role') !== AgentStaff::ROLE_HEAD_OFFICE) {
            return $this->error('طلب الصرف من صلاحية الإدارة العامّة للشركة', 403);
        }

        $agent = $this->companyUser($request);

        if (!$agent) {
            return $this->error('حساب الشركة غير موجود', 404);
        }

        try {
            $settlement = app(AgentNetworkService::class)->requestPayout(
                $agent,
                (string) $request->input('amount'),
                'cash',
                $request->input('note'),
            );
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(
            ['settlement_ulid' => $settlement->settlement_ulid],
            'أُرسل طلب الصرف — حُجز المبلغ من رصيدك حتى تبتّ فيه الإدارة',
        );
    }

    public function dailyReport(Request $request, int $id): JsonResponse
    {
        $branch = $this->authorizedBranch($request, $id);

        return $this->ok($this->reports->dailyReport($branch, $request->query('date')));
    }

    public function setWorkingHours(Request $request, int $id): JsonResponse
    {
        $branch = $this->authorizedBranch($request, $id);

        try {
            $branch = $this->reports->setWorkingHours($branch, (array) $request->input('hours', []));
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(['working_hours' => $branch->working_hours], 'حُفظت ساعات العمل');
    }

    // ── إدارة الفروع ────────────────────────────────────────────────────

    public function createBranch(Request $request): JsonResponse
    {
        // الفرع لا يُنشئ فرعاً: التسلسل مستوىً واحد، وفروعُ الفروع تُضيّع
        // مسؤولية النقد.
        if ($request->attributes->get('is_branch_account')) {
            return $this->error('إنشاء الفروع من حساب الشركة الأمّ وحده', 403);
        }

        $request->validate([
            'name' => 'required|string|max:120',
            'code' => 'required|string|max:24',
            'phone' => 'required|string',
            'password' => 'required|string|min:8',
            'city' => 'sometimes|nullable|string|max:80',
            'address' => 'sometimes|nullable|string|max:500',
        ]);

        try {
            $branch = $this->branches->create($this->companyUser($request), $request->all());
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(['branch' => $branch], "أُنشئ الفرع {$branch->name}");
    }

    /** شحن رصيد الفرع الإلكترونيّ من الشركة الأمّ — غير توريد النقد. */
    public function fundBranch(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'note' => 'required|string|min:5|max:500',
        ]);

        $branch = $this->authorizedBranch($request, $id);

        try {
            $out = $this->branches->fundBranch(
                $branch, $this->companyUser($request),
                (string) $request->input('amount'), (string) $request->input('note'),
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok($out, 'شُحن الفرع — رصيده الآن ' . $out['branch_balance']);
    }

    public function moveCash(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'direction' => 'required|in:in,out',
            'amount' => 'required|numeric|min:1',
            'note' => 'required|string|min:5|max:500',
        ]);

        $branch = $this->authorizedBranch($request, $id);

        try {
            $out = $this->branches->moveTreasuryCash(
                $branch, $this->companyUser($request), $request->input('direction'),
                (string) $request->input('amount'), (string) $request->input('note'),
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok($out, 'سُجّل التوريد — النقد الآن ' . $out['balance_after']);
    }

    public function countTill(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'counted_amount' => 'required|numeric|min:0',
            'note' => 'required|string|min:10|max:500',
        ]);

        $branch = $this->authorizedBranch($request, $id);

        try {
            $out = $this->till->count(
                $branch, $this->companyUser($request),
                (string) $request->input('counted_amount'), (string) $request->input('note'),
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok($out, $out['balanced']
            ? 'الجرد مطابق'
            : 'سُجّل فرق الجرد: ' . $out['difference']);
    }

    // ── الحصر ───────────────────────────────────────────────────────────

    /**
     * الفرع يُقرأ من قائمة الوسيط لا من الطلب.
     *
     * معرّفٌ يأتي من المتصفّح يمكن تغييره، ومن يغيّره يصل إلى خزنة فرعٍ لا
     * يملكه. فالمقارنة على ما حسبه الوسيط من الجلسة.
     */
    private function authorizedBranch(Request $request, int $id): AgentBranch
    {
        $allowed = (array) $request->attributes->get('agent_branch_ids', []);

        abort_unless(in_array($id, array_map('intval', $allowed), true), 403,
            'هذا الفرع ليس ضمن فروعك');

        return AgentBranch::findOrFail($id);
    }

    private function ok(array $meta, string $message = 'OK'): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => 'OK', 'message' => $message,
            'errors' => (object) [], 'meta' => $meta,
        ]);
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'ERROR', 'message' => $message,
            'errors' => (object) [], 'meta' => (object) [],
        ], $status);
    }
}
