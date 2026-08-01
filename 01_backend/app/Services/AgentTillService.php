<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentCashTill;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AGENT-PORTAL-001 — خزنة النقد الورقيّ في فرع الوكيل.
 *
 * ══════════════════════════════════════════════════════════════
 * **القيد الذي كان غائباً عن النظام كلّه.**
 *
 * للوكيل رصيدان يتحرّكان في اتّجاهين **متعاكسين**:
 *
 *   • **الرصيد الإلكترونيّ** يحدّ **الإيداع**: حين يودع العميل نقداً، يمنحه
 *     الوكيل رصيداً من رصيده هو. فبلا رصيدٍ إلكترونيّ لا إيداع.
 *
 *   • **النقد الورقيّ** يحدّ **السحب**: حين يسحب العميل، يسلّمه الفرع
 *     أوراقاً من درجه. فبلا نقدٍ لا سحب — مهما كان الرصيد الإلكترونيّ
 *     كبيراً.
 *
 * وكان النظام يتتبّع الأوّل وحده. فيقبل طلب سحبٍ لأنّ رصيد الوكيل
 * الإلكترونيّ **سيزيد** به، ويقف العميل أمام موظّفٍ درجُه فارغ.
 *
 * وهذه ليست حالةً نادرة: هي الحالة الطبيعية آخر يومٍ في فرعٍ نشط، حيث
 * السحوبات تفوق الإيداعات فيفرغ الدرج بينما الرصيد الإلكترونيّ يتضخّم.
 * ══════════════════════════════════════════════════════════════
 */
class AgentTillService
{
    public function __construct(
        private readonly AuditService $audit,
    ) {
    }

    public function tillFor(AgentBranch $branch): AgentCashTill
    {
        return AgentCashTill::firstOrCreate(
            ['branch_id' => $branch->id],
            ['cash_on_hand' => '0', 'max_cash_on_hand' => '0', 'min_cash_alert' => '0'],
        );
    }

    /**
     * هل يستطيع الفرع دفع هذا المبلغ نقداً **الآن**؟
     *
     * يُسأل هذا **قبل** أن يُوعَد العميل، لا بعد أن يُخصَم رصيده. فخصمٌ ثمّ
     * اكتشافُ أنّ الدرج فارغ يترك العميل بلا مالٍ ولا أوراق.
     */
    public function assertCanPayCash(AgentBranch $branch, string $amount): void
    {
        $till = $this->tillFor($branch);

        if (!$till->canPayOut($amount)) {
            throw new DomainException(sprintf(
                'النقد المتوفّر في الفرع %s ولا يكفي لدفع %s — وجّه العميل إلى فرعٍ آخر أو أورِد نقداً',
                (string) $till->cash_on_hand, $amount,
            ));
        }
    }

    // ── الحركة ──────────────────────────────────────────────────────────

    /**
     * تُسجَّل الحركة **داخل قفل**: فرعان يعملان على الدرج نفسه في اللحظة
     * ذاتها يقرآن الرصيد نفسه ويكتبان فوق بعضهما — فيضيع أحد المبلغين من
     * الجرد ويظهر الفرق آخر اليوم بلا تفسير.
     */
    public function record(
        AgentBranch $branch,
        string $direction,
        string $reason,
        string $amount,
        User $actor,
        ?int $customerId = null,
        ?string $reference = null,
        ?string $note = null,
    ): AgentCashMovement {
        if (bccomp($amount, '0', 4) <= 0) {
            throw new DomainException('المبلغ يجب أن يكون موجباً');
        }

        return DB::transaction(function () use (
            $branch, $direction, $reason, $amount, $actor, $customerId, $reference, $note
        ) {
            $till = AgentCashTill::where('branch_id', $branch->id)
                ->lockForUpdate()->first()
                ?? $this->tillFor($branch);

            $before = (string) $till->cash_on_hand;

            $after = $direction === 'in'
                ? bcadd($before, $amount, 4)
                : bcsub($before, $amount, 4);

            // النقد لا يكون سالباً — ورقةٌ لا تُدفع من العدم. ولو سُمح به
            // لصار الجرد يوازن حساباً وهمياً بدل أن يكشف عجزاً.
            if ($direction === 'out' && bccomp($after, '0', 4) < 0) {
                throw new DomainException(
                    "النقد المتوفّر {$before} ولا يكفي لإخراج {$amount}",
                );
            }

            $till->cash_on_hand = $after;
            $till->save();

            $movement = AgentCashMovement::create([
                'branch_id' => $branch->id,
                'direction' => $direction,
                'reason' => $reason,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference' => $reference,
                'actor_user_id' => $actor->id,
                'customer_user_id' => $customerId,
                'note' => $note,
                'created_at' => now(),
            ]);

            return $movement;
        });
    }

    // ── الجرد ───────────────────────────────────────────────────────────

    /**
     * جردٌ فعليّ: يُعدّ الموظّف ما في الدرج ويُدخل الرقم.
     *
     * **والفرق لا يُبتلَع بل يُسجَّل حركةً بسببها.** لو كُتب الرقم المعدود
     * مباشرةً فوق الرصيد لاختفى العجز أو الفائض بلا أثر — وهو أوّل ما يُبحث
     * عنه في أيّ تحقيق. فيُسجَّل فرقُ الجرد كحركةٍ لها اتّجاهٌ وسببٌ ومنفِّذ.
     */
    public function count(AgentBranch $branch, User $actor, string $countedAmount, string $note): array
    {
        if (mb_strlen(trim($note)) < 10) {
            throw new DomainException('ملاحظة الجرد إلزامية — تفسير الفرق هو الفائدة كلّها');
        }

        $till = $this->tillFor($branch);
        $expected = (string) $till->cash_on_hand;
        $diff = bcsub($countedAmount, $expected, 4);

        if (bccomp($diff, '0', 4) !== 0) {
            $this->record(
                $branch,
                bccomp($diff, '0', 4) > 0 ? 'in' : 'out',
                'count_adjustment',
                bccomp($diff, '0', 4) > 0 ? $diff : bcmul($diff, '-1', 4),
                $actor,
                note: 'تسوية جرد: ' . trim($note),
            );

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $actor->id,
                'subject_type' => 'agent_branch',
                'subject_id' => (string) $branch->id,
                'action' => 'AGENT_TILL_COUNT_DIFF',
                'decision_code' => 'TILL_DIFF',
                'reason' => mb_substr($note, 0, 500),
                // فرقُ جردٍ في فرعٍ نقديّ حدثٌ حرج لا معلومة تشغيلية.
                'severity' => 'critical',
                'context' => ['expected' => $expected, 'counted' => $countedAmount, 'diff' => $diff],
            ]);
        }

        $till->last_counted_at = now();
        $till->last_counted_amount = $countedAmount;
        $till->save();

        return [
            'expected' => $expected,
            'counted' => $countedAmount,
            'difference' => $diff,
            'balanced' => bccomp($diff, '0', 4) === 0,
        ];
    }

    // ── العرض ───────────────────────────────────────────────────────────

    public function summary(AgentBranch $branch): array
    {
        $till = $this->tillFor($branch);
        $today = now()->startOfDay();

        $sum = fn (string $dir, ?string $reason = null) => (string) AgentCashMovement::query()
            ->where('branch_id', $branch->id)
            ->where('direction', $dir)
            ->when($reason, fn ($q) => $q->where('reason', $reason))
            ->where('created_at', '>=', $today)
            ->sum('amount');

        // الرصيد الإلكترونيّ يُقرأ معه دائماً: عرضُ النقد وحده يُعيد نصف
        // الصورة، والقيدان مختلفان.
        $emoney = (string) (\App\Models\EMoney::where('user_id', $branch->branch_user_id)
            ->value('current_balance') ?? '0');

        return [
            'cash_on_hand' => (string) $till->cash_on_hand,
            'emoney_balance' => $emoney,
            'max_cash_on_hand' => (string) $till->max_cash_on_hand,
            'min_cash_alert' => (string) $till->min_cash_alert,
            'is_low' => $till->isLow(),
            'is_overloaded' => $till->isOverloaded(),
            'today' => [
                'cash_in' => $sum('in'),
                'cash_out' => $sum('out'),
                'deposits' => $sum('in', 'customer_deposit'),
                'withdrawals' => $sum('out', 'customer_withdraw'),
            ],
            'last_counted_at' => $till->last_counted_at?->toIso8601String(),
            'last_counted_amount' => $till->last_counted_amount !== null
                ? (string) $till->last_counted_amount : null,
            // ما يستطيع الفرع دفعه فعلاً — وهو أقلّ الرصيدين لا أحدهما.
            //
            // فرعٌ رصيده الإلكترونيّ مليون ودرجه فارغ لا يدفع شيئاً، وعكسه
            // لا يودع شيئاً. وعرضُ الأكبر يعِد بما لا يُوفى.
            'effective_payout_capacity' => (string) $till->cash_on_hand,
            'effective_deposit_capacity' => $emoney,
        ];
    }

    public function movements(AgentBranch $branch, int $limit = 100): array
    {
        return AgentCashMovement::with('actor:id,f_name,l_name')
            ->where('branch_id', $branch->id)
            ->orderByDesc('id')->limit($limit)->get()
            ->map(fn (AgentCashMovement $m) => [
                'id' => (int) $m->id,
                'direction' => $m->direction,
                'reason' => $m->reason,
                'reason_label' => AgentCashMovement::REASON_LABELS[$m->reason] ?? $m->reason,
                'amount' => (string) $m->amount,
                'balance_after' => (string) $m->balance_after,
                'reference' => $m->reference,
                'note' => $m->note,
                'actor' => $m->actor
                    ? trim((string) ($m->actor->f_name . ' ' . $m->actor->l_name)) : '—',
                'at' => $m->created_at?->toIso8601String(),
            ])->all();
    }
}
