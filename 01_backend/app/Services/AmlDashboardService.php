<?php

namespace App\Services;

use App\Models\Aml\AmlFlaggedTransaction;
use App\Models\Aml\AmlInvestigation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AML-DASHBOARD-001 — مؤشّرات مركز مكافحة غسل الأموال (الفصل ١٠).
 *
 * **القرار الأهمّ في هذا الملفّ ليس حسابياً بل أخلاقيّ:** ماذا يُعرَض عن
 * ضابطٍ غير موجود؟
 *
 * الوثيقة تطلب عشرة مؤشّرات، منها «مطابقات قوائم المراقبة» و«مطابقات
 * الأشخاص المعرَّضين سياسيّاً (PEP)» — وكلاهما **غير مبنيّ في هذا النظام**.
 *
 * وعرضُ «٠» عنهما هو الحلّ السهل، وهو كذبٌ صريح: الصفر في لوحة امتثال
 * يُقرأ «فحصنا فلم نجد»، لا «لم نفحص». والفرق بينهما هو الفرق بين منصّةٍ
 * نظيفة وأخرى عمياء — ومن يقرأ اللوحة (مديرٌ أو مدقّق أو منظّم) سيبني على
 * ما قرأ.
 *
 * ولذلك يُعاد عن كلّ مؤشّرٍ غير مبنيّ `configured: false` بلا رقم، وتُظهره
 * الواجهة «غير مُفعَّل» لا «٠». وهذا أضعفُ ما يمكن قوله وأصدقُه.
 */
class AmlDashboardService
{
    /** ما يُعدّ خطراً عالياً. */
    private const HIGH_RISK_LEVELS = ['high', 'very_high', 'critical'];

    public function __construct(
        private readonly AmlRegulatoryReportService $reports,
    ) {
    }

    public function metrics(): array
    {
        return [
            'high_risk' => $this->highRiskByType(),
            'large_transactions' => $this->largeTransactions(),
            'structuring' => $this->structuringAlerts(),
            'sanctions' => $this->sanctionMatches(),

            // انظر شرح أعلى الملفّ: لا رقم عمّا لم يُبنَ.
            'watchlist' => [
                'configured' => false,
                'why' => 'قوائم المراقبة غير مبنيّة — لا يوجد مصدرٌ يُطابَق عليه',
            ],
            'pep' => [
                'configured' => false,
                'why' => 'فحص الأشخاص المعرَّضين سياسيّاً غير مبنيّ — لا قائمة PEP في النظام',
            ],

            'investigations' => $this->investigationCounts(),
            'reports' => $this->reports->pendingSummary(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * العملاء والتجّار والوكلاء عالو الخطر — كلٌّ على حدة.
     *
     * والتفريق ليس تجميلاً: وكيلٌ عالي الخطر مشكلةٌ من نوعٍ آخر تماماً، لأنّه
     * نقطة دخولٍ للنقد إلى المنصّة كلّها لا حسابٌ واحد. وجمعُهم في رقمٍ واحد
     * يُخفي ذلك.
     */
    private function highRiskByType(): array
    {
        if (!$this->hasTable('aml_user_risk_profiles')) {
            return ['configured' => false, 'why' => 'جدول ملفّات الخطر غير موجود'];
        }

        $rows = DB::table('aml_user_risk_profiles as p')
            ->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('p.risk_level', self::HIGH_RISK_LEVELS)
            ->selectRaw('u.type, COUNT(*) as c')
            ->groupBy('u.type')
            ->pluck('c', 'type');

        $blacklisted = DB::table('aml_user_risk_profiles')
            ->where('manual_override', 'blacklist')->count();

        return [
            'configured' => true,
            'customers' => (int) ($rows[CUSTOMER_TYPE] ?? 0),
            'merchants' => (int) ($rows[MERCHANT_TYPE] ?? 0),
            'agents' => (int) ($rows[AGENT_TYPE] ?? 0),
            'blacklisted' => $blacklisted,
            // القائمة البيضاء تُعرَض مع الخطر لا بعيداً عنه: عميلٌ مستثنى من
            // الرقابة معلومةٌ رقابية بذاتها، وإخفاؤها يجعل اللوحة تبدو أنظف
            // ممّا هي.
            'whitelisted' => DB::table('aml_user_risk_profiles')
                ->where('manual_override', 'whitelist')->count(),
        ];
    }

    /**
     * العمليات الكبيرة — بمقياس حدّ بلاغ العملة.
     *
     * ويُعرَض معها **ما تجاوز الحدّ ولم يُبلَّغ عنه**: هذا هو الرقم الذي
     * يُسأل عنه، لا العدد الكلّيّ. عمليةٌ فوق الحدّ بلا بلاغ مخالفةٌ صريحة.
     */
    private function largeTransactions(): array
    {
        $threshold = $this->reports->ctrThreshold();
        $since = now()->subDays(30);

        $flagged = AmlFlaggedTransaction::where('amount', '>=', $threshold)
            ->where('created_at', '>=', $since)
            ->count();

        $reported = DB::table('aml_regulatory_reports')
            ->where('report_type', 'CTR')
            ->where('generated_at', '>=', $since)
            ->count();

        return [
            'configured' => true,
            'threshold' => $threshold,
            'flagged_30d' => $flagged,
            'ctr_generated_30d' => $reported,
            'pending_review' => AmlFlaggedTransaction::where('amount', '>=', $threshold)
                ->where('current_status', 'pending_review')->count(),
        ];
    }

    /** تقسيم العمليات — القاعدة مبنيّة (`StructuringRule`)، فالرقم حقيقيّ. */
    private function structuringAlerts(): array
    {
        if (!$this->hasTable('aml_rule_evaluations')) {
            return ['configured' => false, 'why' => 'جدول تقييمات القواعد غير موجود'];
        }

        $matched = DB::table('aml_rule_evaluations')
            ->where('rule_code', 'like', 'STRUCTURING%')
            ->where('matched', true);

        return [
            'configured' => true,
            'matched_30d' => (clone $matched)->where('created_at', '>=', now()->subDays(30))->count(),
            'distinct_customers_30d' => (clone $matched)
                ->where('created_at', '>=', now()->subDays(30))
                ->distinct()->count('actor_user_id'),
        ];
    }

    /**
     * العقوبات.
     *
     * ويُفرَّق بين «تأكّدت المطابقة» و«محتملة لم تُراجَع بعد». والثانية هي
     * ما يُتابَع: مطابقةٌ معلّقة تعني عميلاً بين حالتين، وتراكمُها هو أوّل ما
     * يظهر في تفتيشٍ رقابيّ.
     */
    private function sanctionMatches(): array
    {
        if (!$this->hasTable('sanction_screening_logs')) {
            return ['configured' => false, 'why' => 'فحص العقوبات غير مبنيّ'];
        }

        $entries = (int) DB::table('sanction_list_entries')->where('is_active', true)->count();

        // قائمةٌ فارغة تعني أنّ الفحص يمرّ دائماً بلا مطابقة — وهو «نظيف»
        // كاذب. يُقال صراحةً بدل أن يُعرَض صفرٌ مطمئن.
        if ($entries === 0) {
            return [
                'configured' => false,
                'why' => 'قائمة العقوبات فارغة — الفحص يعمل ولا شيء يُطابَق عليه',
                'list_entries' => 0,
            ];
        }

        $logs = DB::table('sanction_screening_logs');

        return [
            'configured' => true,
            'list_entries' => $entries,
            'confirmed' => (clone $logs)->where('result', 'confirmed_match')->count(),
            'potential_pending' => (clone $logs)
                ->where('result', 'potential_match')
                ->where('review_status', 'pending')->count(),
            'dismissed' => (clone $logs)->where('review_status', 'dismissed')->count(),
            'blocked_users' => $this->hasColumn('users', 'sanction_status')
                ? User::where('sanction_status', 'blocked')->count()
                : 0,
        ];
    }

    private function investigationCounts(): array
    {
        $open = AmlInvestigation::open();
        $closed = AmlInvestigation::where('status', AmlInvestigation::STATUS_CLOSED);

        // متوسّط زمن الإغلاق مؤشّرٌ تنفيذيّ في الوثيقة — وهو أيضاً ما يسأل
        // عنه المنظّم: قضيّةٌ مفتوحة منذ ستّة أشهر ليست تحقيقاً بل إهمالاً.
        $avgHours = (float) (AmlInvestigation::whereNotNull('closed_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, opened_at, closed_at)) as h')
            ->value('h') ?? 0);

        $oldestOpen = (clone $open)->orderBy('opened_at')->first();

        return [
            'configured' => true,
            'open' => (clone $open)->count(),
            'critical_open' => (clone $open)->where('priority', 'critical')->count(),
            'unassigned' => (clone $open)->whereNull('assigned_officer_id')->count(),
            'closed' => (clone $closed)->count(),
            'avg_close_hours' => round($avgHours, 1),
            'oldest_open_hours' => $oldestOpen ? $oldestOpen->ageHours() : 0,
            'oldest_open_case' => $oldestOpen?->case_number,
        ];
    }

    private function hasTable(string $t): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable($t);
    }

    private function hasColumn(string $t, string $c): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn($t, $c);
    }

    // ── قوائم التبويبات ─────────────────────────────────────────────────

    /** التبويب ٢ — مراقبة العمليات الكبيرة. */
    public function largeTransactionList(int $limit = 100): array
    {
        $threshold = $this->reports->ctrThreshold();

        return AmlFlaggedTransaction::with(['actor:id,f_name,l_name,phone', 'counterparty:id,f_name,l_name'])
            ->where('amount', '>=', $threshold)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(function (AmlFlaggedTransaction $f) {
                // «هل بُلِّغ عنها؟» هو العمود الذي يجعل هذا التبويب مفيداً.
                // بدونه يصير قائمةَ عملياتٍ كبيرة لا أداةَ امتثال.
                $reported = DB::table('aml_regulatory_reports')
                    ->where('report_type', 'CTR')
                    ->where('transaction_ulid', $f->transaction_ulid)
                    ->value('report_number');

                return [
                    'flag_ulid' => $f->flag_ulid,
                    'transaction_ulid' => $f->transaction_ulid,
                    'transaction_type' => $f->transaction_type,
                    'actor_user_id' => (int) $f->actor_user_id,
                    'source' => trim((string) ($f->actor?->f_name . ' ' . $f->actor?->l_name)) ?: '—',
                    'source_phone' => (string) ($f->actor?->phone ?? '—'),
                    'destination' => $f->counterparty
                        ? trim((string) ($f->counterparty->f_name . ' ' . $f->counterparty->l_name))
                        : '—',
                    'amount' => (string) $f->amount,
                    'risk_score' => (string) $f->total_risk_score,
                    'status' => $f->current_status,
                    'ctr_report' => $reported,
                    'created_at' => $f->created_at?->toIso8601String(),
                ];
            })->all();
    }

    /** التبويب ٣ — كشف تقسيم العمليات. */
    public function structuringList(int $limit = 100): array
    {
        if (!$this->hasTable('aml_rule_evaluations')) {
            return [];
        }

        // يُجمَّع على العميل لا على العملية: التقسيم **نمطٌ** لا حادثة، وصفٌّ
        // لكلّ عملية يُخفي أنّ العشرين صفّاً لشخصٍ واحد.
        $rows = DB::table('aml_rule_evaluations as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.rule_code', 'like', 'STRUCTURING%')
            ->where('e.matched', true)
            ->where('e.created_at', '>=', now()->subDays(90))
            ->groupBy('e.actor_user_id', 'u.f_name', 'u.l_name', 'u.phone')
            ->selectRaw('e.actor_user_id, u.f_name, u.l_name, u.phone,
                COUNT(*) as hits, SUM(e.amount) as total,
                MIN(e.created_at) as first_at, MAX(e.created_at) as last_at,
                MAX(e.contributed_risk_score) as risk')
            ->orderByDesc('hits')
            ->limit($limit)
            ->get();

        return $rows->map(function ($r) {
            $open = AmlInvestigation::where('subject_user_id', $r->actor_user_id)
                ->open()->value('case_number');

            return [
                'user_id' => (int) $r->actor_user_id,
                'customer' => trim((string) ($r->f_name . ' ' . $r->l_name)) ?: '—',
                'phone' => (string) ($r->phone ?? '—'),
                'transactions' => (int) $r->hits,
                'total_amount' => (string) $r->total,
                'window_hours' => (int) round(
                    (strtotime((string) $r->last_at) - strtotime((string) $r->first_at)) / 3600,
                ),
                'risk_score' => (string) $r->risk,
                // حالة التحقيق: نمطٌ مكشوف بلا قضيّةٍ مفتوحة هو الرصد الذي
                // يقف عنده النظام — وهو ما بُني مركز التحقيقات لإنهائه.
                'investigation' => $open,
            ];
        })->all();
    }

    /** التبويب ٦ — فحص العقوبات. */
    public function sanctionList(?string $reviewStatus = null, int $limit = 100): array
    {
        if (!$this->hasTable('sanction_screening_logs')) {
            return [];
        }

        $q = DB::table('sanction_screening_logs as l')
            ->leftJoin('users as u', 'u.id', '=', 'l.user_id')
            ->leftJoin('sanction_list_entries as e', 'e.id', '=', 'l.matched_entry_id')
            ->where('l.result', '!=', 'clear');

        if ($reviewStatus) {
            $q->where('l.review_status', $reviewStatus);
        }

        return $q->orderByDesc('l.id')->limit($limit)
            ->selectRaw('l.id, l.user_id, l.screened_name, l.result, l.match_score,
                l.review_status, l.review_note, l.screening_context, l.screened_at,
                u.f_name, u.l_name, u.phone,
                e.list_source, e.full_name as matched_name, e.nationality, e.program')
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'user_id' => $r->user_id ? (int) $r->user_id : null,
                'customer' => trim((string) ($r->f_name . ' ' . $r->l_name)) ?: $r->screened_name,
                'phone' => (string) ($r->phone ?? '—'),
                'screened_name' => $r->screened_name,
                'result' => $r->result,
                'match_score' => (string) $r->match_score,
                'list_source' => $r->list_source ?? '—',
                'matched_name' => $r->matched_name ?? '—',
                'nationality' => $r->nationality ?? '—',
                'program' => $r->program ?? '—',
                'review_status' => $r->review_status ?? 'pending',
                'review_note' => $r->review_note,
                'context' => $r->screening_context,
                'screened_at' => $r->screened_at,
                'age_hours' => (int) ((time() - strtotime((string) $r->screened_at)) / 3600),
            ])->all();
    }

    /**
     * البتّ في مطابقة عقوبات محتملة.
     *
     * **الاستبعاد يحتاج سبباً أطول من التأكيد** — وهذا مقصود ومقلوبٌ عن
     * الحدس. التأكيد يوقف الحساب، وأثره ظاهرٌ يُراجَع من نفسه. أمّا
     * الاستبعاد فيُعيد العميل إلى العمل بلا أثر، وهو القرار الذي يُسأل عنه
     * في التفتيش: «لماذا استبعدتم مطابقةً بدرجة ٩٢٪؟». فمن يستبعد يكتب
     * جوابه اليوم لا يوم يُسأل.
     */
    public function reviewSanctionMatch(
        int $logId, \App\Models\User $reviewer, string $decision, string $note
    ): array {
        if (!in_array($decision, ['dismissed', 'confirmed'], true)) {
            throw new \DomainException('قرار غير معروف');
        }

        $minimum = $decision === 'dismissed' ? 20 : 10;
        if (mb_strlen(trim($note)) < $minimum) {
            throw new \DomainException(
                $decision === 'dismissed'
                    ? 'سبب الاستبعاد إلزاميّ (٢٠ حرفاً على الأقل) — اذكر ما فحصتَه وكيف تبيّن الاختلاف'
                    : 'سبب التأكيد إلزاميّ (١٠ أحرف على الأقل)',
            );
        }

        $log = DB::table('sanction_screening_logs')->where('id', $logId)->first();
        if (!$log) {
            throw new \DomainException('السجلّ غير موجود');
        }

        if (($log->review_status ?? 'pending') !== 'pending') {
            throw new \DomainException('بُتَّ في هذه المطابقة من قبل — الحالة: ' . $log->review_status);
        }

        DB::transaction(function () use ($log, $reviewer, $decision, $note) {
            DB::table('sanction_screening_logs')->where('id', $log->id)->update([
                'review_status' => $decision,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => mb_substr(trim($note), 0, 2000),
            ]);

            // التأكيد يوقف الحساب فعلاً لا في السجلّ وحده.
            if ($decision === 'confirmed' && $log->user_id
                && $this->hasColumn('users', 'sanction_status')) {
                User::where('id', $log->user_id)->update(['sanction_status' => 'blocked']);
            }
        });

        app(AuditService::class)->record([
            'actor_type' => 'admin',
            'actor_user_id' => $reviewer->id,
            'subject_type' => 'sanction_screening',
            'subject_id' => (string) $log->id,
            'action' => 'SANCTION_MATCH_REVIEWED',
            'decision_code' => strtoupper($decision),
            'reason' => mb_substr($note, 0, 500),
            'severity' => 'critical',
            'context' => ['customer_id' => $log->user_id, 'score' => $log->match_score],
        ]);

        return ['id' => (int) $log->id, 'review_status' => $decision];
    }
}
