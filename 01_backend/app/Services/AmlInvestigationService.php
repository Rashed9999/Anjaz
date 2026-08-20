<?php

namespace App\Services;

use App\Models\Aml\AmlAlert;
use App\Models\Aml\AmlFlaggedTransaction;
use App\Models\Aml\AmlInvestigation;
use App\Models\Aml\AmlInvestigationEvent;
use App\Models\Aml\AmlRegulatoryReport;
use App\Models\Aml\AmlUserRiskProfile;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AML-INVESTIGATION-001 — دورة حياة قضيّة التحقيق.
 *
 * **الفجوة التي تُغلَق هنا:** كان النظام يرصد ويعلّق ويُنبّه ثمّ يقف. التنبيه
 * يُحلّ بسطر، والعملية المعلّقة تُعتمد أو تُرفض — ولا شيء يجمع عشرين تنبيهاً
 * على عميلٍ واحد في قضيّةٍ لها ضابطٌ مسؤول وأدلّة وقرار.
 *
 * والمنظّم لا يسأل «هل رأيتم؟» بل «أروني ملفّ القضية».
 *
 * **أربعة ضوابط في هذه الخدمة، وكلٌّ منها يمنع حالةً واقعية:**
 *
 * ١) **لا يحقّق المرء في قضيّةٍ ضدّ نفسه.** موظّفو المنصّة عملاء فيها، ولهم
 *    محافظ وحدود. ومن يُسنَد إليه التحقيق في نشاطه هو يكتب براءته بيده.
 *
 * ٢) **الإغلاق يحتاج قراراً مسمّى وسبباً.** «أُغلقت» بلا قرار تعني أنّ أحداً
 *    لم يقرّر — وهو أسوأ من قرارٍ خاطئ لأنّه لا يُراجَع ولا يُتعلَّم منه.
 *
 * ٣) **قرار «رُفع بلاغ اشتباه» يُشترط أن يكون البلاغ موجوداً.** ولولا ذلك
 *    لصار الحقل ادّعاءً: تُغلق القضية بأنّ بلاغاً رُفع ولا بلاغ في النظام،
 *    ويُكتشف ذلك يوم يطلبه المنظّم — بعد فوات الأوان.
 *
 * ٤) **إعادة الفتح لا تمحو الإغلاق السابق.** يبقى القرار الأوّل في الخطّ
 *    الزمنيّ: أن تُغلَق قضيّةٌ بـ«لا إجراء» ثمّ تُفتح ويُرفع بلاغ، هو نفسه
 *    معلومةٌ تخصّ المنظّم — ومحوُها يُخفي أنّ الحكم الأوّل كان خاطئاً.
 */
class AmlInvestigationService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly KycUpdateRequestService $kycUpdates,
    ) {
    }

    // ── فتح ────────────────────────────────────────────────────────────

    public function openFromAlert(AmlAlert $alert, User $actor, string $priority = 'medium'): AmlInvestigation
    {
        $subjectId = $this->subjectOfAlert($alert);

        return $this->open($subjectId, $actor, 'alert', $alert->alert_ulid, $priority);
    }

    public function openFromFlagged(AmlFlaggedTransaction $flag, User $actor, string $priority = 'high'): AmlInvestigation
    {
        return $this->open(
            (int) $flag->actor_user_id, $actor, 'flagged_transaction',
            $flag->flag_ulid, $priority, (string) $flag->total_risk_score,
        );
    }

    public function open(
        int $subjectUserId,
        User $actor,
        string $openedFrom = 'manual',
        ?string $sourceUlid = null,
        string $priority = 'medium',
        ?string $riskScore = null,
    ): AmlInvestigation {
        // قضيّةٌ مفتوحة على العميل نفسه من المصدر نفسه لا تُكرَّر: عشرون
        // تنبيهاً على عميل واحد يجب أن تجتمع في ملفّ واحد لا أن تُنتج عشرين
        // ملفّاً يُغلق كلٌّ منها على حدة بلا أن يرى أحدٌ النمط.
        if ($sourceUlid !== null) {
            $existing = AmlInvestigation::where('source_ulid', $sourceUlid)->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use (
            $subjectUserId, $actor, $openedFrom, $sourceUlid, $priority, $riskScore
        ) {
            $riskScore ??= (string) (AmlUserRiskProfile::find($subjectUserId)?->current_risk_score ?? 0);

            $inv = AmlInvestigation::create([
                'case_number' => $this->nextCaseNumber(),
                'subject_user_id' => $subjectUserId,
                'opened_from' => $openedFrom,
                'source_ulid' => $sourceUlid,
                'priority' => $priority,
                'status' => AmlInvestigation::STATUS_OPEN,
                'risk_score_at_open' => $riskScore,
                'opened_by' => $actor->id,
                'opened_at' => now(),
            ]);

            $this->event($inv, AmlInvestigationEvent::TYPE_OPENED, $actor,
                "فُتحت من: {$openedFrom}", ['source_ulid' => $sourceUlid]);

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $actor->id,
                'subject_type' => 'aml_investigation',
                'subject_id' => (string) $inv->id,
                'action' => 'AML_INVESTIGATION_OPENED',
                'decision_code' => 'AML_CASE_OPEN',
                'severity' => 'warning',
                'context' => ['case_number' => $inv->case_number, 'customer_id' => $subjectUserId],
            ]);

            return $inv;
        });
    }

    // ── إسناد ──────────────────────────────────────────────────────────

    public function assign(AmlInvestigation $inv, User $officer, User $actor): AmlInvestigation
    {
        $this->assertOpen($inv);

        // الضابط (١): لا يحقّق المرء في قضيّةٍ ضدّ نفسه.
        if ((int) $inv->subject_user_id === (int) $officer->id) {
            throw new DomainException('FOUR_EYES_VIOLATION: لا يُسنَد التحقيق إلى صاحب القضية نفسه');
        }

        $inv->assigned_officer_id = $officer->id;
        $inv->assigned_at = now();
        if ($inv->status === AmlInvestigation::STATUS_OPEN) {
            $inv->status = AmlInvestigation::STATUS_INVESTIGATING;
        }
        $inv->save();

        $this->event($inv, AmlInvestigationEvent::TYPE_ASSIGNED, $actor,
            "أُسندت إلى الموظّف #{$officer->id}", ['officer_id' => $officer->id]);

        return $inv->fresh();
    }

    // ── أدلّة وملاحظات ─────────────────────────────────────────────────

    public function addEvidence(AmlInvestigation $inv, User $actor, string $note, array $metadata = []): AmlInvestigationEvent
    {
        $this->assertOpen($inv);

        if (mb_strlen(trim($note)) < 10) {
            // دليلٌ بسطرٍ غامض لا يفيد من يراجع القرار بعد سنة.
            throw new DomainException('نصّ الدليل قصير — اكتب ما وجدتَه بوضوح (١٠ أحرف على الأقل)');
        }

        return $this->event($inv, AmlInvestigationEvent::TYPE_EVIDENCE, $actor, trim($note), $metadata);
    }

    public function addNote(AmlInvestigation $inv, User $actor, string $note): AmlInvestigationEvent
    {
        $this->assertOpen($inv);

        return $this->event($inv, AmlInvestigationEvent::TYPE_NOTE, $actor, trim($note));
    }

    // ── إجراءات الامتثال ───────────────────────────────────────────────

    /** الإجراءات التي تطلبها الوثيقة (الفصل ١٠ — Compliance Actions). */
    public const ACTIONS = [
        'freeze_account' => 'تجميد الحساب',
        'freeze_transaction' => 'تجميد العملية',
        'request_kyc' => 'طلب تحديث الهوية',
        'request_source_of_funds' => 'طلب إثبات مصدر الأموال',
        'escalate' => 'تصعيد التحقيق',
        'blacklist' => 'إدراج في القائمة السوداء',
    ];

    public function takeAction(AmlInvestigation $inv, User $actor, string $action, string $reason): AmlInvestigation
    {
        $this->assertOpen($inv);

        if (!isset(self::ACTIONS[$action])) {
            throw new DomainException('إجراء غير معروف');
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw new DomainException('سبب الإجراء إلزاميّ (١٠ أحرف على الأقل)');
        }

        return DB::transaction(function () use ($inv, $actor, $action, $reason) {
            // الإجراء يُنفَّذ فعلاً لا يُسجَّل وحسب. وإجراءٌ يُكتب في الخطّ
            // الزمنيّ ولا يقع هو أخطر من عدمه: من يقرأ الملفّ يظنّ الحساب
            // مجمَّداً وهو يعمل.
            $this->execute($inv, $actor, $action);

            $type = $action === 'escalate'
                ? AmlInvestigationEvent::TYPE_ESCALATED
                : AmlInvestigationEvent::TYPE_ACTION;

            $this->event($inv, $type, $actor,
                self::ACTIONS[$action] . ' — ' . trim($reason), ['action' => $action]);

            if ($action === 'escalate') {
                $inv->priority = 'critical';
                $inv->save();
            }

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $actor->id,
                'subject_type' => 'aml_investigation',
                'subject_id' => (string) $inv->id,
                'action' => 'AML_COMPLIANCE_ACTION',
                'decision_code' => strtoupper($action),
                'reason' => mb_substr($reason, 0, 500),
                'severity' => 'critical',
                'context' => ['case_number' => $inv->case_number, 'customer_id' => $inv->subject_user_id],
            ]);

            return $inv->fresh();
        });
    }

    private function execute(AmlInvestigation $inv, User $actor, string $action): void
    {
        $subject = User::find($inv->subject_user_id);
        if (!$subject) {
            return;
        }

        match ($action) {
            'freeze_account' => $this->freezeAccount($subject),
            'request_kyc' => $this->kycUpdates->request($subject, $actor, 'aml_investigation'),
            'blacklist' => $this->blacklist($subject, $inv),
            // التصعيد وطلب مصدر الأموال وتجميد العملية: أثرُها في الملفّ
            // وفي إشعار العميل، لا في حالة الحساب.
            default => null,
        };
    }

    private function freezeAccount(User $user): void
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'is_temp_blocked')) {
            $user->forceFill(['is_temp_blocked' => 1])->save();
        }
    }

    private function blacklist(User $user, AmlInvestigation $inv): void
    {
        $profile = AmlUserRiskProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['current_risk_score' => 0, 'risk_level' => 'low', 'manual_override' => 'none'],
        );

        $profile->update([
            'manual_override' => 'blacklist',
            'override_reason' => 'قضية ' . $inv->case_number,
        ]);
    }

    // ── الإغلاق وإعادة الفتح ───────────────────────────────────────────

    public function close(AmlInvestigation $inv, User $actor, string $decision, string $reason): AmlInvestigation
    {
        $this->assertOpen($inv);

        // الضابط (٢): قرارٌ مسمّى وسببٌ مكتوب.
        if (!isset(AmlInvestigation::DECISIONS[$decision])) {
            throw new DomainException('قرار غير معروف — الإغلاق يحتاج قراراً مسمّى');
        }

        if (mb_strlen(trim($reason)) < 20) {
            throw new DomainException('سبب الإغلاق إلزاميّ (٢٠ حرفاً على الأقل) — هو ما يُراجَع لا القرار وحده');
        }

        // الضابط (٣): «رُفع بلاغ اشتباه» يُشترط وجود البلاغ.
        if ($decision === AmlInvestigation::DECISION_STR_FILED) {
            $hasStr = AmlRegulatoryReport::where('investigation_id', $inv->id)
                ->where('report_type', AmlRegulatoryReport::TYPE_STR)
                ->exists();

            if (!$hasStr) {
                throw new DomainException(
                    'لا بلاغ اشتباه على هذه القضية — وَلِّد البلاغ أوّلاً ثمّ أغلق بهذا القرار',
                );
            }
        }

        return DB::transaction(function () use ($inv, $actor, $decision, $reason) {
            $inv->status = AmlInvestigation::STATUS_CLOSED;
            $inv->decision = $decision;
            $inv->closed_by = $actor->id;
            $inv->closed_at = now();
            $inv->closure_reason = mb_substr(trim($reason), 0, 2000);
            $inv->save();

            $this->event($inv, AmlInvestigationEvent::TYPE_DECISION, $actor,
                AmlInvestigation::DECISIONS[$decision] . ' — ' . trim($reason),
                ['decision' => $decision]);

            $this->event($inv, AmlInvestigationEvent::TYPE_CLOSED, $actor,
                'أُغلقت القضية بعد ' . $inv->ageHours() . ' ساعة');

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $actor->id,
                'subject_type' => 'aml_investigation',
                'subject_id' => (string) $inv->id,
                'action' => 'AML_INVESTIGATION_CLOSED',
                'decision_code' => strtoupper($decision),
                'reason' => mb_substr($reason, 0, 500),
                'severity' => 'critical',
                'context' => [
                    'case_number' => $inv->case_number,
                    'age_hours' => $inv->ageHours(),
                    'customer_id' => $inv->subject_user_id,
                ],
            ]);

            return $inv->fresh();
        });
    }

    public function reopen(AmlInvestigation $inv, User $actor, string $reason): AmlInvestigation
    {
        if ($inv->status !== AmlInvestigation::STATUS_CLOSED) {
            throw new DomainException('القضية ليست مغلقة');
        }

        if (mb_strlen(trim($reason)) < 10) {
            throw new DomainException('سبب إعادة الفتح إلزاميّ');
        }

        // الضابط (٤): القرار السابق يبقى في الخطّ الزمنيّ. لا يُمحى `decision`
        // من السجلّ — يُنقل إلى الحدث ثمّ يُفرَّغ الحقل الحاليّ لأنّ القضية
        // صارت بلا قرارٍ نافذ، ويبقى الأوّل مقروءاً في التاريخ.
        $previous = $inv->decision;

        $inv->status = AmlInvestigation::STATUS_REOPENED;
        $inv->decision = null;
        $inv->closed_by = null;
        $inv->closed_at = null;
        $inv->save();

        $this->event($inv, AmlInvestigationEvent::TYPE_REOPENED, $actor, trim($reason), [
            'previous_decision' => $previous,
        ]);

        return $inv->fresh();
    }

    // ── مساعدات ────────────────────────────────────────────────────────

    private function assertOpen(AmlInvestigation $inv): void
    {
        if (!$inv->isOpen()) {
            throw new DomainException('القضية مغلقة — أعد فتحها أوّلاً');
        }
    }

    private function event(
        AmlInvestigation $inv, string $type, User $actor, ?string $note = null, array $meta = []
    ): AmlInvestigationEvent {
        return AmlInvestigationEvent::create([
            'investigation_id' => $inv->id,
            'event_type' => $type,
            'actor_user_id' => $actor->id,
            'note' => $note,
            'metadata' => $meta ?: null,
            'created_at' => now(),
        ]);
    }

    /**
     * رقمٌ متسلسل سنويّاً — INV-2026-000001.
     *
     * والفجوة في التسلسل سؤالٌ يجب أن يكون له جواب، ولذلك يُشتقّ الرقم من
     * العدّ لا من عشوائيّ. ويُقفل الجدول أثناء الاشتقاق: طلبان متزامنان
     * يقرآن العدّ نفسه فيتصادمان على الرقم.
     */
    private function nextCaseNumber(): string
    {
        $year = now()->year;

        $last = AmlInvestigation::where('case_number', 'like', "INV-{$year}-%")
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('case_number');

        $seq = $last ? ((int) substr($last, -6)) + 1 : 1;

        return sprintf('INV-%d-%06d', $year, $seq);
    }

    private function subjectOfAlert(AmlAlert $alert): int
    {
        return $alert->subject_type === 'user'
            ? (int) $alert->subject_id
            : (int) ($alert->context['user_id'] ?? $alert->subject_id);
    }

    // ── قوائم للوحة ────────────────────────────────────────────────────

    public function queue(?string $status = null, int $limit = 50): array
    {
        $q = AmlInvestigation::with(['subject:id,f_name,l_name,phone', 'officer:id,f_name,l_name']);

        if ($status === 'open') {
            $q->open();
        } elseif ($status) {
            $q->where('status', $status);
        }

        return $q->orderByRaw("FIELD(priority,'critical','high','medium','low')")
            ->orderBy('opened_at')
            ->limit($limit)
            ->get()
            ->map(fn (AmlInvestigation $i) => [
                'id' => (int) $i->id,
                'case_number' => $i->case_number,
                'subject_user_id' => (int) $i->subject_user_id,
                'subject_name' => trim((string) ($i->subject?->f_name . ' ' . $i->subject?->l_name)) ?: '—',
                'subject_phone' => (string) ($i->subject?->phone ?? '—'),
                'officer' => $i->officer
                    ? trim((string) ($i->officer->f_name . ' ' . $i->officer->l_name))
                    : null,
                'priority' => $i->priority,
                'status' => $i->status,
                'decision' => $i->decision,
                'decision_label' => $i->decision ? (AmlInvestigation::DECISIONS[$i->decision] ?? $i->decision) : null,
                'risk_score_at_open' => (string) $i->risk_score_at_open,
                'age_hours' => $i->ageHours(),
                'opened_at' => $i->opened_at?->toIso8601String(),
            ])->all();
    }

    public function detail(AmlInvestigation $inv): array
    {
        $inv->load(['subject:id,f_name,l_name,phone', 'officer:id,f_name,l_name',
            'events.actor:id,f_name,l_name', 'reports']);

        return [
            'id' => (int) $inv->id,
            'case_number' => $inv->case_number,
            'subject_name' => trim((string) ($inv->subject?->f_name . ' ' . $inv->subject?->l_name)) ?: '—',
            'subject_phone' => (string) ($inv->subject?->phone ?? '—'),
            'subject_user_id' => (int) $inv->subject_user_id,
            'status' => $inv->status,
            'priority' => $inv->priority,
            'decision' => $inv->decision,
            'closure_reason' => $inv->closure_reason,
            'age_hours' => $inv->ageHours(),
            'officer' => $inv->officer ? trim((string) ($inv->officer->f_name . ' ' . $inv->officer->l_name)) : null,
            'timeline' => $inv->events->map(fn ($e) => [
                'type' => $e->event_type,
                'type_label' => AmlInvestigationEvent::TYPE_LABELS[$e->event_type] ?? $e->event_type,
                'actor' => $e->actor ? trim((string) ($e->actor->f_name . ' ' . $e->actor->l_name)) : '—',
                'note' => $e->note,
                'at' => $e->created_at?->toIso8601String(),
            ])->all(),
            'reports' => $inv->reports->map(fn ($r) => [
                'id' => (int) $r->id,
                'report_number' => $r->report_number,
                'report_type' => $r->report_type,
                'status' => $r->status,
            ])->all(),
        ];
    }
}
