<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-STAFF-360-001 — ملفّ الموظّف الموحَّد، وتقييمه، ودرجة مخاطرته.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ما تراه الشركة عن موظّفها هو ما تراه المنصّة عن الوكيل، طبقةً أدنى.**
 *
 * وكان الموظّف في هذه البوّابة سطراً في جدول: اسمٌ ورمزٌ وحالة. ومديرٌ
 * يريد أن يعرف «كيف يعمل محمد» لم يكن أمامه إلّا أن يسأل محمداً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **القاعدة الحاكمة: كلّ رقمٍ من مصدره.**
 *
 *   • العمليات    ← `agent_cash_movements` (سجلٌّ يُلحَق ولا يُعدَّل)
 *   • الورديّات   ← `agent_shifts`
 *   • الفروق      ← `variance` المحسوب عند الإغلاق
 *
 * ولا يُقرأ رقمٌ من عمودٍ تجميعيٍّ يُحدَّث بيد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والعجز والفائض لا يُقاصّان.**
 *
 * صرّافٌ نقص عنده خمسةٌ يوماً وزاد خمسةٌ يوماً آخر ليس «صفراً»: هما
 * حادثتان تستحقّان سؤالين. والمقاصّة تُخفي الاثنين وتُخرج موظّفاً مضطرباً
 * في صورة المنضبط.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ودرجةُ المخاطرة تكون «غير محسوبة» ولا تكون صفراً.**
 *
 * موظّفٌ لم يُغلق ورديّةً واحدة ليس منخفض المخاطر — هو **غير مُقيَّم**.
 * وإظهارُه أخضرَ يجعل المدير يثق بمن لم يُختبَر. فمن لا بيانات له تُعاد
 * درجتُه `null` مع سببها، لا رقماً.
 */
class AgentStaffProfileService
{
    /** نافذة التقييم الافتراضيّة — شهرٌ من العمل. */
    private const DEFAULT_DAYS = 30;

    /** أقلّ عددِ ورديّاتٍ مغلقةٍ تُحسب عندها درجة. */
    private const MIN_SHIFTS_FOR_SCORE = 3;

    /**
     * الملفّ الكامل لموظّفٍ واحد.
     */
    public function profile(AgentStaff $staff, ?string $from = null, ?string $to = null): array
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now()->subDays(self::DEFAULT_DAYS)->startOfDay();
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();

        $shifts = AgentShift::where('staff_id', $staff->id)
            ->whereBetween('opened_at', [$fromDate, $toDate])
            ->orderByDesc('opened_at')->get();

        $ops = $this->operations($staff, $fromDate, $toDate);
        $shiftStats = $this->shiftStats($shifts);
        $risk = $this->risk($shiftStats, $ops, $staff);

        return [
            'staff' => [
                'id' => (int) $staff->id,
                'name' => $staff->name,
                'username' => $staff->username,
                'role' => $staff->role,
                'role_label' => $staff->roleLabel(),
                'branch' => $staff->branch?->name,
                'branch_id' => $staff->branch_id ? (int) $staff->branch_id : null,
                'phone' => $staff->phone,
                'is_active' => (bool) $staff->is_active,
                'last_login_at' => $staff->last_login_at?->toDateTimeString(),
                'max_txn_amount' => $staff->max_txn_amount ? (string) $staff->max_txn_amount : null,
                'hired_at' => $staff->created_at?->toDateTimeString(),
            ],

            'period' => ['from' => $fromDate->toDateString(), 'to' => $toDate->toDateString()],

            'operations' => $ops,
            'shifts' => $shiftStats,
            'risk' => $risk,

            'recent_shifts' => $shifts->take(20)->map(fn (AgentShift $s) => [
                'id' => (int) $s->id,
                'opened_at' => $s->opened_at?->toDateTimeString(),
                'closed_at' => $s->closed_at?->toDateTimeString(),
                'status' => $s->status,
                'opening_float' => (string) $s->opening_float,
                'deposits_total' => (string) $s->deposits_total,
                'withdrawals_total' => (string) $s->withdrawals_total,
                'counted_cash' => $s->counted_cash === null ? null : (string) $s->counted_cash,
                'variance' => $s->variance === null ? null : (string) $s->variance,
                'review_status' => $s->review_status,
                'review_label' => AgentShift::REVIEW_LABELS[$s->review_status] ?? $s->review_status,
                'close_note' => $s->close_note,
            ])->values()->all(),

            'recent_operations' => $this->recentOperations($staff, $fromDate, $toDate),
        ];
    }

    /**
     * حجم العمل — من سجلّ الحركة لا من عدّادٍ مخزَّن.
     */
    private function operations(AgentStaff $staff, Carbon $from, Carbon $to): array
    {
        $rows = AgentCashMovement::where('staff_id', $staff->id)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
            ->selectRaw('reason, count(*) as n, sum(amount) as total, max(amount) as biggest')
            ->groupBy('reason')->get()->keyBy('reason');

        $dep = $rows['customer_deposit'] ?? null;
        $wdr = $rows['customer_withdraw'] ?? null;

        $depTotal = bcadd((string) ($dep->total ?? '0'), '0', 4);
        $wdrTotal = bcadd((string) ($wdr->total ?? '0'), '0', 4);

        // عددُ العملاء المتميّزين: صرّافٌ حجمُه كبيرٌ على عميلين ليس كصرّافٍ
        // مثلِه على مئتين — والرقمان متساويان في المجموع وحده.
        $customers = AgentCashMovement::where('staff_id', $staff->id)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
            ->whereNotNull('customer_user_id')
            ->distinct()->count('customer_user_id');

        return [
            'deposits_count' => (int) ($dep->n ?? 0),
            'deposits_total' => $depTotal,
            'deposits_biggest' => bcadd((string) ($dep->biggest ?? '0'), '0', 4),
            'withdrawals_count' => (int) ($wdr->n ?? 0),
            'withdrawals_total' => $wdrTotal,
            'withdrawals_biggest' => bcadd((string) ($wdr->biggest ?? '0'), '0', 4),
            'total_count' => (int) ($dep->n ?? 0) + (int) ($wdr->n ?? 0),
            'total_volume' => bcadd($depTotal, $wdrTotal, 4),
            'distinct_customers' => $customers,
        ];
    }

    /**
     * الورديّات — والفروق مفصولةً عجزاً وفائضاً.
     */
    private function shiftStats($shifts): array
    {
        $closed = $shifts->where('status', AgentShift::STATUS_CLOSED);
        $open = $shifts->where('status', AgentShift::STATUS_OPEN);

        $short = $closed->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) < 0);
        $over = $closed->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) > 0);
        $exact = $closed->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) === 0);

        $sum = fn ($c) => (string) $c->reduce(
            fn ($a, $s) => bcadd($a, ltrim((string) $s->variance, '-'), 4), '0');

        return [
            'opened' => $shifts->count(),
            'closed' => $closed->count(),
            // ورديّةٌ لم تُغلق ليست «بلا فرق» — هي درجٌ بلا شهادةِ إنسان.
            'still_open' => $open->count(),

            'exact_count' => $exact->count(),
            'shortage_count' => $short->count(),
            'shortage_total' => $sum($short),
            'overage_count' => $over->count(),
            'overage_total' => $sum($over),

            'pending_review' => $closed->where('review_status', AgentShift::REVIEW_PENDING)->count(),

            // الدقّة: نسبة ما أُغلق مطابقاً. و«غير معروف» حين لا إغلاق.
            'accuracy_pct' => $closed->count() > 0
                ? round($exact->count() * 100 / $closed->count(), 1)
                : null,
        ];
    }

    /**
     * درجة المخاطرة — من إشارات تُقاس، ومعروضةٌ إشارةً إشارة.
     *
     * **رقمٌ واحدٌ بلا تفصيلٍ لا يُتّخذ عليه قرار.** مديرٌ يقرأ «٧٢» لا يعرف
     * أيوقف الموظّف أم يدرّبه أم يراجع خزنته. فتُعاد الإشارات كلّها بوزنها
     * ومقدارها، والدرجةُ مجموعُها لا بديلٌ عنها.
     */
    private function risk(array $shifts, array $ops, AgentStaff $staff): array
    {
        // ── لا بيانات ⇒ لا درجة ────────────────────────────────────────
        if ($shifts['closed'] < self::MIN_SHIFTS_FOR_SCORE) {
            return [
                'score' => null,
                'level' => 'unrated',
                'level_label' => 'غير مُقيَّم',
                'reason' => 'أغلق ' . $shifts['closed'] . ' ورديّة فقط — تُحسب الدرجة من '
                    . self::MIN_SHIFTS_FOR_SCORE . ' فأكثر. وموظّفٌ لم يُختبَر ليس منخفض المخاطر.',
                'signals' => [],
            ];
        }

        $signals = [];
        $score = 0;

        // ① تكرار العجز — أثقل الإشارات: مالٌ نقص وتحت يدٍ واحدة.
        $shortRate = $shifts['shortage_count'] / max(1, $shifts['closed']);
        $p = (int) round(min(35, $shortRate * 100));
        $score += $p;
        $signals[] = [
            'key' => 'shortage_rate',
            'label' => 'تكرار العجز',
            'value' => $shifts['shortage_count'] . ' من ' . $shifts['closed'] . ' ورديّة',
            'points' => $p, 'max' => 35,
        ];

        // ② حجم العجز نسبةً إلى حجم العمل — عجزُ ألفٍ على مليونٍ غيرُ
        //    عجزِ ألفٍ على عشرة آلاف.
        $vol = $ops['total_volume'];
        $ratio = bccomp($vol, '0', 4) > 0
            ? (float) bcdiv($shifts['shortage_total'], $vol, 6) : 0.0;
        $p = (int) round(min(25, $ratio * 2500));       // ١٪ من الحجم ⇒ ٢٥ نقطة
        $score += $p;
        $signals[] = [
            'key' => 'shortage_weight',
            'label' => 'ثقل العجز في حجم عمله',
            'value' => $shifts['shortage_total'] . ' من ' . $vol,
            'points' => $p, 'max' => 25,
        ];

        // ③ الفائض المتكرّر إشارةٌ أيضاً، لا خبرٌ سارّ: من يعدّ صحيحاً لا
        //    يزيد ولا ينقص. والفائض المنتظم قد يكون مالاً أُخذ ثمّ رُدّ.
        $overRate = $shifts['overage_count'] / max(1, $shifts['closed']);
        $p = (int) round(min(15, $overRate * 40));
        $score += $p;
        $signals[] = [
            'key' => 'overage_rate',
            'label' => 'تكرار الفائض',
            'value' => $shifts['overage_count'] . ' من ' . $shifts['closed'] . ' ورديّة',
            'points' => $p, 'max' => 15,
        ];

        // ④ ورديّاتٌ تُترك مفتوحة — درجٌ بلا جردٍ في آخر اليوم.
        $p = min(15, $shifts['still_open'] * 5);
        $score += $p;
        $signals[] = [
            'key' => 'unclosed',
            'label' => 'ورديّات تُركت مفتوحة',
            'value' => (string) $shifts['still_open'],
            'points' => $p, 'max' => 15,
        ];

        // ⑤ فروقٌ أُغلقت بلا مراجعة — ليست إشارةً على الموظّف وحده، بل
        //    على الرقابة عليه. وتبقى في درجته لأنّ المال ما زال مفقوداً.
        $p = min(10, $shifts['pending_review'] * 3);
        $score += $p;
        $signals[] = [
            'key' => 'unreviewed',
            'label' => 'فروقٌ لم تُراجَع بعد',
            'value' => (string) $shifts['pending_review'],
            'points' => $p, 'max' => 10,
        ];

        $score = min(100, $score);

        return [
            'score' => $score,
            'level' => $this->level($score),
            'level_label' => [
                'low' => 'منخفض', 'medium' => 'متوسّط', 'high' => 'مرتفع',
            ][$this->level($score)],
            'reason' => null,
            'signals' => $signals,
        ];
    }

    private function level(int $score): string
    {
        if ($score >= 60) {
            return 'high';
        }

        return $score >= 30 ? 'medium' : 'low';
    }

    /** آخرُ عمليّاته — الأسماء لا الأرقام، فالمدير يقرأ لا يستعلم. */
    private function recentOperations(AgentStaff $staff, Carbon $from, Carbon $to): array
    {
        $rows = AgentCashMovement::where('staff_id', $staff->id)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
            ->orderByDesc('created_at')->limit(30)->get();

        $names = User::whereIn('id', $rows->pluck('customer_user_id')->filter()->unique())
            ->get(['id', 'f_name', 'l_name', 'phone'])->keyBy('id');

        return $rows->map(function (AgentCashMovement $m) use ($names) {
            $c = $names[$m->customer_user_id] ?? null;

            return [
                'id' => (int) $m->id,
                'at' => (string) $m->created_at,
                'reason' => $m->reason,
                'label' => AgentCashMovement::REASON_LABELS[$m->reason] ?? $m->reason,
                'direction' => $m->direction,
                'amount' => (string) $m->amount,
                'reference' => $m->reference,
                'customer' => $c ? (trim(($c->f_name ?? '') . ' ' . ($c->l_name ?? '')) ?: $c->phone) : null,
                'customer_phone' => $c?->phone,
            ];
        })->values()->all();
    }

    /**
     * سجلُّ عمليات الشركة — كلّ إيداعٍ وسحبٍ باسم من نفّذه.
     *
     * كان الحلّ الوحيد لمعرفة «ماذا جرى اليوم» هو فتح كلّ فرعٍ على حدة في
     * «حركة النقد». والسجلّ الواحد هو ما يُقرأ فعلاً.
     *
     * @param  array<int>  $branchIds  الفروع المسموح بها لهذا الطالب
     */
    public function operationsLog(array $branchIds, array $filters = []): array
    {
        if ($branchIds === []) {
            return ['rows' => [], 'totals' => $this->emptyTotals()];
        }

        $q = AgentCashMovement::whereIn('branch_id', $branchIds);

        if (!empty($filters['branch_id'])) {
            $bid = (int) $filters['branch_id'];
            if (!in_array($bid, $branchIds, true)) {
                return ['rows' => [], 'totals' => $this->emptyTotals()];
            }
            $q->where('branch_id', $bid);
        }

        if (!empty($filters['staff_id'])) {
            $q->where('staff_id', (int) $filters['staff_id']);
        }

        // الافتراضيّ عمليّات العملاء: حركاتُ الورديّة نقلٌ داخليّ، وإظهارها
        // بلا طلبٍ يُغرق السجلّ بما ليس عمليّةً لعميل.
        $reasons = $filters['reason'] ?? null;
        $q->whereIn('reason', $reasons
            ? [$reasons]
            : ['customer_deposit', 'customer_withdraw']);

        $from = !empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->subDays(7)->startOfDay();
        $to = !empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();
        $q->whereBetween('created_at', [$from, $to]);

        $rows = (clone $q)->orderByDesc('created_at')->limit(300)->get();

        $staff = AgentStaff::whereIn('id', $rows->pluck('staff_id')->filter()->unique())
            ->get(['id', 'name', 'username'])->keyBy('id');
        $branches = AgentBranch::whereIn('id', $rows->pluck('branch_id')->unique())
            ->get(['id', 'name'])->keyBy('id');
        $customers = User::whereIn('id', $rows->pluck('customer_user_id')->filter()->unique())
            ->get(['id', 'f_name', 'l_name', 'phone'])->keyBy('id');

        $agg = (clone $q)->selectRaw('reason, count(*) as n, sum(amount) as total')
            ->groupBy('reason')->get()->keyBy('reason');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $rows->map(function (AgentCashMovement $m) use ($staff, $branches, $customers) {
                $c = $customers[$m->customer_user_id] ?? null;
                $s = $staff[$m->staff_id] ?? null;

                return [
                    'id' => (int) $m->id,
                    'at' => (string) $m->created_at,
                    'branch' => $branches[$m->branch_id]->name ?? '—',
                    'staff' => $s?->name ?? '—',
                    'staff_code' => $s?->username,
                    'staff_id' => $m->staff_id ? (int) $m->staff_id : null,
                    'reason' => $m->reason,
                    'label' => AgentCashMovement::REASON_LABELS[$m->reason] ?? $m->reason,
                    'direction' => $m->direction,
                    'amount' => (string) $m->amount,
                    'reference' => $m->reference,
                    'customer' => $c ? (trim(($c->f_name ?? '') . ' ' . ($c->l_name ?? '')) ?: $c->phone) : null,
                    'customer_phone' => $c?->phone,
                ];
            })->values()->all(),
            'totals' => [
                'deposits_count' => (int) ($agg['customer_deposit']->n ?? 0),
                'deposits_total' => bcadd((string) ($agg['customer_deposit']->total ?? '0'), '0', 4),
                'withdrawals_count' => (int) ($agg['customer_withdraw']->n ?? 0),
                'withdrawals_total' => bcadd((string) ($agg['customer_withdraw']->total ?? '0'), '0', 4),
                // الصفوف محدودةٌ بثلاثمئة، والإجماليّات على الفترة كلّها.
                // ولو حُسبت من الصفوف لكذب الإجماليّ كلّما طالت الفترة.
                'row_limit_reached' => $rows->count() >= 300,
            ],
        ];
    }

    private function emptyTotals(): array
    {
        return [
            'deposits_count' => 0, 'deposits_total' => '0.0000',
            'withdrawals_count' => 0, 'withdrawals_total' => '0.0000',
            'row_limit_reached' => false,
        ];
    }
}
