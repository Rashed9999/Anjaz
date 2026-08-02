<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentDailySettlement;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AGENT-REPORTS-001 — تقارير شركة الصرافة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **التقرير ليس جدولاً — هو جوابُ سؤال.** ولذلك كلّ قسمٍ هنا مكتوبٌ
 * لسؤالٍ يسأله مديرُ شركةٍ فعلاً:
 *
 *   • كيف كان الشهر مقارنةً بالذي قبله؟   ← `summary`
 *   • أيّ يومٍ كان ثقيلاً وأيّ يومٍ خفيفاً؟ ← `daily`
 *   • أيّ فروعي يعمل وأيّها يأكل رأس المال؟ ← `byBranch`
 *   • من موظّفيّ دقيقٌ ومن يتكرّر عنده الفرق؟ ← `byStaff`
 *   • أين ضاع المال بالضبط؟                ← `variances`
 *   • هل أُقفل كلّ يومٍ في وقته؟            ← `settlements`
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والمقارنة بالفترة السابقة ليست زينة.** رقمٌ وحده لا يُقرأ: «٣ ملايين»
 * لا تعني شيئاً حتى تُعرف أنّها كانت ٤ الشهر الماضي. فكلّ مؤشّرٍ يحمل
 * نظيرَه من فترةٍ سابقةٍ **بنفس الطول** ونسبةَ التغيّر.
 *
 * **و«لا بيانات» تُقال ولا تُعرَض صفراً** — فترةٌ بلا عملٍ ليست فترةً
 * حصيلتُها صفر، والفرق بينهما هو الفرق بين «فرعٌ متوقّف» و«فرعٌ خسر».
 */
class AgentReportsService
{
    /**
     * التقرير كاملاً — كلّ الأقسام في نداءٍ واحد.
     *
     * ونداءٌ واحدٌ مقصود: ستّة نداءاتٍ متتابعة تجعل الشاشة تُبنى على
     * دفعاتٍ فتُطبَع نصفَ جاهزة.
     */
    public function full(User $agent, string $from, string $to, array $branchIds = []): array
    {
        $fromD = Carbon::parse($from)->startOfDay();
        $toD = Carbon::parse($to)->endOfDay();

        $ids = $branchIds ?: AgentBranch::where('agent_user_id', $agent->id)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();

        // الفترة السابقة بنفس الطول تماماً — لا «الشهر الماضي» جزافاً.
        //
        // والفارق يُحسب بين **بدايتَي يومين**: `$toD` نهايةُ يومٍ، فالفرق
        // بينه وبين بداية الأوّل ٦٫٩٩٩٩ لا ٧ — فكان طول الفترة يخرج
        // «7.999999999988426»، ويُطبَع على ورقةٍ تُقدَّم إلى بنك.
        $days = (int) $fromD->copy()->startOfDay()
            ->diffInDays($toD->copy()->startOfDay()) + 1;
        $prevTo = $fromD->copy()->subSecond();
        $prevFrom = $prevTo->copy()->subDays($days - 1)->startOfDay();

        return [
            'period' => [
                'from' => $fromD->toDateString(),
                'to' => $toD->toDateString(),
                'days' => $days,
                'prev_from' => $prevFrom->toDateString(),
                'prev_to' => $prevTo->toDateString(),
            ],
            'agent' => [
                'name' => trim(($agent->f_name ?? '') . ' ' . ($agent->l_name ?? '')) ?: ('#' . $agent->id),
                'phone' => $agent->phone,
            ],
            'summary' => $this->summary($ids, $fromD, $toD, $prevFrom, $prevTo),
            'daily' => $this->daily($ids, $fromD, $toD),
            'by_branch' => $this->byBranch($agent, $ids, $fromD, $toD),
            'by_staff' => $this->byStaff($ids, $fromD, $toD),
            'variances' => $this->variances($ids, $fromD, $toD),
            'settlements' => $this->settlements($agent, $fromD, $toD),
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    // ══════════════════════════════════════════════════════════════════

    private function volumes(array $ids, Carbon $from, Carbon $to): array
    {
        if ($ids === []) {
            return ['dep_n' => 0, 'dep' => '0.0000', 'wdr_n' => 0, 'wdr' => '0.0000'];
        }

        $r = AgentCashMovement::whereIn('branch_id', $ids)
            ->where('is_drawer', true)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
            ->selectRaw('reason, count(*) as n, sum(amount) as total')
            ->groupBy('reason')->get()->keyBy('reason');

        return [
            'dep_n' => (int) ($r['customer_deposit']->n ?? 0),
            'dep' => bcadd((string) ($r['customer_deposit']->total ?? '0'), '0', 4),
            'wdr_n' => (int) ($r['customer_withdraw']->n ?? 0),
            'wdr' => bcadd((string) ($r['customer_withdraw']->total ?? '0'), '0', 4),
        ];
    }

    private function summary(array $ids, Carbon $from, Carbon $to, Carbon $pFrom, Carbon $pTo): array
    {
        $now = $this->volumes($ids, $from, $to);
        $prev = $this->volumes($ids, $pFrom, $pTo);

        $vol = bcadd($now['dep'], $now['wdr'], 4);
        $prevVol = bcadd($prev['dep'], $prev['wdr'], 4);
        $count = $now['dep_n'] + $now['wdr_n'];

        [$fees, $commission] = $this->feesBetween($ids, $from, $to);
        [$prevFees, $prevCommission] = $this->feesBetween($ids, $pFrom, $pTo);

        $customers = $ids === [] ? 0 : AgentCashMovement::whereIn('branch_id', $ids)
            ->where('is_drawer', true)
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('customer_user_id')
            ->distinct()->count('customer_user_id');

        return [
            'volume' => $this->metric($vol, $prevVol),
            'deposits' => $this->metric($now['dep'], $prev['dep']),
            'withdrawals' => $this->metric($now['wdr'], $prev['wdr']),
            'commission' => $this->metric($commission, $prevCommission),
            'fees' => $this->metric($fees, $prevFees),
            'operations' => $this->metric((string) $count, (string) ($prev['dep_n'] + $prev['wdr_n'])),
            'customers' => $customers,
            'avg_ticket' => $count > 0 ? bcdiv($vol, (string) $count, 4) : null,
        ];
    }

    /**
     * مؤشّرٌ مع نظيره ونسبة التغيّر.
     *
     * و«لا نسبة» حين لا يوجد أساس: النموّ من صفرٍ ليس «١٠٠٪» ولا «لا
     * نهاية» — هو بدايةُ عملٍ لم يكن.
     */
    private function metric(string $now, string $prev): array
    {
        $change = null;

        if (bccomp($prev, '0', 4) > 0) {
            $change = round(((float) bcsub($now, $prev, 4) / (float) $prev) * 100, 1);
        }

        return [
            'value' => bcadd($now, '0', 4),
            'previous' => bcadd($prev, '0', 4),
            'change_pct' => $change,
            'is_new' => $change === null && bccomp($now, '0', 4) > 0,
        ];
    }

    private function feesBetween(array $ids, Carbon $from, Carbon $to): array
    {
        if ($ids === []) {
            return ['0.0000', '0.0000'];
        }

        $refs = AgentCashMovement::whereIn('branch_id', $ids)
            ->where('is_drawer', true)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
            ->pluck('reference')->filter()->unique()->all();

        if ($refs === []) {
            return ['0.0000', '0.0000'];
        }

        $rows = DB::table('ledger_journal_entries')
            ->whereIn('source_id', $refs)
            ->whereIn('source_type', ['agent_deposit', 'agent_withdraw'])
            ->pluck('metadata');

        $fees = '0.0000';
        $commission = '0.0000';

        foreach ($rows as $m) {
            $meta = json_decode((string) $m, true) ?: [];
            $fees = bcadd($fees, (string) ($meta['fee'] ?? '0'), 4);
            $commission = bcadd($commission, (string) ($meta['commission'] ?? '0'), 4);
        }

        return [$fees, $commission];
    }

    /**
     * سلسلةٌ يوميّة — **بكلّ أيّام الفترة، بما فيها الأيّام الصامتة.**
     *
     * ورسمٌ يقفز فوق الأيّام الفارغة يكذب على العين: يجعل أسبوعاً بلا عملٍ
     * يبدو خطّاً متّصلاً صاعداً.
     */
    private function daily(array $ids, Carbon $from, Carbon $to): array
    {
        $map = [];

        if ($ids !== []) {
            $rows = AgentCashMovement::whereIn('branch_id', $ids)
                ->where('is_drawer', true)
                ->whereBetween('created_at', [$from, $to])
                ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
                ->selectRaw('DATE(created_at) as d, reason, count(*) as n, sum(amount) as total')
                ->groupBy('d', 'reason')->get();

            foreach ($rows as $r) {
                $map[$r->d][$r->reason] = ['n' => (int) $r->n, 'total' => bcadd((string) $r->total, '0', 4)];
            }
        }

        $out = [];
        $cursor = $from->copy()->startOfDay();

        while ($cursor->lte($to)) {
            $d = $cursor->toDateString();
            $dep = $map[$d]['customer_deposit'] ?? ['n' => 0, 'total' => '0.0000'];
            $wdr = $map[$d]['customer_withdraw'] ?? ['n' => 0, 'total' => '0.0000'];

            $out[] = [
                'date' => $d,
                'label' => $cursor->format('m-d'),
                'deposits' => $dep['total'],
                'withdrawals' => $wdr['total'],
                'volume' => bcadd($dep['total'], $wdr['total'], 4),
                'count' => $dep['n'] + $wdr['n'],
                'net_cash' => bcsub($dep['total'], $wdr['total'], 4),
            ];

            $cursor->addDay();
        }

        return $out;
    }

    private function byBranch(User $agent, array $ids, Carbon $from, Carbon $to): array
    {
        $branches = AgentBranch::with('till')->whereIn('id', $ids ?: [0])->orderBy('name')->get();

        return $branches->map(function (AgentBranch $b) use ($from, $to) {
            $v = $this->volumes([(int) $b->id], $from, $to);
            [$fees, $commission] = $this->feesBetween([(int) $b->id], $from, $to);

            $shifts = AgentShift::where('branch_id', $b->id)
                ->whereBetween('opened_at', [$from, $to])->get();
            $closed = $shifts->where('status', AgentShift::STATUS_CLOSED);

            $short = (string) $closed->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) < 0)
                ->reduce(fn ($a, $s) => bcadd($a, ltrim((string) $s->variance, '-'), 4), '0');
            $over = (string) $closed->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) > 0)
                ->reduce(fn ($a, $s) => bcadd($a, (string) $s->variance, 4), '0');

            return [
                'id' => (int) $b->id,
                'name' => $b->name,
                'code' => $b->code,
                'city' => $b->city,
                'is_active' => (bool) $b->is_active,
                'deposits' => $v['dep'],
                'deposits_count' => $v['dep_n'],
                'withdrawals' => $v['wdr'],
                'withdrawals_count' => $v['wdr_n'],
                'volume' => bcadd($v['dep'], $v['wdr'], 4),
                'commission' => $commission,
                'fees' => $fees,
                'shifts' => $shifts->count(),
                'shortage_total' => $short,
                'overage_total' => $over,
                'cash_on_hand' => bcadd((string) ($b->till->cash_on_hand ?? '0'), '0', 4),
                // فرعٌ لم يعمل يُقال عنه ذلك — لا يُعرَض صفراً بين العاملين.
                'idle' => $v['dep_n'] + $v['wdr_n'] === 0,
            ];
        })->values()->all();
    }

    private function byStaff(array $ids, Carbon $from, Carbon $to): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = AgentCashMovement::whereIn('branch_id', $ids)
            ->where('is_drawer', true)
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('reason', ['customer_deposit', 'customer_withdraw'])
            ->whereNotNull('staff_id')
            ->selectRaw('staff_id, reason, count(*) as n, sum(amount) as total')
            ->groupBy('staff_id', 'reason')->get();

        $staff = AgentStaff::whereIn('id', $rows->pluck('staff_id')->unique())
            ->get()->keyBy('id');

        $agg = [];

        foreach ($rows as $r) {
            $id = (int) $r->staff_id;
            $agg[$id] ??= ['dep' => '0.0000', 'dep_n' => 0, 'wdr' => '0.0000', 'wdr_n' => 0];

            if ($r->reason === 'customer_deposit') {
                $agg[$id]['dep'] = bcadd((string) $r->total, '0', 4);
                $agg[$id]['dep_n'] = (int) $r->n;
            } else {
                $agg[$id]['wdr'] = bcadd((string) $r->total, '0', 4);
                $agg[$id]['wdr_n'] = (int) $r->n;
            }
        }

        $out = [];

        foreach ($agg as $id => $a) {
            $shifts = AgentShift::where('staff_id', $id)
                ->whereBetween('opened_at', [$from, $to])
                ->where('status', AgentShift::STATUS_CLOSED)->get();

            $exact = $shifts->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) === 0)->count();
            $short = $shifts->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) < 0);
            $over = $shifts->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) > 0);

            $abs = fn ($c) => (string) $c->reduce(
                fn ($x, $s) => bcadd($x, ltrim((string) $s->variance, '-'), 4), '0');

            $out[] = [
                'id' => $id,
                'name' => $staff[$id]->name ?? ('#' . $id),
                'username' => $staff[$id]->username ?? null,
                'branch' => $staff[$id]->branch?->name,
                'deposits' => $a['dep'],
                'withdrawals' => $a['wdr'],
                'volume' => bcadd($a['dep'], $a['wdr'], 4),
                'operations' => $a['dep_n'] + $a['wdr_n'],
                'shifts_closed' => $shifts->count(),
                // «غير معروف» ليس ١٠٠٪: من لم يُغلق ورديّةً لا دقّة له.
                'accuracy_pct' => $shifts->count() > 0
                    ? round($exact * 100 / $shifts->count(), 1) : null,
                'shortage_count' => $short->count(),
                'shortage_total' => $abs($short),
                'overage_count' => $over->count(),
                'overage_total' => $abs($over),
            ];
        }

        usort($out, fn ($a, $b) => bccomp($b['volume'], $a['volume'], 4));

        return $out;
    }

    /** أين ضاع المال — ورديّةً ورديّة، بلا مقاصّة. */
    private function variances(array $ids, Carbon $from, Carbon $to): array
    {
        if ($ids === []) {
            return ['rows' => [], 'shortage_total' => '0.0000', 'overage_total' => '0.0000',
                'shortage_count' => 0, 'overage_count' => 0];
        }

        $shifts = AgentShift::with(['staff', 'branch'])
            ->whereIn('branch_id', $ids)
            ->whereBetween('opened_at', [$from, $to])
            ->where('status', AgentShift::STATUS_CLOSED)
            ->whereRaw('COALESCE(variance, 0) <> 0')
            ->orderByDesc('closed_at')->limit(200)->get();

        $short = $shifts->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) < 0);
        $over = $shifts->filter(fn ($s) => bccomp((string) $s->variance, '0', 4) > 0);
        $abs = fn ($c) => (string) $c->reduce(
            fn ($x, $s) => bcadd($x, ltrim((string) $s->variance, '-'), 4), '0');

        return [
            'rows' => $shifts->map(fn (AgentShift $s) => [
                'shift_id' => (int) $s->id,
                'date' => $s->closed_at?->toDateString(),
                'branch' => $s->branch?->name,
                'staff' => $s->staff?->name,
                'variance' => (string) $s->variance,
                'kind' => bccomp((string) $s->variance, '0', 4) < 0 ? 'shortage' : 'overage',
                'review_status' => $s->review_status,
                'review_label' => AgentShift::REVIEW_LABELS[$s->review_status] ?? $s->review_status,
                'note' => $s->close_note,
            ])->values()->all(),
            'shortage_count' => $short->count(),
            'shortage_total' => $abs($short),
            'overage_count' => $over->count(),
            'overage_total' => $abs($over),
        ];
    }

    /** هل أُقفل كلّ يومٍ في وقته؟ */
    private function settlements(User $agent, Carbon $from, Carbon $to): array
    {
        $rows = AgentDailySettlement::where('agent_user_id', $agent->id)
            ->whereBetween('settlement_date', [$from->toDateString(), $to->toDateString()])
            ->orderByDesc('settlement_date')->get();

        $days = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $onTime = $rows->where('window_state', 'on_time')->count();

        return [
            'expected_days' => $days,
            'filed' => $rows->count(),
            'missing' => max(0, $days - $rows->count()),
            'on_time' => $onTime,
            'late' => $rows->whereIn('window_state', ['late', 'unlocked'])->count(),
            'accepted' => $rows->where('status', AgentDailySettlement::STATUS_ACCEPTED)->count(),
            'rejected' => $rows->where('status', AgentDailySettlement::STATUS_REJECTED)->count(),
            'on_time_pct' => $rows->count() > 0 ? round($onTime * 100 / $rows->count(), 1) : null,
            'rows' => $rows->map(fn (AgentDailySettlement $r) => [
                'date' => $r->settlement_date->toDateString(),
                'status' => $r->status,
                'status_label' => AgentDailySettlement::STATUS_LABELS[$r->status] ?? $r->status,
                'window_state' => $r->window_state,
                'conversion' => $r->conversion,
                'conversion_label' => AgentDailySettlement::CONVERSION_LABELS[$r->conversion] ?? '',
                'conversion_amount' => (string) $r->conversion_amount,
                'deposits_total' => (string) $r->deposits_total,
                'withdrawals_total' => (string) $r->withdrawals_total,
            ])->values()->all(),
        ];
    }
}
