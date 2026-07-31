<?php

namespace App\Services;

use App\Models\Aml\AmlInvestigation;
use App\Models\Aml\AmlRegulatoryReport;
use App\Models\Aml\AmlUserRiskProfile;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AML-REGREPORT-001 — بلاغا الاشتباه والعملة.
 *
 * **لماذا هذا أهمّ بندٍ تنظيميّ في فصل مكافحة غسل الأموال:** الرصدُ واجبٌ
 * داخليّ، والإبلاغُ واجبٌ قانونيّ. ومنصّةٌ ترصد ولا تُبلّغ تكون قد جمعت
 * الدليل على المخالفة واحتفظت به لنفسها — وهو أمام المنظّم أسوأ من ألّا
 * ترصد أصلاً.
 *
 * **والبلاغان مختلفان في طبيعتهما، والتصميم يتبع ذلك:**
 *
 *   • **STR** تقديريّ: يُرفع حين يقتنع الضابط بعد تحقيق. فيُشترط أن يكون
 *     له تحقيقٌ خلفه — وإلّا صار بلاغاً بلا أساس، والبلاغات بلا أساس تُفقد
 *     المنظّمَ الثقةَ في بلاغاتنا الحقيقية.
 *
 *   • **CTR** غير تقديريّ: كلّ عملية فوق الحدّ تُبلَّغ، مشبوهةً كانت أو لا.
 *     ولذلك **لا يوجد في هذه الخدمة مسارٌ لإلغاء بلاغ عملة**. وإتاحةُ
 *     الإلغاء هي بالضبط ما يجعل الحدّ بلا معنى: يصير الإبلاغ رأياً بعد أن
 *     جعله القانون قاعدة.
 *
 * **والمُرسَل لا يُعدَّل** — لدى المنظّم نسخة، وتعديل نسختنا يجعلهما
 * تختلفان. التصحيح ببلاغٍ جديد يشير إلى الأوّل (`supersedes_report_id`)،
 * كما يُصحَّح القيد المحاسبيّ بقيدٍ عكسيّ لا بمحو الأوّل.
 */
class AmlRegulatoryReportService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {
    }

    /** حدّ بلاغ العملة. يُقرأ من الإعداد لأنّ المنظّم يغيّره. */
    public function ctrThreshold(): string
    {
        return (string) config('amial.aml.ctr_threshold', '2000000');
    }

    // ── بلاغ الاشتباه ──────────────────────────────────────────────────

    public function generateStr(AmlInvestigation $inv, User $actor, string $narrative): AmlRegulatoryReport
    {
        // السردُ هو البلاغ. بلاغُ اشتباه بلا سردٍ يشرح **لماذا** اشتُبه لا
        // يقرؤه المنظّم إلّا كإشعارٍ فارغ، ويُعاد إلينا طلباً للتفصيل.
        if (mb_strlen(trim($narrative)) < 50) {
            throw new DomainException(
                'سرد البلاغ قصير — اشرح لماذا اشتُبه في هذا النشاط (٥٠ حرفاً على الأقل)',
            );
        }

        $existing = AmlRegulatoryReport::where('investigation_id', $inv->id)
            ->where('report_type', AmlRegulatoryReport::TYPE_STR)
            ->whereIn('status', [AmlRegulatoryReport::STATUS_DRAFT, AmlRegulatoryReport::STATUS_PENDING])
            ->first();

        if ($existing) {
            // بلاغان على قضيّةٍ واحدة يُربكان المنظّم: أيّهما النافذ؟
            throw new DomainException(
                "على هذه القضية بلاغ اشتباه غير مُرسَل بعد ({$existing->report_number})",
            );
        }

        return DB::transaction(function () use ($inv, $actor, $narrative) {
            $inv->load(['subject:id,f_name,l_name,phone', 'events']);
            $profile = AmlUserRiskProfile::find($inv->subject_user_id);

            $report = AmlRegulatoryReport::create([
                'report_number' => $this->nextNumber(AmlRegulatoryReport::TYPE_STR),
                'report_type' => AmlRegulatoryReport::TYPE_STR,
                'status' => AmlRegulatoryReport::STATUS_DRAFT,
                'subject_user_id' => $inv->subject_user_id,
                'investigation_id' => $inv->id,
                'amount' => '0',
                'period_start' => $inv->opened_at,
                'period_end' => now(),
                // المتن يُحفَظ كاملاً لا يُعاد بناؤه: إعادةُ البناء بعد سنة
                // تُنتج نصّاً من بياناتٍ تغيّرت فلا يطابق ما أُرسل.
                'content' => [
                    'case_number' => $inv->case_number,
                    'subject' => [
                        'user_id' => (int) $inv->subject_user_id,
                        'name' => trim((string) ($inv->subject?->f_name . ' ' . $inv->subject?->l_name)),
                        'phone' => (string) ($inv->subject?->phone ?? ''),
                    ],
                    'risk' => [
                        'score_at_open' => (string) $inv->risk_score_at_open,
                        'score_now' => (string) ($profile?->current_risk_score ?? '0'),
                        'level' => (string) ($profile?->risk_level ?? 'unknown'),
                    ],
                    'narrative' => trim($narrative),
                    'timeline' => $inv->events->map(fn ($e) => [
                        'at' => $e->created_at?->toIso8601String(),
                        'type' => $e->event_type,
                        'note' => $e->note,
                    ])->all(),
                    'generated_at' => now()->toIso8601String(),
                ],
                'generated_by' => $actor->id,
                'generated_at' => now(),
            ]);

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $actor->id,
                'subject_type' => 'aml_report',
                'subject_id' => (string) $report->id,
                'action' => 'AML_STR_GENERATED',
                'decision_code' => 'STR_GENERATED',
                'severity' => 'critical',
                'context' => ['report_number' => $report->report_number, 'case_number' => $inv->case_number],
            ]);

            return $report;
        });
    }

    // ── بلاغ العملة ────────────────────────────────────────────────────

    /**
     * بلاغ عملة عن عمليةٍ فوق الحدّ.
     *
     * **لا يقبل هذا المسار رأياً في وجوب الإبلاغ.** يُستدعى بالمبلغ، فإن
     * كان فوق الحدّ وُلِّد البلاغ. ومن يستدعيه بمبلغٍ تحت الحدّ يحصل على
     * استثناء لا على بلاغٍ اختياريّ: الإبلاغ فوق الحدّ قاعدةٌ، وتحت الحدّ
     * ليس بلاغَ عملة أصلاً.
     */
    public function generateCtr(
        int $subjectUserId,
        string $amount,
        User $actor,
        ?string $transactionUlid = null,
    ): AmlRegulatoryReport {
        if (bccomp($amount, $this->ctrThreshold(), 4) < 0) {
            throw new DomainException(
                'المبلغ تحت حدّ بلاغ العملة (' . $this->ctrThreshold() . ') — لا بلاغ عملة عليه',
            );
        }

        if ($transactionUlid !== null) {
            // العملية الواحدة تُبلَّغ مرّة. وبلاغان عن عمليةٍ واحدة يظهران
            // لدى المنظّم كعمليتين — فيُضخَّم الحجم المُبلَّغ بلا سبب.
            $dup = AmlRegulatoryReport::where('transaction_ulid', $transactionUlid)
                ->where('report_type', AmlRegulatoryReport::TYPE_CTR)
                ->first();

            if ($dup) {
                return $dup;
            }
        }

        return DB::transaction(function () use ($subjectUserId, $amount, $actor, $transactionUlid) {
            $user = User::find($subjectUserId);

            $report = AmlRegulatoryReport::create([
                'report_number' => $this->nextNumber(AmlRegulatoryReport::TYPE_CTR),
                'report_type' => AmlRegulatoryReport::TYPE_CTR,
                // بلاغ العملة يُولَّد جاهزاً للإرسال لا مسودّة: لا شيء فيه
                // يحتاج رأياً — المبلغ والطرف والتاريخ، وكلّها معروفة.
                'status' => AmlRegulatoryReport::STATUS_PENDING,
                'subject_user_id' => $subjectUserId,
                'transaction_ulid' => $transactionUlid,
                'amount' => $amount,
                'period_start' => now()->startOfDay(),
                'period_end' => now(),
                'content' => [
                    'subject' => [
                        'user_id' => $subjectUserId,
                        'name' => trim((string) ($user?->f_name . ' ' . $user?->l_name)),
                        'phone' => (string) ($user?->phone ?? ''),
                    ],
                    'amount' => $amount,
                    'threshold' => $this->ctrThreshold(),
                    'transaction_ulid' => $transactionUlid,
                    'generated_at' => now()->toIso8601String(),
                ],
                'generated_by' => $actor->id,
                'generated_at' => now(),
            ]);

            $this->audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $actor->id,
                'subject_type' => 'aml_report',
                'subject_id' => (string) $report->id,
                'action' => 'AML_CTR_GENERATED',
                'decision_code' => 'CTR_GENERATED',
                'severity' => 'critical',
                'context' => ['report_number' => $report->report_number, 'amount' => $amount],
            ]);

            return $report;
        });
    }

    // ── الإرسال ────────────────────────────────────────────────────────

    public function markReadyToSubmit(AmlRegulatoryReport $report, User $actor): AmlRegulatoryReport
    {
        $this->assertEditable($report);

        $report->status = AmlRegulatoryReport::STATUS_PENDING;
        $report->save();

        return $report->fresh();
    }

    public function markSubmitted(
        AmlRegulatoryReport $report, User $actor, string $externalReference, ?string $note = null
    ): AmlRegulatoryReport {
        $this->assertEditable($report);

        // مرجع الجهة المستقبِلة إلزاميّ: بلاغٌ «مُرسَل» بلا مرجعٍ منها ادّعاءٌ
        // لا إثبات — ويوم يُسأل «متى أبلغتم؟» لا يكون في اليد ما يُبرَز.
        if (mb_strlen(trim($externalReference)) < 3) {
            throw new DomainException('مرجع الجهة المستقبِلة إلزاميّ — بلاغ بلا مرجع لا يُثبَت');
        }

        // من ولّد البلاغ لا يؤكّد إرساله بنفسه.
        //
        // ليست شكليّة: تأكيدُ الإرسال هو ما يُغلق الواجب القانونيّ، ومن يملك
        // الخطوتين يستطيع أن يُنهي الواجب على الورق بلا أن يُرسل شيئاً.
        if ((int) $report->generated_by === (int) $actor->id) {
            throw new DomainException(
                'FOUR_EYES_VIOLATION: من ولّد البلاغ لا يؤكّد إرساله — يؤكّده موظّف آخر',
            );
        }

        $report->status = AmlRegulatoryReport::STATUS_SUBMITTED;
        $report->submitted_by = $actor->id;
        $report->submitted_at = now();
        $report->external_reference = mb_substr(trim($externalReference), 0, 120);
        $report->submission_note = $note;
        $report->save();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor->id,
            'subject_type' => 'aml_report',
            'subject_id' => (string) $report->id,
            'action' => 'AML_REPORT_SUBMITTED',
            'decision_code' => $report->report_type . '_SUBMITTED',
            'severity' => 'critical',
            'context' => [
                'report_number' => $report->report_number,
                'external_reference' => $report->external_reference,
            ],
        ]);

        return $report->fresh();
    }

    private function assertEditable(AmlRegulatoryReport $report): void
    {
        if ($report->isLocked()) {
            throw new DomainException(
                'البلاغ أُرسل — لدى الجهة نسخة منه. التصحيح ببلاغٍ جديد يشير إليه لا بتعديله',
            );
        }
    }

    // ── قوائم ووسائل ───────────────────────────────────────────────────

    public function listReports(?string $type = null, ?string $status = null, int $limit = 100): array
    {
        $q = AmlRegulatoryReport::with(['subject:id,f_name,l_name,phone', 'generator:id,f_name,l_name']);

        if ($type) {
            $q->where('report_type', $type);
        }
        if ($status) {
            $q->where('status', $status);
        }

        return $q->orderByDesc('id')->limit($limit)->get()
            ->map(fn (AmlRegulatoryReport $r) => [
                'id' => (int) $r->id,
                'report_number' => $r->report_number,
                'report_type' => $r->report_type,
                'type_label' => AmlRegulatoryReport::TYPE_LABELS[$r->report_type] ?? $r->report_type,
                'status' => $r->status,
                'status_label' => AmlRegulatoryReport::STATUS_LABELS[$r->status] ?? $r->status,
                'subject_name' => trim((string) ($r->subject?->f_name . ' ' . $r->subject?->l_name)) ?: '—',
                'subject_phone' => (string) ($r->subject?->phone ?? '—'),
                'amount' => (string) $r->amount,
                'generated_by' => $r->generator
                    ? trim((string) ($r->generator->f_name . ' ' . $r->generator->l_name)) : '—',
                'generated_at' => $r->generated_at?->toIso8601String(),
                'submitted_at' => $r->submitted_at?->toIso8601String(),
                'external_reference' => $r->external_reference,
                'age_hours' => (int) $r->generated_at?->diffInHours(now()),
            ])->all();
    }

    /**
     * ما لم يُرسَل بعد — وهو ما يُسأل عنه.
     *
     * البلاغ المتأخّر مخالفةٌ بذاته في أغلب الأنظمة، فالعدد وحده لا يكفي:
     * يُعرَض معه أقدمُ بلاغٍ لم يُرسل.
     */
    public function pendingSummary(): array
    {
        $pending = AmlRegulatoryReport::whereIn('status', [
            AmlRegulatoryReport::STATUS_DRAFT, AmlRegulatoryReport::STATUS_PENDING,
        ]);

        $oldest = (clone $pending)->orderBy('generated_at')->first();

        return [
            'pending_total' => (clone $pending)->count(),
            'pending_str' => (clone $pending)->where('report_type', AmlRegulatoryReport::TYPE_STR)->count(),
            'pending_ctr' => (clone $pending)->where('report_type', AmlRegulatoryReport::TYPE_CTR)->count(),
            'submitted_str' => AmlRegulatoryReport::where('report_type', AmlRegulatoryReport::TYPE_STR)
                ->where('status', AmlRegulatoryReport::STATUS_SUBMITTED)->count(),
            'submitted_ctr' => AmlRegulatoryReport::where('report_type', AmlRegulatoryReport::TYPE_CTR)
                ->where('status', AmlRegulatoryReport::STATUS_SUBMITTED)->count(),
            'oldest_pending_hours' => $oldest ? (int) $oldest->generated_at?->diffInHours(now()) : 0,
            'oldest_pending_number' => $oldest?->report_number,
        ];
    }

    private function nextNumber(string $type): string
    {
        $year = now()->year;

        $last = AmlRegulatoryReport::where('report_number', 'like', "{$type}-{$year}-%")
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('report_number');

        $seq = $last ? ((int) substr($last, -6)) + 1 : 1;

        return sprintf('%s-%d-%06d', $type, $year, $seq);
    }
}
