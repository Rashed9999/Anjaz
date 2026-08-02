<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\AgentStaff;
use App\Services\AgentShiftService;
use App\Services\AgentShiftStatementService;
use App\Services\AgentStaffProfileService;
use App\Services\AuditService;
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

    /**
     * ملفّ الموظّف الموحَّد — عمليّاته وورديّاته وتقييمه ودرجة مخاطرته.
     *
     * والصرّاف يرى ملفّ نفسه ولا يرى غيره: الرقابة تنزل ولا تصعد.
     */
    public function profile(Request $request, int $id): JsonResponse
    {
        $actor = $this->actor($request);
        $target = AgentStaff::find($id);

        if (!$target || (int) $target->agent_user_id !== (int) $actor->agent_user_id) {
            return response()->json(['success' => false, 'message' => 'الموظّف خارج نطاقك'], 404);
        }

        if ($actor->isTeller() && (int) $target->id !== (int) $actor->id) {
            return response()->json(['success' => false, 'message' => 'ملفّات الموظّفين للإدارة'], 403);
        }

        if (!$actor->isTeller() && !$actor->isHeadOffice()
            && !in_array((int) $target->branch_id, $actor->visibleBranchIds(), true)) {
            return response()->json(['success' => false, 'message' => 'الموظّف خارج فرعك'], 403);
        }

        return response()->json([
            'success' => true,
            'meta' => app(AgentStaffProfileService::class)->profile(
                $target, $request->query('from'), $request->query('to'),
            ),
        ]);
    }

    /** سجلُّ عمليات الشركة — إيداعاً وسحباً، باسم من نفّذ. */
    public function operations(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return response()->json([
            'success' => true,
            'meta' => app(AgentStaffProfileService::class)->operationsLog(
                $actor->visibleBranchIds(),
                $request->only(['branch_id', 'staff_id', 'reason', 'from', 'to']),
            ),
        ]);
    }

    /**
     * كشفُ تسوية ورديّةٍ كما رفعه صاحبها — تقرؤه الإدارة قبل أن تقرّر.
     *
     * **وهو نفسُ المستند الذي يراه الصرّاف حرفيّاً.** فلو بُني للإدارة
     * كشفٌ آخر لصار الخلاف على أيّ الرقمين صحيح بدل الخلاف على الفرق.
     */
    public function shiftStatement(Request $request, int $shiftId): JsonResponse
    {
        $actor = $this->actor($request);

        $shift = \App\Models\Agent\AgentShift::find($shiftId);

        if (!$shift || !in_array((int) $shift->branch_id, $actor->visibleBranchIds(), true)) {
            return response()->json(['success' => false, 'message' => 'الورديّة خارج نطاقك'], 404);
        }

        if ($actor->isTeller() && (int) $shift->staff_id !== (int) $actor->id) {
            return response()->json(['success' => false, 'message' => 'هذه ورديّة زميلك'], 403);
        }

        return response()->json([
            'success' => true,
            'meta' => app(AgentShiftStatementService::class)->build($shift),
        ]);
    }

    /**
     * قرارُ الإدارة في ملفّ تسوية ورديّة.
     *
     * **الفرق لا يُغلق بنفسه.** كان يُسجَّل ثمّ يُترك، فيمرّ العجز بصمتٍ
     * ويصير عُرفاً. وهنا يُنسب القرار إلى إنسانٍ باسمه ووقته وسببه.
     */
    public function reviewShift(Request $request, int $shiftId): JsonResponse
    {
        $request->validate([
            'decision' => 'required|in:accepted,investigating,resolved',
            'note' => 'required|string|min:10|max:500',
        ]);

        $actor = $this->actor($request);

        if ($actor->isTeller()) {
            // ولا يُراجع الصرّافُ ورديّةَ نفسه: من عدّ الدرج لا يُصدّق على عدّه.
            return response()->json([
                'success' => false,
                'message' => 'مراجعة الفروق من صلاحية الإدارة — لا يُصدّق الصرّاف على عدّ نفسه',
            ], 403);
        }

        $shift = \App\Models\Agent\AgentShift::find($shiftId);

        if (!$shift || !in_array((int) $shift->branch_id, $actor->visibleBranchIds(), true)) {
            return response()->json(['success' => false, 'message' => 'الورديّة خارج نطاقك'], 404);
        }

        if ($shift->status !== \App\Models\Agent\AgentShift::STATUS_CLOSED) {
            return response()->json(['success' => false, 'message' => 'الورديّة لم تُغلق بعد'], 422);
        }

        $shift->forceFill([
            'review_status' => $request->input('decision'),
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_note' => (string) $request->input('note'),
        ])->save();

        app(AuditService::class)->record([
            'actor_type' => 'agent',
            'actor_user_id' => $actor->agent_user_id,
            'action' => 'agent.shift.review',
            'severity' => $request->input('decision') === 'investigating' ? 'critical' : 'warning',
            'subject_type' => 'agent_shift',
            'subject_id' => $shift->id,
            'metadata' => [
                'decision' => $request->input('decision'),
                'reviewer_staff_id' => $actor->id,
                'variance' => (string) $shift->variance,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'سُجّل القرار: ' . (\App\Models\Agent\AgentShift::REVIEW_LABELS[$request->input('decision')] ?? ''),
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
