<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\AgentBranch;
use App\Models\EMoney;
use App\Models\User;
use App\Services\AgentBranchService;
use App\Services\AgentCounterService;
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
    ) {
    }

    // ── الدخول ──────────────────────────────────────────────────────────

    public function loginPage()
    {
        if (Auth::guard('user')->check()
            && (int) Auth::guard('user')->user()->type === AGENT_TYPE) {
            return redirect()->route('agent.dashboard');
        }

        return view('agent-views.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'password' => 'required|string',
        ]);

        $phone = preg_replace('/\D+/', '', (string) $request->input('phone'));
        $user = User::where('phone', $phone)->first();

        // رسالةٌ واحدة للحالتين — «الهاتف غير مسجَّل» تُخبر من يجرّب أنّ
        // الرقم صحيح، فيبقى له كلمة السرّ وحدها.
        if (!$user || !Hash::check($request->input('password'), (string) $user->password)) {
            return back()->withErrors(['phone' => 'بيانات الدخول غير صحيحة'])->withInput();
        }

        if ((int) $user->type !== AGENT_TYPE) {
            return back()->withErrors(['phone' => 'هذه البوّابة للوكلاء وفروعهم'])->withInput();
        }

        if ((int) ($user->is_temp_blocked ?? 0) === 1) {
            return back()->withErrors(['phone' => 'الحساب موقوف — راجع إدارة أميال'])->withInput();
        }

        Auth::guard('user')->login($user, (bool) $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->route('agent.dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('user')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('agent.login');
    }

    // ── الصفحات ─────────────────────────────────────────────────────────

    public function dashboard()
    {
        return view('agent-views.dashboard');
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
                'low_cash_branches' => count(array_filter($branches, fn ($b) => $b['cash_is_low'])),
            ],
        ]);
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
                    $request->user(), $request->input('note'))
                : $this->counter->withdraw($branch, $customer, (string) $request->input('amount'),
                    $request->user(), $request->input('note'));
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok($out, $op === 'deposit'
            ? "تمّ الإيداع — المرجع {$out['reference']}"
            : "تمّ السحب — سلّم العميل {$out['amount']} نقداً. المرجع {$out['reference']}");
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
            $branch = $this->branches->create($request->user(), $request->all());
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
                $branch, $request->user(),
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
                $branch, $request->user(), $request->input('direction'),
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
                $branch, $request->user(),
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
