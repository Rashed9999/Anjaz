<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\User;
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

    /** سحب: العميل يستلم نقداً من الدرج. */
    public function withdraw(Request $request): JsonResponse
    {
        return $this->operate($request, 'withdraw');
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
