<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\Agent\AgentStaff;
use App\Models\User;
use App\Services\AgentStaffService;
use App\Services\AgentTellerRequestService;
use App\Services\AgentTellerRiskService;
use App\Services\AgentTellerWorkspaceService;
use App\Services\AuditService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-TELLER-WS-001 — نقاطُ نهاية مساحة عمل الصرّاف.
 *
 * **ولا معرّفَ فرعٍ في أيّ مسارٍ هنا** (القاعدة الثامنة): النطاق من
 * هويّة الداخل، وما لا يُقبل من الطلب لا يحتاج فحصاً.
 */
class AgentTellerController extends Controller
{
    public function __construct(
        private readonly AgentTellerWorkspaceService $workspace,
        private readonly AgentTellerRequestService $requests,
        private readonly AgentTellerRiskService $risk,
        private readonly AuditService $audit,
    ) {
    }

    /** كلّ ما تحتاجه الشاشة في نداءٍ واحد. */
    public function workspace(Request $request): JsonResponse
    {
        return $this->ok($this->workspace->build($this->actor($request)));
    }

    /**
     * فحصُ عميلٍ ومبلغٍ **قبل** التنفيذ.
     *
     * وهي قراءةٌ محضة لا تُحرّك شيئاً — الغرض أن يرى الصرّاف الإشارات
     * وهو يكتب المبلغ، لا بعد أن يضغط.
     */
    public function assess(Request $request): JsonResponse
    {
        $request->validate([
            'customer_id' => 'required|integer',
            'amount' => 'required|numeric|min:0',
            'operation' => 'nullable|string|in:deposit,withdraw',
        ]);

        $staff = $this->actor($request);
        $customer = User::find((int) $request->input('customer_id'));

        if (!$customer) {
            return $this->error('لا عميل بهذا المعرّف', 404);
        }

        $out = $this->risk->assess(
            $customer,
            bcadd((string) $request->input('amount'), '0', 4),
            (string) $request->input('operation', 'deposit'),
            $staff,
        );

        return $this->ok($out);
    }

    // ── ١٧) الموافقات ──────────────────────────────────────────────────

    public function submitRequest(Request $request): JsonResponse
    {
        $request->validate([
            'amount' => 'nullable|numeric|min:0',
            'reason' => 'required|string|max:500',
            'operation' => 'nullable|string|in:deposit,withdraw',
            'kind' => 'nullable|string|in:over_limit,restricted_op,overtime',
            'customer_id' => 'nullable|integer',
        ]);

        try {
            $row = $this->requests->submit($this->actor($request), [
                'amount' => $request->input('amount', '0'),
                'reason' => $request->input('reason'),
                'operation' => $request->input('operation', 'deposit'),
                'kind' => $request->input('kind', 'over_limit'),
                'customer_user_id' => $request->input('customer_id'),
            ]);
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(
            ['number' => $row->request_number, 'expires_at' => $row->expires_at?->toDateTimeString()],
            'أُرسل الطلب إلى مديرك — يظهر عنده الآن، والمهلة ٣٠ دقيقة',
        );
    }

    public function pendingRequests(Request $request): JsonResponse
    {
        return $this->ok(['rows' => $this->requests->pendingFor($this->actor($request))]);
    }

    public function decideRequest(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'approve' => 'required|boolean',
            'note' => 'nullable|string|max:500',
        ]);

        try {
            $row = $this->requests->decide(
                $this->actor($request), $id,
                $request->boolean('approve'), (string) $request->input('note', ''),
            );
        } catch (DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->ok(
            ['status' => $row->status],
            $row->status === 'approved' ? 'وُوفق على الطلب' : 'رُفض الطلب',
        );
    }

    // ── ١٣) الطوارئ ────────────────────────────────────────────────────

    /**
     * زرّ الطوارئ.
     *
     * **بلا تحقّقٍ يمنع.** موظّفٌ تحت تهديدٍ لا يُطلب منه حقلٌ إلزاميّ:
     * كلّ ما يُطلب اختياريّ، والبلاغ يُسجَّل بما وصل.
     */
    public function panic(Request $request): JsonResponse
    {
        $request->validate([
            'kind' => 'nullable|string|in:duress,robbery,fraud,threat',
            'note' => 'nullable|string|max:500',
            'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric',
            'geo_state' => 'nullable|string|in:ok,denied,unavailable,insecure',
        ]);

        $alert = $this->requests->panic($this->actor($request), $request->all());

        return $this->ok(
            ['number' => $alert->alert_number],
            'أُرسل البلاغ إلى إدارتك — رقمه ' . $alert->alert_number,
        );
    }

    // ── ساعاتُ الدوام (AMIAL-WORKTIME-001) ─────────────────────────────

    /**
     * كشفُ ساعاتي — يوميّاً وأسبوعيّاً وشهريّاً.
     *
     * **والصرّاف يرى كشفَه هو وحده.** ولا معرّفَ موظّفٍ في هذا المسار:
     * النطاق من هويّة الداخل. أمّا كشوف الفريق فتُقرأ من تبويب
     * الموظّفين — بفحص نطاقٍ صريح.
     */
    public function timesheet(Request $request): JsonResponse
    {
        $staff = $this->actor($request);

        // مدىً افتراضيّ شهر: أطولُ منه يجعل الشاشة ثقيلةً بلا حاجة،
        // وأقصرُ منه لا يُظهر «الشهريّ» الذي طُلب.
        $to = $request->query('to') ?: now()->toDateString();
        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();

        return $this->ok(app(\App\Services\AgentWorkTimeService::class)
            ->summary($staff, (string) $from, (string) $to));
    }

    /**
     * استراحة: بدايتُها ونهايتُها.
     *
     * **ولولا الاستراحة لما كان أمام الموظّف إلّا إغلاق ورديّته** — وذلك
     * جردُ درجٍ كامل لأجل نصف ساعة غداء. فيبقى الدرج مفتوحاً ويُخصم
     * الوقت.
     */
    public function toggleBreak(Request $request): JsonResponse
    {
        $request->validate(['action' => 'required|string|in:start,end']);

        $staff = $this->actor($request);
        $shift = $staff->openShift();

        if (!$shift) {
            return $this->error('لا ورديّة مفتوحة — الاستراحة داخل الورديّة لا خارجها', 422);
        }

        $wt = app(\App\Services\AgentWorkTimeService::class);
        $start = $request->input('action') === 'start';

        // **حالةُ الاستراحة تُقرأ من السجلّ لا من عمود.** فمن يضغط «ابدأ»
        // مرّتين لا يُنشئ استراحتين، ومن يضغط «أنهِ» بلا بدايةٍ لا يُنشئ
        // حدثاً معلَّقاً يقتطع من ورديّةٍ لاحقة.
        $last = \App\Models\Agent\AgentWorkEvent::where('staff_id', $staff->id)
            ->where('shift_id', $shift->id)
            ->whereIn('event', [
                \App\Models\Agent\AgentWorkEvent::BREAK_START,
                \App\Models\Agent\AgentWorkEvent::BREAK_END,
            ])->orderByDesc('id')->first();

        $onBreak = $last && $last->event === \App\Models\Agent\AgentWorkEvent::BREAK_START;

        if ($start && $onBreak) {
            return $this->error('أنت في استراحةٍ بالفعل', 422);
        }

        if (!$start && !$onBreak) {
            return $this->error('لا استراحةَ جارية', 422);
        }

        $wt->record(
            $staff,
            $start ? \App\Models\Agent\AgentWorkEvent::BREAK_START
                   : \App\Models\Agent\AgentWorkEvent::BREAK_END,
            (int) $shift->id,
        );

        return $this->ok(
            ['on_break' => $start],
            $start ? 'بدأت استراحتُك — درجُك يبقى مفتوحاً ولا تُنفَّذ عمليّات'
                   : 'انتهت الاستراحة — أهلاً بعودتك',
        );
    }

    // ── ١٦) سجلّ تدقيق واجهة الشبّاك ───────────────────────────────────

    /**
     * تسجيلُ ما يفعله الصرّاف على الشاشة — لا ما يُحرّك مالاً وحده.
     *
     * ══════════════════════════════════════════════════════════════════
     * **لماذا يُسجَّل «بحثٌ عن عميل» أصلاً؟**
     *
     * لأنّ أخطر ما يفعله موظّفٌ فاسد لا يُحرّك ريالاً: يبحث عن أرقام
     * الناس ليعرف من يملك ماذا. وحركةُ المال مُسجَّلةٌ منذ البداية، أمّا
     * **النظر** فلم يكن يُسجَّل — فمن يفتح خمسين ملفّاً في ساعةٍ ولا
     * ينفّذ عمليّةً واحدة لا يترك أثراً.
     *
     * ولا يُقبل من المتصفّح إلّا **نوعُ الحدث**: لو قُبل نصٌّ حرّ لصار
     * سجلّ التدقيق قابلاً للتلويث بما يكتبه من يُدقَّق عليه.
     */
    public function logEvent(Request $request): JsonResponse
    {
        $request->validate([
            'event' => 'required|string|max:40',
            'customer_id' => 'nullable|integer',
            'reference' => 'nullable|string|max:60',
        ]);

        $allowed = [
            'counter_opened' => 'فتح شاشة الشبّاك',
            'customer_searched' => 'بحث عن عميل',
            'customer_viewed' => 'عرض بطاقة عميل',
            'receipt_printed' => 'طباعة إيصال',
            'statement_viewed' => 'عرض كشف ورديّة',
            'risk_flag_shown' => 'ظهرت إشارة خطر',
            'risk_flag_overridden' => 'تابع رغم إشارة الخطر',
        ];

        $event = (string) $request->input('event');

        if (!isset($allowed[$event])) {
            return $this->error('حدث غير معروف', 422);
        }

        $staff = $this->actor($request);

        $this->audit->record([
            'actor_type' => 'agent',
            'actor_user_id' => $staff->agent_user_id,
            'action' => 'agent.teller.' . $event,
            // «تابع رغم الإشارة» هو السطر الذي يُبحث عنه في كلّ تحقيق.
            'severity' => $event === 'risk_flag_overridden' ? 'high' : 'low',
            'subject_type' => $request->input('customer_id') ? 'user' : 'agent_staff',
            'subject_id' => (string) ($request->input('customer_id') ?: $staff->id),
            'metadata' => [
                'staff_id' => $staff->id,
                'branch_id' => $staff->branch_id,
                'label' => $allowed[$event],
                'reference' => $request->input('reference'),
            ],
        ]);

        return $this->ok([]);
    }

    // ══════════════════════════════════════════════════════════════════

    private function actor(Request $request): AgentStaff
    {
        $s = $request->attributes->get('agent_staff');

        if (!$s instanceof AgentStaff) {
            $user = \Illuminate\Support\Facades\Auth::guard('user')->user();

            abort_unless($user, 401);

            return app(AgentStaffService::class)->ensureHeadOfficeAccount($user);
        }

        return $s;
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
