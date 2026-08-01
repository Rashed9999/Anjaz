<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\AgentStaff;
use App\Services\AgentShiftService;
use App\Services\AgentStaffService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-AGENT-STAFF-001 — إدارة موظّفي شركة الصرافة من داخل بوّابتها.
 *
 * الشركة تُعيّن وتُعطّل بنفسها. ولو مرّ تعيينُ صرّافٍ بتذكرة دعمٍ عندنا
 * لتعطّل فرعٌ في المكلا انتظاراً لموظّفٍ في صنعاء — وشركةٌ لها آلاف
 * الموظّفين تُبدّل بعضهم كلّ أسبوع.
 */
class AgentStaffController extends Controller
{
    public function __construct(
        private readonly AgentStaffService $staff,
        private readonly AgentShiftService $shifts,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json([
            'data' => $this->staff->listFor($actor),
            'can_manage' => !$actor->isTeller(),
            'roles' => AgentStaff::ROLE_LABELS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:120',
            'password' => 'required|string|min:6',
            'role' => 'required|string',
            'branch_id' => 'nullable|integer',
            'phone' => 'nullable|string|max:20',
            'max_txn_amount' => 'nullable|numeric|min:0',
        ]);

        try {
            $staff = $this->staff->hire($this->actor($request), $request->all());
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            // الرمز يُعرَض مرّةً واضحاً: هو ما سيكتبه الموظّف كلّ صباح،
            // وإخفاؤه في جدولٍ طويلٍ يجعل أوّل سؤالٍ للدعم «ما رمزي؟».
            'message' => "أُنشئ الحساب. رمز الدخول: {$staff->username} — سلّمه للموظّف مع كلمة السرّ",
            'username' => $staff->username,
        ]);
    }

    public function setActive(Request $request, int $id): JsonResponse
    {
        $request->validate(['active' => 'required|boolean']);

        try {
            $staff = $this->staff->setActive($this->actor($request), $id, $request->boolean('active'));
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $staff->is_active ? 'أُعيد تفعيل الحساب' : 'عُطّل الحساب',
        ]);
    }

    public function resetPassword(Request $request, int $id): JsonResponse
    {
        $request->validate(['password' => 'required|string|min:6']);

        try {
            $this->staff->resetPassword($this->actor($request), $id, (string) $request->input('password'));
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'message' => 'تغيّرت كلمة السرّ']);
    }

    /**
     * ورديّات الشبابيك — ما يراه المدير عن فرعه.
     *
     * ويُحصر الفرع بما يراه الفاعل: معرّفٌ يأتي من المتصفّح يُفحص، ولا يُبنى
     * عليه استعلام.
     */
    public function shifts(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $visible = $actor->visibleBranchIds();

        $branchId = (int) $request->query('branch_id', (string) ($visible[0] ?? 0));

        if (!in_array($branchId, $visible, true)) {
            return response()->json(['data' => [], 'message' => 'الفرع خارج نطاقك'], 403);
        }

        return response()->json([
            'data' => $this->shifts->forBranch($branchId, $request->query('date')),
        ]);
    }

    /** إغلاق ورديّة صرّافٍ غادر ولم يُغلق — بيد المدير. */
    public function closeShift(Request $request, int $shiftId): JsonResponse
    {
        $request->validate([
            'counted_cash' => 'required|numeric|min:0',
            'note' => 'nullable|string|max:500',
        ]);

        $actor = $this->actor($request);

        if ($actor->isTeller()) {
            return response()->json(['success' => false, 'message' => 'الصرّاف يغلق ورديّته من الشبّاك'], 403);
        }

        $shift = \App\Models\Agent\AgentShift::find($shiftId);

        if (!$shift || !in_array((int) $shift->branch_id, $actor->visibleBranchIds(), true)) {
            return response()->json(['success' => false, 'message' => 'الورديّة خارج نطاقك'], 404);
        }

        try {
            $closed = $this->shifts->close(
                $actor, $shift,
                (string) $request->input('counted_cash'),
                (string) $request->input('note', ''),
            );
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'أُغلقت الورديّة — الفرق ' . $closed->variance,
        ]);
    }

    private function actor(Request $request): AgentStaff
    {
        $s = $request->attributes->get('agent_staff');

        if (!$s instanceof AgentStaff) {
            // صاحب الشركة داخلٌ بهاتفه ولم يُنشئ حساب إدارةٍ عامّة بعد.
            // يُنشأ له عند الحاجة بدل أن تُغلق الشاشة في وجهه.
            $user = \Illuminate\Support\Facades\Auth::guard('user')->user();

            abort_unless($user, 401);

            return app(AgentStaffService::class)->ensureHeadOfficeAccount($user);
        }

        return $s;
    }
}
