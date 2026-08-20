<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\EMoney;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-AGENT-PORTAL-002 — عمولات الوكيل وتسوياته وتقاريره (الفصل ٠٣).
 *
 * **العمولة تُشتقّ من الدفتر لا تُجمَع من عدّاد.**
 *
 * `agent_float_logs.commission_earned` عمودٌ تراكميّ يُزاد عند كلّ عملية.
 * وهو أسرع في القراءة وأسهل في الانحراف: عمليةٌ تفشل بعد زيادته، أو تُعاد
 * محاولتها فيُزاد مرّتين، فيصير الوكيل يطالب بما لم يكسبه أو يخسر ما كسبه.
 *
 * وقيود الدفتر لا تُزاد ولا تُنقص — تُلحَق. فاشتقاق العمولة منها يجعل الرقم
 * قابلاً لإعادة الحساب في أيّ وقت، ومطابقاً لما يراه المدقّق.
 */
class AgentReportService
{
    public function __construct(
        private readonly AgentTillService $till,
    ) {
    }

    /**
     * عمولات الفرع في مدّة.
     *
     * تُقرأ من `metadata` قيود الإيداع والسحب — حيث كُتبت وقت العملية ولا
     * تتغيّر بعدها.
     */
    public function commissions(AgentBranch $branch, ?string $from = null, ?string $to = null): array
    {
        $from ??= now()->startOfMonth()->toDateString();
        $to ??= now()->toDateString();

        $rows = DB::table('ledger_journal_entries')
            ->whereIn('source_type', ['agent_deposit', 'agent_withdraw'])
            ->whereBetween('posted_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->get(['source_type', 'total_amount', 'metadata', 'posted_at']);

        $byDay = [];
        $totalCommission = '0';
        $totalFee = '0';
        $count = 0;

        foreach ($rows as $r) {
            $meta = is_string($r->metadata) ? json_decode($r->metadata, true) : (array) $r->metadata;

            if ((int) ($meta['branch_id'] ?? 0) !== (int) $branch->id) {
                continue;
            }

            $day = substr((string) $r->posted_at, 0, 10);
            $commission = (string) ($meta['commission'] ?? '0');
            $fee = (string) ($meta['fee'] ?? '0');

            $byDay[$day] ??= ['date' => $day, 'deposits' => 0, 'withdrawals' => 0,
                'volume' => '0', 'fee' => '0', 'commission' => '0'];

            $isDeposit = $r->source_type === 'agent_deposit';
            $byDay[$day][$isDeposit ? 'deposits' : 'withdrawals']++;
            $byDay[$day]['volume'] = bcadd($byDay[$day]['volume'], (string) $r->total_amount, 4);
            $byDay[$day]['fee'] = bcadd($byDay[$day]['fee'], $fee, 4);
            $byDay[$day]['commission'] = bcadd($byDay[$day]['commission'], $commission, 4);

            $totalCommission = bcadd($totalCommission, $commission, 4);
            $totalFee = bcadd($totalFee, $fee, 4);
            $count++;
        }

        krsort($byDay);

        return [
            'from' => $from,
            'to' => $to,
            'days' => array_values($byDay),
            'total_commission' => $totalCommission,
            'total_fee' => $totalFee,
            'operations' => $count,
            // حصّة المنصّة تُعرَض أيضاً: وكيلٌ يرى ما كسبه ولا يرى ما دفعه
            // يظنّ الرسم كلّه له، فيحتجّ يوم التسوية.
            'platform_share' => bcsub($totalFee, $totalCommission, 4),
        ];
    }

    /**
     * تسويات الوكيل.
     *
     * والعمولة المستحقّة **غير** الرصيد الإلكترونيّ: ذاك سيولةُ تشغيلٍ
     * تتحرّك مع كلّ عملية، وهذه أرباحٌ تُسحب. وخلطُهما يجعل وكيلاً يسحب
     * أرباحه فيعجز عن الإيداع.
     */
    public function settlements(User $agent, int $limit = 50): array
    {
        if (!Schema::hasTable('agent_settlements')) {
            return ['items' => [], 'summary' => []];
        }

        $q = DB::table('agent_settlements')->where('agent_user_id', $agent->id);

        $items = (clone $q)->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($s) => [
                'ulid' => (string) $s->settlement_ulid,
                'type' => (string) $s->settlement_type,
                'amount' => (string) $s->amount,
                'commission' => (string) ($s->commission_amount ?? '0'),
                'status' => (string) $s->status,
                'method' => (string) ($s->payment_method ?? '—'),
                'reference' => $s->payment_reference,
                'note' => $s->note,
                'created_at' => (string) $s->created_at,
                'completed_at' => $s->completed_at,
            ])->all();

        $sum = fn (string $status) => (string) ((clone $q)->where('status', $status)->sum('amount') ?: '0');

        return [
            'items' => $items,
            'summary' => [
                'pending' => $sum('pending'),
                'completed' => $sum('completed'),
                'pending_count' => (clone $q)->where('status', 'pending')->count(),
            ],
        ];
    }

    /**
     * تقرير الفرع اليوميّ — ما يُغلق به الموظّف يومه.
     *
     * **ويُبنى على المطابقة لا على العدّ:** الرقم المهمّ ليس كم أُودع وكم
     * سُحب، بل **هل يطابق ما في الدرج ما يقوله النظام**. فالفرق هو الخبر،
     * والباقي سياق.
     */
    public function dailyReport(AgentBranch $branch, ?string $date = null): array
    {
        $date ??= now()->toDateString();
        $start = $date . ' 00:00:00';
        $end = $date . ' 23:59:59';

        $moves = AgentCashMovement::where('branch_id', $branch->id)
            ->whereBetween('created_at', [$start, $end])
            ->orderBy('id')->get();

        $sum = fn (string $dir, ?string $reason = null) => (string) $moves
            ->where('direction', $dir)
            ->when($reason, fn ($c) => $c->where('reason', $reason))
            ->reduce(fn ($carry, $m) => bcadd($carry, (string) $m->amount, 4), '0');

        $opening = $moves->isNotEmpty()
            ? (string) $moves->first()->balance_before
            : (string) $this->till->tillFor($branch)->cash_on_hand;

        // الرصيد المتوقَّع يُحسب من الافتتاحيّ والحركة — لا يُقرأ من الخزنة.
        // وقراءتُه من الخزنة تجعل المطابقة تقارن الرقم بنفسه.
        $expected = bcsub(bcadd($opening, $sum('in'), 4), $sum('out'), 4);

        $till = $this->till->tillFor($branch);
        // «المسجَّل» هو ما تقوله الخزنة الآن، لا ما قالته آخر حركة. لو عُدّل
        // العمود أو حصل خلل بعد الحركة، قراءة balance_after تجعل التقرير
        // يقارن تاريخ الحركة بنفسه ويخفي الفرق.
        $closing = (string) $till->cash_on_hand;

        return [
            'date' => $date,
            'branch' => ['id' => (int) $branch->id, 'name' => $branch->name, 'code' => $branch->code],
            'cash' => [
                'opening' => $opening,
                'deposits' => $sum('in', 'customer_deposit'),
                'withdrawals' => $sum('out', 'customer_withdraw'),
                'treasury_in' => $sum('in', 'treasury_in'),
                'treasury_out' => $sum('out', 'treasury_out'),
                'adjustments' => bcsub($sum('in', 'count_adjustment'), $sum('out', 'count_adjustment'), 4),
                'closing' => $closing,
                'expected' => $expected,
                // فرق النقد قيمة مالية، فلا يُترك للمتصفح ليحسبه بـ Number
                // ويخسر الدقّة في مبالغ الريال الكبيرة.
                'difference' => bcsub($expected, $closing, 4),
                // الخبر: هل يطابق المحسوب ما هو مسجَّل؟
                'reconciles' => bccomp($expected, $closing, 4) === 0,
            ],
            'counts' => [
                'deposits' => $moves->where('reason', 'customer_deposit')->count(),
                'withdrawals' => $moves->where('reason', 'customer_withdraw')->count(),
                'total_movements' => $moves->count(),
            ],
            'emoney_balance' => (string) (EMoney::where('user_id', $branch->branch_user_id)
                ->value('current_balance') ?? '0'),
            'commission' => $this->commissions($branch, $date, $date)['total_commission'],
            'last_counted_at' => $till->last_counted_at?->toIso8601String(),
            // فرعٌ لم يُجرَد اليوم ينتهي يومه بلا تأكيدٍ من إنسان.
            'counted_today' => $till->last_counted_at !== null
                && $till->last_counted_at->toDateString() === $date,
        ];
    }

    /** ساعات العمل — فرعٌ مغلق لا يُوجَّه إليه عميل. */
    public function setWorkingHours(AgentBranch $branch, array $hours): AgentBranch
    {
        $days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $clean = [];

        foreach ($days as $d) {
            $row = $hours[$d] ?? null;
            if (!is_array($row) || empty($row['open']) || empty($row['close'])) {
                $clean[$d] = null;   // مغلق
                continue;
            }

            foreach (['open', 'close'] as $k) {
                if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', (string) $row[$k])) {
                    throw new DomainException("صيغة وقت غير صحيحة في {$d} — استعمل HH:MM");
                }
            }

            // فتحٌ بعد إغلاق يمرّ في الحفظ ويُغلق الفرع طول اليوم في التنفيذ.
            if ($row['open'] >= $row['close']) {
                throw new DomainException("وقت الفتح في {$d} ليس قبل الإغلاق");
            }

            $clean[$d] = ['open' => $row['open'], 'close' => $row['close']];
        }

        $branch->working_hours = $clean;
        $branch->save();

        return $branch->fresh();
    }
}
