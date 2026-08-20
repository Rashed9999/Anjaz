<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentStaff;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentBranchService;
use App\Services\AgentNetworkService;
use App\Services\AgentDailySettlementService;
use App\Services\AgentCounterService;
use App\Services\AgentReportService;
use App\Services\AgentReportsService;
use App\Services\AgentStaffService;
use App\Services\AgentTillService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

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
        private readonly \App\Services\AuditService $audit,
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
        $throttleKey = 'agent-login:' . sha1(mb_strtolower($id) . '|' . (string) $request->ip());

        // رموز الموظفين قصيرة ومتسلسلة غالباً؛ كلمة مرور صحيحة لا يجب أن
        // تمنح المهاجم آلاف محاولات التخمين. المفتاح لا يسرّب وجود الرمز.
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return back()->withErrors(['username' => 'محاولات كثيرة. أعد المحاولة بعد ' . $seconds . ' ثانية'])->withInput();
        }

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
            RateLimiter::clear($throttleKey);

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
        RateLimiter::clear($throttleKey);

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
        $id = trim((string) $request->input('username'));
        $throttleKey = 'agent-login:' . sha1(mb_strtolower($id) . '|' . (string) $request->ip());
        RateLimiter::hit($throttleKey, 60);

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
            // لا تُقاصّ الفروق: العجز والفائض حادثتان مستقلتان حتى إن تساويا.
            'shortage_count' => $variances->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) < 0)->count(),
            'shortage_total' => (string) $variances
                ->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) < 0)
                ->reduce(fn ($a, $s) => bcadd($a, bcmul((string) $s->variance, '-1', 4), 4), '0'),
            'overage_count' => $variances->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) > 0)->count(),
            'overage_total' => (string) $variances
                ->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) > 0)
                ->reduce(fn ($a, $s) => bcadd($a, (string) $s->variance, 4), '0'),

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
        // ── بطاقةُ العميل السريعة (AMIAL-TELLER-WS-001) ─────────────────
        //
        // **وما يُضاف هنا محسوبٌ بميزان: ما يُعين على القرار وحده.**
        //
        // فالصرّاف يقرّر ثلاثة أشياء: أيَخدمه؟ وهل هو من يدّعي؟ وهل في
        // العمليّة ما يستدعي سؤالاً؟ فيُعطى ما يجيب هذه الثلاثة: الحالة،
        // ودرجةُ التوثيق، وقِدَمُ الحساب، وآخرُ عمليّة، والبلاغات.
        //
        // **ولا يُعطى الرصيد** — وهذا قرارٌ سابقٌ يُبقى عليه: عرضُه يجعل
        // كلّ موظّفٍ في كلّ فرعٍ يرى ثروة كلّ من يمرّ به، وهي بياناتٌ لا
        // يحتاجها لأداء العملية. والسحب يُرفض من نفسه إن لم يكفِ الرصيد.
        $lastOp = \Illuminate\Support\Facades\DB::table('agent_cash_movements')
            ->where('customer_user_id', $customer->id)
            ->orderByDesc('id')->first(['reason', 'amount', 'created_at']);

        $reports = 0;

        if (\Illuminate\Support\Facades\Schema::hasTable('aml_alerts')) {
            // **الأعمدة تُقرأ من المخطّط.** كتبتُ `user_id` أوّلاً وهو
            // عمودٌ لا وجود له — والجدول يربط بـ`subject_type`/`subject_id`.
            // وخطأٌ كهذا يردّ ٥٠٠ على بحثٍ عن عميل، فيقف الشبّاك كلّه.
            $reports = (int) \Illuminate\Support\Facades\DB::table('aml_alerts')
                ->where('subject_type', 'user')
                ->where('subject_id', (string) $customer->id)
                ->whereIn('status', ['open', 'pending', 'investigating'])->count();
        }

        return $this->ok([
            'customer' => [
                'id' => (int) $customer->id,
                'name' => trim((string) ($customer->f_name . ' ' . $customer->l_name)) ?: '—',
                'phone' => (string) $customer->phone,
                'account_number' => $customer->account_number,
                'status' => $status['status'],
                'status_label' => $status['label'],
                'severity' => $status['severity'],
                'can_transact' => !in_array($status['status'], [
                    \App\Services\CustomerStatusResolver::BLACKLISTED,
                    \App\Services\CustomerStatusResolver::CLOSED,
                    \App\Services\CustomerStatusResolver::DECEASED,
                    \App\Services\CustomerStatusResolver::FROZEN,
                ], true),

                'kyc_verified' => (bool) $customer->is_kyc_verified,
                // **صورةُ العميل تُعرَض إن وُجدت.** والتحقّق من الوجه أوّل
                // ما يُسأل عنه حين يُنكِر صاحبُ الحساب عمليّةً وقعت باسمه.
                'photo' => $customer->image
                    ? asset('storage/profile/' . $customer->image) : null,
                // حسابٌ عمرُه ساعات ويسحب مليوناً ليس كحسابٍ عمرُه سنتان.
                'member_since' => $customer->created_at?->toDateString(),
                'account_age_days' => $customer->created_at
                    ? (int) $customer->created_at->diffInDays(now()) : null,
                'last_operation' => $lastOp ? [
                    'kind' => $lastOp->reason === 'customer_deposit' ? 'إيداع' : 'سحب',
                    'amount' => (string) $lastOp->amount,
                    'at' => (string) $lastOp->created_at,
                ] : null,
                'open_reports' => $reports,
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

    // ══════════════════════════════════════════════════════════════════
    // AMIAL-AGENT-SETTINGS-001 — الإعدادات
    // ══════════════════════════════════════════════════════════════════

    /**
     * كلُّ ما يُضبَط في مكانٍ واحد.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولماذا لم تكن موجودة؟** لأنّ كلّ إعدادٍ بُني مع ميزته وبقي عندها.
     * فساعاتُ عمل الفرع لها نقطة نهاية منذ شهور **ولا زرّ لها في أيّ
     * شاشة**، وحدُّ تنبيه النقد المنخفض — الذي تُبنى عليه تنبيهات واتساب
     * كلُّها — لم يكن يُضبط من أيّ مكان.
     *
     * وهي حالةُ العطل المتكرّرة هنا: المنطق يعمل، والمسار مسجَّل، ولا
     * شاشة تناديه. فالميزة موجودةٌ في الشيفرة وغائبةٌ عن المستعمل.
     */
    public function settings(Request $request): JsonResponse
    {
        $staff = $request->attributes->get('agent_staff');
        $agent = $this->companyUser($request);

        // **`companyUser` قد تُعيد `null`** — والقراءة منها بلا فحصٍ
        // تُسقط الشاشة بـ٥٠٠ بدل أن تقول ما الخطب.
        if (!$agent) {
            return $this->error('تعذّر تحديد شركتك — أعد الدخول', 401);
        }

        $branches = AgentBranch::with('till')
            ->where('agent_user_id', $agent->id)->orderBy('name')->get()
            ->map(fn (AgentBranch $b) => [
                'id' => (int) $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'city' => $b->city,
                'is_active' => (bool) $b->is_active,
                'working_hours' => $b->working_hours,
                // «غير مضبوط» ليس صفراً: خزنةٌ حدُّها صفر لا تُنبِّه أبداً،
                // ومن يقرأ «٠» يظنّ الحدّ مضبوطاً على الصفر عمداً.
                'min_cash_alert' => (string) ($b->till->min_cash_alert ?? '0'),
                'max_cash_on_hand' => (string) ($b->till->max_cash_on_hand ?? '0'),
                'cash_on_hand' => (string) ($b->till->cash_on_hand ?? '0'),
                'alerts_configured' => $b->till
                    && bccomp((string) $b->till->min_cash_alert, '0', 4) > 0,
            ])->all();

        return $this->ok([
            'company' => [
                'name' => trim(($agent->f_name ?? '') . ' ' . ($agent->l_name ?? '')) ?: '—',
                'phone' => $agent->phone,
                'portal_url' => route('agent.login'),
            ],
            'me' => $staff instanceof AgentStaff ? [
                'name' => $staff->name, 'username' => $staff->username,
                'role_label' => $staff->roleLabel(),
                'can_manage' => !$staff->isTeller(),
            ] : null,
            'branches' => $branches,
            // **وضعيّة التشفير تُقاس من الطلب لا من متغيّر بيئة.**
            // ومن يقرأها من `APP_URL` يستنتج أنّ التشفير يعمل ولو كان
            // الخادم يردّ على HTTP وحده.
            'security' => \App\Http\Middleware\HttpsPosture::describe($request),
            'announcements' => \App\Models\Agent\AgentAnnouncement::where('agent_user_id', $agent->id)
                ->orderByDesc('id')->limit(20)->get()
                ->map(fn ($a) => [
                    'id' => (int) $a->id, 'title' => $a->title, 'body' => $a->body,
                    'severity' => $a->severity, 'audience' => $a->audience,
                    'is_active' => (bool) $a->is_active,
                    'at' => $a->created_at?->toDateTimeString(),
                ])->all(),
        ]);
    }

    /**
     * حدود خزنة الفرع — الحدّ الأدنى للتنبيه والسقف الأعلى.
     *
     * **والأدنى هو ما تُبنى عليه تنبيهات واتساب كلُّها.** وبقاؤه صفراً
     * يعني أنّ «نقد فرعك منخفض» لن يُرسَل أبداً — تنبيهٌ مبنيٌّ يعمل على
     * عتبةٍ لا يضبطها أحد.
     */
    public function setBranchThresholds(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'min_cash_alert' => 'required|numeric|min:0',
            'max_cash_on_hand' => 'required|numeric|min:0',
        ]);

        $staff = $request->attributes->get('agent_staff');

        if ($staff instanceof AgentStaff && $staff->isTeller()) {
            return $this->error('ضبطُ حدود الخزنة من صلاحية الإدارة', 403);
        }

        $branch = $this->authorizedBranch($request, $id);
        $min = (string) $request->input('min_cash_alert');
        $max = (string) $request->input('max_cash_on_hand');

        // سقفٌ دون حدّ التنبيه يجعل الخزنة «منخفضةً وممتلئة» معاً — وهي
        // حالةٌ لا معنى لها تُنتج تنبيهين متناقضين في الدقيقة نفسها.
        if (bccomp($max, '0', 4) > 0 && bccomp($max, $min, 4) < 0) {
            return $this->error('السقف الأعلى لا يكون دون حدّ التنبيه', 422);
        }

        $till = app(\App\Services\AgentTillService::class)->tillFor($branch);
        $till->forceFill(['min_cash_alert' => $min, 'max_cash_on_hand' => $max])->save();

        $this->audit->record([
            'actor_type' => 'agent',
            'actor_user_id' => $branch->agent_user_id,
            'action' => 'agent.branch.thresholds',
            'severity' => 'notice',
            'subject_type' => 'agent_branch',
            'subject_id' => (string) $branch->id,
            'context' => ['min_cash_alert' => $min, 'max_cash_on_hand' => $max],
        ]);

        return $this->ok(
            ['min_cash_alert' => $min, 'max_cash_on_hand' => $max],
            'حُفظت حدود خزنة ' . $branch->name,
        );
    }

    /** تعميمٌ من إدارة الشركة إلى شبابيكها. */
    public function createAnnouncement(Request $request): JsonResponse
    {
        $request->validate([
            'title' => 'required|string|max:160',
            'body' => 'required|string|max:2000',
            'severity' => 'nullable|string|in:info,warning,critical',
            'audience' => 'nullable|string|in:all,tellers,managers',
            'ends_at' => 'nullable|date',
        ]);

        $staff = $request->attributes->get('agent_staff');

        if ($staff instanceof AgentStaff && $staff->isTeller()) {
            return $this->error('التعاميم من صلاحية الإدارة', 403);
        }

        $agent = $this->companyUser($request);

        if (!$agent) {
            return $this->error('تعذّر تحديد شركتك — أعد الدخول', 401);
        }

        $row = \App\Models\Agent\AgentAnnouncement::create([
            // **يُختم بشركة الكاتب من جلسته لا من طلبه.** ومعرّفٌ يأتي
            // من المتصفّح يعني تعميماً يُكتب في شركةٍ منافسة.
            'agent_user_id' => $agent->id,
            'audience' => $request->input('audience', 'all'),
            'severity' => $request->input('severity', 'info'),
            'title' => (string) $request->input('title'),
            'body' => (string) $request->input('body'),
            'is_active' => true,
            'starts_at' => now(),
            'ends_at' => $request->input('ends_at'),
            'created_by_staff_id' => $staff instanceof AgentStaff ? $staff->id : null,
        ]);

        return $this->ok(['id' => (int) $row->id], 'نُشر التعميم — يظهر في شبابيكك الآن');
    }

    public function toggleAnnouncement(Request $request, int $id): JsonResponse
    {
        $staff = $request->attributes->get('agent_staff');

        if ($staff instanceof AgentStaff && $staff->isTeller()) {
            return $this->error('التعاميم من صلاحية الإدارة', 403);
        }

        $agent = $this->companyUser($request);

        $row = $agent ? \App\Models\Agent\AgentAnnouncement::where('id', $id)
            ->where('agent_user_id', $agent->id)->first() : null;

        if (!$row) {
            return $this->error('التعميم خارج نطاقك', 404);
        }

        $row->forceFill(['is_active' => !$row->is_active])->save();

        return $this->ok(['is_active' => (bool) $row->is_active],
            $row->is_active ? 'أُعيد نشر التعميم' : 'أُوقف التعميم');
    }

    /**
     * تغييرُ الموظّف كلمةَ سرّه.
     *
     * **ولم تكن موجودة.** كان يستطيع المديرُ أن يُبدّل كلمة سرّ غيره ولا
     * يستطيع أحدٌ أن يُبدّل كلمة سرّه هو — فمن ظنّ أنّ كلمته عُرفت يطلب
     * من مديره أن يُبدّلها، **فيصير مديرُه يعرفها**.
     */
    public function changeMyPassword(Request $request): JsonResponse
    {
        $request->validate([
            'current' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        $staff = $request->attributes->get('agent_staff');

        if (!$staff instanceof AgentStaff) {
            return $this->error('هذه الخاصيّة لحسابات الموظّفين — ادخل برمز موظّف', 422);
        }

        // **الحاليّة تُطلب.** ومن غيرها يكفي أن يترك الموظّف شاشته مفتوحة
        // دقيقةً ليُقفل حسابُه عليه بكلمةٍ لا يعرفها.
        if (!\Illuminate\Support\Facades\Hash::check(
            (string) $request->input('current'), (string) $staff->password
        )) {
            return $this->error('كلمة السرّ الحاليّة غير صحيحة', 422);
        }

        $staff->forceFill([
            'password' => \Illuminate\Support\Facades\Hash::make((string) $request->input('password')),
        ])->save();

        $this->audit->record([
            'actor_type' => 'agent',
            'actor_user_id' => $staff->agent_user_id,
            'action' => 'agent.staff.self_password',
            'severity' => 'notice',
            'subject_type' => 'agent_staff',
            'subject_id' => (string) $staff->id,
        ]);

        return $this->ok([], 'تغيّرت كلمة سرّك — استعملها في الدخول القادم');
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

    /**
     * شحن رصيد الفرع الإلكترونيّ من الشركة الأمّ — غير توريد النقد.
     *
     * وهو قرارُ الإدارة العامّة وحدها: المال يخرج من محفظة **الشركة**، فلا
     * يسحبه مديرُ فرعٍ إلى فرعه بنفسه. وكان يستطيع — لأنّ فرعه ضمن فروعه
     * المسموحة، والخدمة تفحص أبوّة الفرع لا رتبةَ الطالب.
     */
    public function fundBranch(Request $request, int $id): JsonResponse
    {
        if ($guard = $this->headOfficeOnly($request, 'شحن رصيد الفروع')) {
            return $guard;
        }

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

    /** سحب رصيدٍ إلكترونيٍّ من الفرع إلى الشركة الأمّ — عكس الشحن. */
    public function collectBranch(Request $request, int $id): JsonResponse
    {
        if ($guard = $this->headOfficeOnly($request, 'سحب رصيد الفروع')) {
            return $guard;
        }

        $request->validate([
            'amount' => 'required|numeric|min:1',
            'note' => 'required|string|min:5|max:500',
        ]);

        $branch = $this->authorizedBranch($request, $id);

        try {
            $out = $this->branches->collectFromBranch(
                $branch, $this->companyUser($request),
                (string) $request->input('amount'), (string) $request->input('note'),
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok($out, 'سُحب الرصيد — رصيد الفرع الآن ' . $out['branch_balance']);
    }

    /**
     * كشفُ الطبقة الثانية: ما بين الشركة وفروعها.
     *
     * الطبقة الأولى (المنصّة ← الشركة) في تبويب التسويات. وهذه تكملتُها:
     * بلا الاثنتين معاً لا يعرف المدير أين ذهب الرصيد الذي اشتراه.
     */
    public function branchSettlement(Request $request): JsonResponse
    {
        $rows = $this->branches->settlementWithBranches($this->companyUser($request));

        // مديرُ الفرع يرى فرعه وحده. والشركة كلّها تُقرأ من الإدارة العامّة —
        // فأرقامُ فرعٍ آخر ليست من شأنه، ولا تُترك الشاشة تكشفها بحجّة أنّ
        // القائمة تُبنى مرّةً واحدة.
        if ((string) $request->attributes->get('portal_role') !== AgentStaff::ROLE_HEAD_OFFICE) {
            $allowed = array_map('intval', (array) $request->attributes->get('agent_branch_ids', []));
            $rows = array_values(array_filter($rows, fn ($r) => in_array($r['id'], $allowed, true)));
        }

        return $this->ok(['branches' => $rows]);
    }

    /**
     * تسويةُ اليوم: ما جرى، وماذا يجب أن يتحوّل، ومتى تُفتح النافذة.
     *
     * **يُعرَض قبل الرفع لا بعده.** فمن يرفع رقماً لم يقرأه لا يكون قد
     * أقرّ به.
     */
    public function dailySettlement(Request $request): JsonResponse
    {
        $agent = $this->companyUser($request);
        $date = (string) $request->query('date', now()->toDateString());
        $svc = app(AgentDailySettlementService::class);

        $row = \App\Models\Agent\AgentDailySettlement::where('agent_user_id', $agent->id)
            ->whereDate('settlement_date', $date)->first();

        return $this->ok([
            'day' => $svc->computeDay($agent, $date),
            'window' => $svc->windowState($date),
            'submitted' => $row ? [
                'ulid' => $row->settlement_ulid,
                'status' => $row->status,
                'status_label' => \App\Models\Agent\AgentDailySettlement::STATUS_LABELS[$row->status] ?? $row->status,
                'window_state' => $row->window_state,
                'window_label' => $row->window_state
                    ? (\App\Models\Agent\AgentDailySettlement::WINDOW_LABELS[$row->window_state] ?? '') : null,
                'submitted_at' => $row->submitted_at?->toDateTimeString(),
                'decision_note' => $row->decision_note,
                'unlocked' => $row->unlocked_at !== null,
                'unlock_reason' => $row->unlock_reason,
                'conversion_amount' => (string) $row->conversion_amount,
            ] : null,
            'history' => \App\Models\Agent\AgentDailySettlement::where('agent_user_id', $agent->id)
                ->orderByDesc('settlement_date')->limit(30)->get()
                ->map(fn ($r) => [
                    'date' => $r->settlement_date->toDateString(),
                    'status' => $r->status,
                    'status_label' => \App\Models\Agent\AgentDailySettlement::STATUS_LABELS[$r->status] ?? $r->status,
                    'window_state' => $r->window_state,
                    'conversion' => $r->conversion,
                    'conversion_label' => \App\Models\Agent\AgentDailySettlement::CONVERSION_LABELS[$r->conversion] ?? '',
                    'conversion_amount' => (string) $r->conversion_amount,
                    'deposits_total' => (string) $r->deposits_total,
                    'withdrawals_total' => (string) $r->withdrawals_total,
                    'shortage_total' => (string) $r->shortage_total,
                    'overage_total' => (string) $r->overage_total,
                    'decision_note' => $r->decision_note,
                ])->values()->all(),
        ]);
    }

    /** رفعُ تسوية اليوم إلى أميال — داخل النافذة أو بفكٍّ منها. */
    public function submitDailySettlement(Request $request): JsonResponse
    {
        $request->validate(['date' => 'nullable|date']);

        $staff = $request->attributes->get('agent_staff');

        if (!$staff instanceof AgentStaff) {
            return $this->error('رفعُ التسوية بحساب الإدارة العامّة للشركة', 403);
        }

        try {
            $row = app(AgentDailySettlementService::class)->submit(
                $this->companyUser($request), $staff,
                (string) $request->input('date', now()->toDateString()),
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(
            ['ulid' => $row->settlement_ulid, 'window_state' => $row->window_state],
            $row->window_state === 'on_time'
                ? 'رُفعت تسوية اليوم إلى أميال — بانتظار قرارهم'
                : 'رُفعت التسوية وسُجّلت **متأخّرة** — سيراها فريق أميال بهذه الصفة',
        );
    }

    /**
     * التقرير الكامل — ستّة أقسامٍ في نداءٍ واحد.
     *
     * ونداءٌ واحدٌ مقصود: ستّة نداءاتٍ متتابعة تبني الشاشة على دفعات،
     * فمن يضغط «طباعة» بعد ثانيةٍ يطبع تقريراً نصفَ جاهز.
     */
    public function reports(Request $request): JsonResponse
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        $agent = $this->companyUser($request);

        // مديرُ الفرع يرى فرعه، والإدارة العامّة ترى الشركة.
        $allowed = array_map('intval', (array) $request->attributes->get('agent_branch_ids', []));
        $scoped = (string) $request->attributes->get('portal_role') === AgentStaff::ROLE_HEAD_OFFICE
            ? [] : $allowed;

        return $this->ok([
            'report' => app(AgentReportsService::class)->full(
                $agent,
                (string) $request->query('from', now()->subDays(29)->toDateString()),
                (string) $request->query('to', now()->toDateString()),
                $scoped,
            ),
        ]);
    }

    /** ما يُحرّك مال الشركة قرارُ إدارتها العامّة. */
    private function headOfficeOnly(Request $request, string $what): ?JsonResponse
    {
        if ((string) $request->attributes->get('portal_role') === AgentStaff::ROLE_HEAD_OFFICE) {
            return null;
        }

        return $this->error("{$what} من صلاحية الإدارة العامّة للشركة", 403);
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
