<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\CustomerWithdrawService;
use Illuminate\Support\Facades\DB;
use App\Services\AgentCounterService;
use App\Services\AgentShiftService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-AGENT-STAFF-001 — شبّاك الصرّاف.
 *
 * **لا معرّف فرعٍ في أيّ مسارٍ هنا.** الفرع يأتي من ورديّة الداخل، والورديّة
 * من حسابه. وكلّ مسارٍ يقبل `branch_id` من المتصفّح هو مسارٌ يستطيع موظّفٌ
 * أن يعمل به على فرعٍ ليس فرعه — والتحقّق بعد القبول أضعف من ألّا يُقبل.
 */
class AgentCounterController extends Controller
{
    public function __construct(
        private readonly AgentShiftService $shifts,
        private readonly AgentCounterService $counter,
    ) {
    }

    /** حالة الشبّاك: هل هناك ورديّة مفتوحة، وكم في الدرج. */
    public function state(Request $request): JsonResponse
    {
        $staff = $this->staff($request);
        $shift = $staff?->openShift();

        $branch = $staff?->branch_id ? AgentBranch::find($staff->branch_id) : null;

        return response()->json([
            'staff' => $staff ? [
                'name' => $staff->name,
                'username' => $staff->username,
                'role' => $staff->role,
                'role_label' => $staff->roleLabel(),
                'max_txn_amount' => (string) $staff->max_txn_amount,
            ] : null,
            // الفرع يُعرَض ولا يُختار — هذه هي كلّ الفكرة.
            'branch' => $branch ? [
                'id' => (int) $branch->id,
                'name' => $branch->name,
                'code' => $branch->code,
                'city' => $branch->city,
            ] : null,
            'shift' => $shift ? $this->shifts->state($shift) : null,
            'can_operate' => $staff?->isTeller() && $shift !== null,
            'why_not' => $this->whyNot($staff, $shift),
        ]);
    }

    public function openShift(Request $request): JsonResponse
    {
        $request->validate(['opening_float' => 'required|numeric|min:0']);

        try {
            $shift = $this->shifts->open(
                $this->requireStaff($request),
                (string) $request->input('opening_float'),
            );
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'فُتحت الورديّة — درجك جاهز',
            'shift' => $this->shifts->state($shift),
        ]);
    }

    public function closeShift(Request $request): JsonResponse
    {
        $request->validate([
            'counted_cash' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ]);

        $staff = $this->requireStaff($request);
        $shift = $staff->openShift();

        if (!$shift) {
            return response()->json(['success' => false, 'message' => 'لا ورديّة مفتوحة'], 422);
        }

        try {
            $closed = $this->shifts->close(
                $staff, $shift,
                (string) $request->input('counted_cash'),
                (string) $request->input('note', ''),
            );
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $variance = (string) $closed->variance;

        return response()->json([
            'success' => true,
            'message' => bccomp($variance, '0', 4) === 0
                ? 'أُغلقت الورديّة مطابِقة'
                : 'أُغلقت الورديّة بفرق ' . $variance . ' — سُجّل الفرق بسببه',
            'shift' => $this->shifts->state($closed),
        ]);
    }

    /** إيداع: العميل يسلّم نقداً. الفرع من الورديّة. */
    public function deposit(Request $request): JsonResponse
    {
        return $this->operate($request, 'deposit');
    }

    /**
     * المسار القديم للسحب — **مُغلق**.
     *
     * كان يخصم من محفظة العميل بمعرّفه وحده بلا رمزٍ ولا موافقة. ولا يُحذف
     * بصمتٍ بل يردّ سبباً: عميلٌ أو صرّافٌ يعتاد شاشةً قديمة يستحقّ أن يُقال
     * له ما تغيّر ولماذا.
     */
    public function withdraw(Request $request): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'السحب صار برمز العملية الذي يُصدره العميل من تطبيقه — لا برقم هاتفه.',
        ], 410);
    }

    private function operate(Request $request, string $op): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|integer',
            'amount' => 'required|numeric|min:1',
            'note' => 'nullable|string|max:255',
        ]);

        $staff = $this->requireStaff($request);
        $shift = $staff->openShift();

        if (!$shift) {
            return response()->json([
                'success' => false,
                'message' => 'افتح ورديّتك أوّلاً — لا يُعمل على الشبّاك بلا درجٍ مفتوح',
            ], 422);
        }

        $amount = (string) $request->input('amount');

        // حدّ الموظّف يُفحص قبل كلّ شيء: شركةٌ تضع لصرّافٍ جديدٍ حدّاً منخفضاً
        // تتوقّع أن يُمنع، لا أن يُسجَّل ويُراجَع لاحقاً.
        if (bccomp((string) $staff->max_txn_amount, '0', 4) > 0
            && bccomp($amount, (string) $staff->max_txn_amount, 4) > 0) {
            return response()->json([
                'success' => false,
                'message' => 'المبلغ يتجاوز حدّك للعملية الواحدة (' . $staff->max_txn_amount . ') — حوّله إلى مدير الفرع',
            ], 422);
        }

        $branch = AgentBranch::find($shift->branch_id);
        $customer = User::find((int) $request->input('customer_id'));

        if (!$branch || !$customer) {
            return response()->json(['success' => false, 'message' => 'الفرع أو العميل غير موجود'], 404);
        }

        try {
            $result = $op === 'deposit'
                ? $this->counter->deposit($branch, $customer, $amount, $branch->account, $request->input('note'), $shift)
                : $this->counter->withdraw($branch, $customer, $amount, $branch->account, $request->input('note'), $shift);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $op === 'deposit' ? 'تمّ الإيداع' : 'تمّ السحب — سلّم النقد للعميل',
            'result' => $result,
            'shift' => $this->shifts->state($shift->fresh()),
        ]);
    }


    // ── السحب برمز العملية ───────────────────────────────────────────
    //
    // **العطل الذي يُصلحه هذا القسم كان ثغرةً أمنيّة أدخلتُها.**
    //
    // في المنصّة نظامُ سحبٍ كامل يبدأ من العميل: يطلب من تطبيقه، فيُحجز
    // المبلغ فوراً ويصدر **رمز عمليّةٍ مؤقّت** ينتهي بعد مدّة. ثمّ يذهب إلى
    // الصرّاف بالرمز. فموافقة العميل مثبتةٌ لأنّه أنشأ الطلب بنفسه على جهازه.
    //
    // وشبّاكي كان يتجاوز ذلك كلّه: يُدخل الصرّاف رقم هاتف العميل ومبلغاً
    // فيُخصم من محفظته. **بلا رمز، وبلا موافقةٍ من العميل، وبلا أثرٍ يُثبت
    // أنّه طلب السحب أصلاً.** أي أنّ أيّ صرّافٍ يستطيع أن يسحب من أيّ عميلٍ
    // يعرف رقمه.
    //
    // فصار السحب لا يقع إلّا برمزٍ أصدره العميل.

    /** قراءة عمليةٍ برمزها قبل الدفع — الصرّاف يرى ما سيدفع. */
    public function lookupWithdrawal(Request $request): JsonResponse
    {
        $code = strtoupper(trim((string) $request->query('op_code', '')));

        if ($code === '') {
            return response()->json(['success' => false, 'message' => 'أدخل رمز العملية'], 422);
        }

        $req = WithdrawalRequest::where('op_code', $code)->first();

        if (!$req) {
            return response()->json(['success' => false, 'message' => 'رمز العملية غير صحيح'], 404);
        }

        if ($req->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => $req->status === 'completed'
                    ? 'نُفّذت هذه العملية مسبقاً'
                    : 'العملية ملغاة أو منتهية',
            ], 422);
        }

        if ($req->expires_at && $req->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'انتهت صلاحية الرمز — يطلب العميل رمزاً جديداً من تطبيقه',
            ], 422);
        }

        $customer = User::find($req->customer_user_id);
        $staff = $this->staff($request);
        $shift = $staff?->openShift();

        // النقد المطلوب هو `amount` وحده: الرسم إلكترونيّ ولا يخرج من الدرج.
        $canPay = $shift !== null && $shift->canPayOut((string) $req->amount);

        return response()->json([
            'success' => true,
            'request' => [
                'op_code' => $req->op_code,
                'amount' => (string) $req->amount,
                'fee' => (string) $req->fee,
                'total_debit' => (string) $req->total_debit,
                'expires_at' => $req->expires_at?->toDateTimeString(),
                'customer' => [
                    'name' => $customer
                        ? (trim(($customer->f_name ?? '') . ' ' . ($customer->l_name ?? '')) ?: '—')
                        : '—',
                    'phone' => $customer?->phone,
                ],
            ],
            // يُقال للصرّاف قبل أن يعدّ الأوراق لا بعد.
            'can_pay' => $canPay,
            'why_not' => $canPay ? null : ($shift
                ? 'النقد في درجك ' . $shift->cash_on_hand . ' ولا يكفي — اطلب توريداً من خزنة الفرع'
                : 'افتح ورديّتك أوّلاً'),
        ]);
    }

    /** تنفيذ السحب: يُصرَف المحجوز، ويخرج النقد من درج الصرّاف. */
    public function withdrawByCode(Request $request): JsonResponse
    {
        $request->validate([
            'op_code' => 'required|string|max:40',
            'identifier' => 'nullable|string|max:32',
        ]);

        $staff = $this->requireStaff($request);
        $shift = $staff->openShift();

        if (!$shift) {
            return response()->json([
                'success' => false,
                'message' => 'افتح ورديّتك أوّلاً — لا يُدفع نقدٌ بلا درجٍ مفتوح',
            ], 422);
        }

        $code = strtoupper(trim((string) $request->input('op_code')));
        $req = WithdrawalRequest::where('op_code', $code)->first();

        if (!$req || $req->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'رمز العملية غير صالح'], 422);
        }

        $amount = (string) $req->amount;

        // حدّ الموظّف يُفحص هنا أيضاً: الرمز صادرٌ من العميل، والحدّ على من
        // يدفع لا على من يطلب.
        if (bccomp((string) $staff->max_txn_amount, '0', 4) > 0
            && bccomp($amount, (string) $staff->max_txn_amount, 4) > 0) {
            return response()->json([
                'success' => false,
                'message' => 'المبلغ يتجاوز حدّك للعملية الواحدة — حوّله إلى مدير الفرع',
            ], 422);
        }

        $branch = AgentBranch::find($shift->branch_id);

        if (!$branch || !$branch->account) {
            return response()->json(['success' => false, 'message' => 'الفرع غير مهيّأ'], 404);
        }

        // **قبل أيّ خصم:** هل في الدرج ما يكفي؟ ويُعاد الفحص داخل المعاملة.
        if (!$shift->canPayOut($amount)) {
            return response()->json([
                'success' => false,
                'message' => 'النقد في درجك ' . $shift->cash_on_hand . ' ولا يكفي لدفع ' . $amount,
            ], 422);
        }

        try {
            $result = DB::transaction(function () use ($branch, $shift, $req, $request, $code, $amount) {
                $fresh = $shift->fresh();

                if (!$fresh || !$fresh->isOpen() || !$fresh->canPayOut($amount)) {
                    throw new DomainException('تغيّر رصيد درجك — أعد المحاولة');
                }

                // المحفظة التي تُعوَّض هي **محفظة الفرع**: هو من دفع النقد،
                // لا الشركة الأمّ. وتعويضُ الأمّ يترك الفرع ناقصاً كلّ يوم.
                $out = app(CustomerWithdrawService::class)->execute(
                    $branch->account, $code, (string) $request->input('identifier', ''),
                );

                // والنقد يخرج من درج الصرّاف — فيُعرَف صاحبُ العجز عند الجرد.
                $this->shifts->recordDrawer(
                    $fresh, 'out', 'customer_withdraw', $amount,
                    customerId: (int) $req->customer_user_id,
                    reference: $code,
                );

                return $out;
            });
        } catch (DomainException | \RuntimeException | \InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'تمّ السحب — سلّم العميل ' . $amount . ' ر.ي نقداً',
            'result' => $result,
            'shift' => $this->shifts->state($shift->fresh()),
        ]);
    }

    /** توريدٌ بين خزنة الفرع والدرج أثناء الورديّة. */
    public function refill(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'direction' => 'required|in:to_drawer,to_safe',
        ]);

        $staff = $this->requireStaff($request);
        $shift = $staff->openShift();

        if (!$shift) {
            return response()->json(['success' => false, 'message' => 'لا ورديّة مفتوحة'], 422);
        }

        try {
            $fresh = $this->shifts->refill(
                $staff, $shift,
                (string) $request->input('amount'),
                (string) $request->input('direction'),
            );
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'تمّ التوريد',
            'shift' => $this->shifts->state($fresh),
        ]);
    }

    // ── مساعدات ─────────────────────────────────────────────────────────

    private function staff(Request $request): ?AgentStaff
    {
        $s = $request->attributes->get('agent_staff');

        return $s instanceof AgentStaff ? $s : null;
    }

    private function requireStaff(Request $request): AgentStaff
    {
        $staff = $this->staff($request);

        if (!$staff) {
            // صاحب الشركة يدخل بهاتفه ولا شبّاك له: الشبّاك للموظّفين.
            abort(response()->json([
                'success' => false,
                'message' => 'الشبّاك للصرّافين — أنشئ حسابات موظّفيك من تبويب «الموظّفون»',
            ], 403));
        }

        return $staff;
    }

    private function whyNot(?AgentStaff $staff, ?AgentShift $shift): ?string
    {
        if (!$staff) {
            return 'أنت داخلٌ بحساب الشركة — الشبّاك يعمل بحساب صرّاف';
        }
        if (!$staff->isTeller()) {
            return 'دورك ' . $staff->roleLabel() . ' — الشبّاك للصرّافين';
        }
        if (!$staff->branch_id) {
            return 'حسابك بلا فرع — راجع إدارة شركتك';
        }
        if (!$shift) {
            return 'افتح ورديّتك لتبدأ العمل';
        }

        return null;
    }
}
