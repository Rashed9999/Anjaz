<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentPanicAlert;
use App\Models\Agent\AgentStaff;
use App\Models\Agent\AgentTellerRequest;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-TELLER-WS-001 — طلباتُ الموافقة وبلاغاتُ الطوارئ.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **الرفض ليس علاجاً — هو دفعٌ للعطل إلى مكانٍ لا يُرى.**
 *
 * صرّافٌ حدُّه نصف مليون وأمامه عميلٌ يريد مليوناً، وليس أمامه إلّا
 * «مرفوض»: إمّا أن ينصرف العميل إلى منافس، وإمّا أن يقسّمها الصرّاف
 * عمليّتين. والثانية أسوأ: **هي بالضبط النمط الذي بُنيت قواعد غسل
 * الأموال لرصده** — فيصير الحدُّ الذي وُضع للحماية سبباً في إنتاج
 * السلوك الذي يُدان به.
 *
 * فالطريق الثالث: يطلب باسمه، ويقرّر مديرُه، ويُسجَّل القرار بصاحبه.
 */
class AgentTellerRequestService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * طلبُ تجاوزٍ من صرّاف.
     *
     * @param array<string, mixed> $data
     */
    public function submit(AgentStaff $staff, array $data): AgentTellerRequest
    {
        $amount = bcadd((string) ($data['amount'] ?? '0'), '0', 4);
        $reason = trim((string) ($data['reason'] ?? ''));

        // طلبُ الوقت الإضافيّ ليس مبلغاً — يُقاس بالدقائق لا بالريالات.
        $isOvertime = ($data['kind'] ?? '') === 'overtime';

        if (!$isOvertime && bccomp($amount, '0', 4) <= 0) {
            throw new DomainException('المبلغ لا يكون صفراً');
        }

        // **السبب إلزاميّ وطويل.** مديرٌ يوافق على «تجاوز» بلا سبب يوقّع
        // على مليونٍ لا يعرف لماذا — وهو أوّل ما يُسأل عنه في التحقيق.
        if (mb_strlen($reason) < 10) {
            throw new DomainException('اكتب سبب الطلب — عشرة أحرف فأكثر');
        }

        if (!$staff->branch_id) {
            throw new DomainException('حسابك بلا فرع — راجع إدارة شركتك');
        }

        // طلبٌ معلَّقٌ واحدٌ في الوقت الواحد: عشرةُ طلباتٍ مفتوحة تجعل
        // المدير يوافق على الكوم بلا قراءة.
        $open = AgentTellerRequest::where('staff_id', $staff->id)
            ->where('status', AgentTellerRequest::STATUS_PENDING)->count();

        if ($open >= 3) {
            throw new DomainException('لديك ثلاثة طلباتٍ معلّقة — انتظر قرار مديرك');
        }

        $shift = $staff->openShift();

        return DB::transaction(function () use ($staff, $data, $amount, $reason, $shift) {
            $row = AgentTellerRequest::create([
                'request_number' => 'TRQ-' . strtoupper(Str::random(10)),
                'agent_user_id' => $staff->agent_user_id,
                'branch_id' => $staff->branch_id,
                'staff_id' => $staff->id,
                'shift_id' => $shift?->id,
                'kind' => in_array(($data['kind'] ?? ''), ['over_limit', 'restricted_op', 'overtime'], true)
                    ? $data['kind'] : 'over_limit',
                'operation' => in_array(($data['operation'] ?? ''), ['deposit', 'withdraw'], true)
                    ? $data['operation'] : 'deposit',
                'amount' => $amount,
                'customer_user_id' => $data['customer_user_id'] ?? null,
                'reason' => $reason,
                // **لقطةُ الحدّ تُحفظ لحظةَ الطلب.** الحدود تتغيّر، ومن
                // يقرأ الطلب بعد شهرٍ يجب أن يرى ما مُنع فعلاً لا ما
                // يُحسب اليوم.
                'limit_snapshot' => [
                    'per_operation' => (string) $staff->max_txn_amount,
                    'daily' => (string) $staff->daily_limit,
                    'daily_count' => (int) $staff->daily_count_limit,
                    // للوقت الإضافيّ: **اليوم يُختم من الخادم لا من الطلب**.
                    // ولو قُبل من المتصفّح لصار من يطلب موافقةً اليوم
                    // يُقرّها على أيّ يومٍ في الشهر.
                    'date' => in_array(($data['kind'] ?? ''), ['overtime'], true)
                        ? now()->toDateString() : null,
                    'expected_daily_hours' => (float) $staff->daily_hours_expected,
                ],
                'status' => AgentTellerRequest::STATUS_PENDING,
                // مهلةٌ قصيرة: العميل واقفٌ على الشبّاك، وموافقةٌ تصل بعد
                // ساعتين لا تنفع أحداً — وتبقى قابلةً للاستعمال في غفلة.
                'expires_at' => now()->addMinutes(30),
            ]);

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $staff->agent_user_id,
                'action' => 'agent.teller_request.submit',
                'subject_type' => 'agent_teller_request',
                'subject_id' => $row->request_number,
                'metadata' => [
                    'staff_id' => $staff->id, 'branch_id' => $staff->branch_id,
                    'amount' => $amount, 'kind' => $row->kind,
                ],
            ]);

            return $row;
        });
    }

    /** ما ينتظر قرار هذا المدير. */
    public function pendingFor(AgentStaff $manager): array
    {
        if ($manager->isTeller()) {
            return [];
        }

        // انقضاءُ المهلة يُطبَّق عند القراءة: لا مهمّةَ مجدولة لهذا،
        // وطلبٌ منتهٍ يظهر «معلّقاً» يجعل المدير يوافق على ما فات وقته.
        AgentTellerRequest::where('status', AgentTellerRequest::STATUS_PENDING)
            ->where('expires_at', '<', now())
            ->update(['status' => AgentTellerRequest::STATUS_EXPIRED]);

        return AgentTellerRequest::with('staff')
            ->whereIn('branch_id', $manager->visibleBranchIds() ?: [0])
            ->where('status', AgentTellerRequest::STATUS_PENDING)
            ->orderByDesc('id')->limit(50)->get()
            ->map(fn (AgentTellerRequest $r) => [
                'id' => (int) $r->id,
                'number' => $r->request_number,
                'staff' => $r->staff?->name,
                'staff_username' => $r->staff?->username,
                'kind_label' => AgentTellerRequest::KIND_LABELS[$r->kind] ?? $r->kind,
                'operation' => $r->operation === 'deposit' ? 'إيداع' : 'سحب',
                'amount' => (string) $r->amount,
                'reason' => $r->reason,
                'limit_snapshot' => $r->limit_snapshot,
                'expires_at' => $r->expires_at?->toDateTimeString(),
                'at' => $r->created_at?->toDateTimeString(),
            ])->all();
    }

    /** قرارُ المدير — قبولاً أو رفضاً، وكلاهما بسبب. */
    public function decide(AgentStaff $manager, int $id, bool $approve, string $note = ''): AgentTellerRequest
    {
        if ($manager->isTeller()) {
            throw new DomainException('القرار من صلاحية المدير');
        }

        $row = AgentTellerRequest::find($id);

        if (!$row || !in_array((int) $row->branch_id, $manager->visibleBranchIds(), true)) {
            throw new DomainException('الطلب خارج نطاقك');
        }

        if ($row->status !== AgentTellerRequest::STATUS_PENDING) {
            throw new DomainException('هذا الطلب حُسم بالفعل');
        }

        if ($row->expires_at && $row->expires_at->isPast()) {
            $row->update(['status' => AgentTellerRequest::STATUS_EXPIRED]);
            throw new DomainException('انقضت مهلة الطلب — يطلب الصرّاف من جديد');
        }

        // **لا يوافق أحدٌ على طلب نفسه.** ومديرُ فرعٍ يفتح ورديّةً لا
        // يصير صرّافاً، لكنّ الحارس يُكتب لأنّ الأدوار تتغيّر.
        if ((int) $row->staff_id === (int) $manager->id) {
            throw new DomainException('لا تُوافق على طلبك — يقرّره غيرك');
        }

        if (!$approve && mb_strlen(trim($note)) < 10) {
            throw new DomainException('سببُ الرفض إلزاميّ — عشرة أحرف فأكثر');
        }

        $row->update([
            'status' => $approve ? AgentTellerRequest::STATUS_APPROVED : AgentTellerRequest::STATUS_REJECTED,
            'decided_by_staff_id' => $manager->id,
            'decision_note' => $note ?: null,
            'decided_at' => now(),
        ]);

        $this->audit->record([
            'actor_type' => 'agent',
            'actor_user_id' => $manager->agent_user_id,
            'action' => 'agent.teller_request.' . ($approve ? 'approve' : 'reject'),
            'severity' => 'high',
            'subject_type' => 'agent_teller_request',
            'subject_id' => $row->request_number,
            'metadata' => [
                'manager_staff_id' => $manager->id, 'teller_staff_id' => $row->staff_id,
                'amount' => (string) $row->amount, 'note' => $note,
            ],
        ]);

        return $row->fresh();
    }

    /**
     * موافقةٌ صالحةٌ لهذا الصرّاف بهذا المبلغ — تُستهلك عند الاستعمال.
     *
     * **ومرّةً واحدة.** موافقةٌ تُستعمل مرّتين تعني أنّ مديراً وافق على
     * مليونٍ فمرّ مليونان باسمه.
     */
    public function consumeFor(AgentStaff $staff, string $operation, string $amount): ?AgentTellerRequest
    {
        $row = AgentTellerRequest::where('staff_id', $staff->id)
            ->where('operation', $operation)
            ->where('status', AgentTellerRequest::STATUS_APPROVED)
            ->whereNull('used_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            // موافقةٌ على مليونٍ تُغطّي ثمانمئة ألف، ولا تُغطّي مليوناً ومئة.
            ->whereRaw('amount >= ?', [$amount])
            ->orderBy('id')->first();

        if (!$row) {
            return null;
        }

        $row->update(['status' => AgentTellerRequest::STATUS_USED, 'used_at' => now()]);

        return $row;
    }

    // ══════════════════════════════════════════════════════════════════
    // ١٣) زرّ الطوارئ
    // ══════════════════════════════════════════════════════════════════

    /**
     * بلاغُ طوارئ من الشبّاك.
     *
     * **ولا يُشترط أن ينجح شيء.** موظّفٌ تحت تهديدٍ لا ينتظر تحميل صفحة،
     * ولا يُطلب منه إذنُ موقعٍ ولا كتابةُ سبب. يُسجَّل ما وصل، ويُقال
     * صراحةً ما لم يصل — والموقعُ يغيب على اتّصالٍ غير مشفَّر لأنّ
     * المتصفّح يمنعه، لا لأنّ الموظّف أخفاه.
     *
     * @param array<string, mixed> $data
     */
    public function panic(AgentStaff $staff, array $data): AgentPanicAlert
    {
        $branch = $staff->branch_id ? AgentBranch::find($staff->branch_id) : null;
        $shift = $staff->openShift();

        $geo = in_array(($data['geo_state'] ?? ''), ['ok', 'denied', 'unavailable', 'insecure'], true)
            ? $data['geo_state'] : 'unavailable';

        $alert = AgentPanicAlert::create([
            'alert_number' => 'PNC-' . strtoupper(Str::random(10)),
            'agent_user_id' => $staff->agent_user_id,
            'branch_id' => $staff->branch_id ?: 0,
            'staff_id' => $staff->id,
            'shift_id' => $shift?->id,
            'kind' => in_array(($data['kind'] ?? ''), array_keys(AgentPanicAlert::KIND_LABELS), true)
                ? $data['kind'] : 'duress',
            'note' => $data['note'] ?? null,
            'lat' => $geo === 'ok' ? ($data['lat'] ?? null) : null,
            'lng' => $geo === 'ok' ? ($data['lng'] ?? null) : null,
            'geo_state' => $geo,
            'ip' => request()->ip(),
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
            'status' => 'open',
        ]);

        $this->audit->record([
            'actor_type' => 'agent',
            'actor_user_id' => $staff->agent_user_id,
            'action' => 'agent.panic',
            'severity' => 'critical',
            'subject_type' => 'agent_panic_alert',
            'subject_id' => $alert->alert_number,
            'metadata' => [
                'staff_id' => $staff->id, 'branch_id' => $staff->branch_id,
                'kind' => $alert->kind, 'geo_state' => $geo,
            ],
        ]);

        // **التنبيه بعد التسجيل لا قبله، وفشلُه لا يُسقط البلاغ.**
        // بلاغُ إكراهٍ يضيع لأنّ مزوّد واتساب متوقّف هو أسوأ ما يمكن
        // أن يقع في هذه الميزة.
        try {
            app(\App\Services\Whatsapp\AgentAlertService::class)
                ->panicRaised($alert, $staff, $branch);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::critical(
                'AgentPanic: تعذّر إرسال تنبيه بلاغ طوارئ',
                ['alert' => $alert->alert_number, 'error' => $e->getMessage()],
            );
        }

        return $alert;
    }
}
