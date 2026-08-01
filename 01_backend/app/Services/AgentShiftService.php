<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentCashMovement;
use App\Models\Agent\AgentCashTill;
use App\Models\Agent\AgentShift;
use App\Models\Agent\AgentStaff;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AGENT-STAFF-001 — ورديّة الصرّاف: من فتح الدرج إلى جرده.
 *
 * **خزنة الفرع ودرج الصرّاف مالان منفصلان.** الصرّاف يأخذ عهدةً من الخزنة
 * أوّل ورديّته فتنقص الخزنة ويزيد درجُه، ويردّ ما عدّه آخرها فيعود العكس.
 * ومجموعُ نقد الفرع = الخزنة + الأدراج المفتوحة.
 *
 * ولولا هذا الفصل لظلّ عجزُ خمسة آلافٍ في فرعٍ فيه عشرون شبّاكاً بلا صاحب:
 * يُقسَّم على عشرين بريئاً أو يُنسى. والدرج هو ما يجعل للجرد أثراً.
 */
class AgentShiftService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * فتح ورديّة: تخرج العهدة من خزنة الفرع إلى الدرج.
     *
     * وتُفتح **للصرّاف نفسه** لا لغيره: ورديّةٌ يفتحها مديرٌ باسم موظّفٍ غائب
     * تُنتج درجاً مسؤولُه لم يستلمه.
     */
    public function open(AgentStaff $teller, string $openingFloat): AgentShift
    {
        if (!$teller->isTeller()) {
            throw new DomainException('الورديّات للصرّافين — الإدارة والمدير لا يقفان على شبّاك');
        }

        if (!$teller->is_active) {
            throw new DomainException('الحساب معطَّل');
        }

        if (!$teller->branch_id) {
            throw new DomainException('الصرّاف بلا فرع — راجع إدارة شركتك');
        }

        if ($teller->openShift()) {
            throw new DomainException('لديك ورديّة مفتوحة بالفعل — أغلقها أوّلاً');
        }

        if (bccomp($openingFloat, '0', 4) < 0) {
            throw new DomainException('العهدة لا تكون سالبة');
        }

        $branch = AgentBranch::findOrFail($teller->branch_id);

        return DB::transaction(function () use ($teller, $branch, $openingFloat) {
            $shift = AgentShift::create([
                'branch_id' => $branch->id,
                'staff_id' => $teller->id,
                'opening_float' => $openingFloat,
                'cash_on_hand' => $openingFloat,
                'status' => AgentShift::STATUS_OPEN,
                'opened_at' => now(),
            ]);

            // عهدةٌ صفر ورديّةٌ صحيحة (صرّافٌ يبدأ بلا نقد ويجمعه من
            // الإيداعات) — فلا تُسجَّل حركةُ خزنةٍ بلا مبلغ.
            if (bccomp($openingFloat, '0', 4) > 0) {
                $this->moveBranchSafe($branch, 'out', 'shift_open', $openingFloat, $teller, $shift->id,
                    "عهدة افتتاحيّة — {$teller->name} ({$teller->username})");
            }

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $branch->agent_user_id,
                'action' => 'agent.shift.open',
                'subject_type' => 'agent_shift',
                'subject_id' => $shift->id,
                'metadata' => ['staff_id' => $teller->id, 'branch_id' => $branch->id,
                    'opening_float' => $openingFloat],
            ]);

            return $shift->fresh();
        });
    }

    /**
     * إغلاق ورديّة بجردٍ فعليّ.
     *
     * **المتوقَّع يُحسب من العهدة والحركة، لا يُقرأ من `cash_on_hand`.**
     * قراءتُه من العمود تجعل الجرد يقارن الرقم بنفسه فيخرج الفرق صفراً
     * دائماً — حارسٌ يمرّ للسبب الخطأ، وهو أسوأ من غيابه.
     */
    public function close(AgentStaff $actor, AgentShift $shift, string $countedCash, string $note = ''): AgentShift
    {
        if (!$shift->isOpen()) {
            throw new DomainException('الورديّة مغلقة بالفعل');
        }

        // الصرّاف يغلق ورديّته، والمدير يغلق ورديّات فرعه (صرّافٌ غادر ولم
        // يغلق يترك درجاً مقفلاً على النظام لا على الواقع).
        $mine = $actor->id === (int) $shift->staff_id;
        $managerOfBranch = ($actor->isBranchManager() || $actor->isHeadOffice())
            && in_array((int) $shift->branch_id, $actor->visibleBranchIds(), true);

        if (!$mine && !$managerOfBranch) {
            throw new DomainException('لا تملك إغلاق هذه الورديّة');
        }

        if (bccomp($countedCash, '0', 4) < 0) {
            throw new DomainException('المبلغ المعدود لا يكون سالباً');
        }

        $expected = $shift->expectedCash();
        $variance = bcsub($countedCash, $expected, 4);

        // فرقٌ بلا تفسير هو أوّل ما يُبحث عنه في أيّ تحقيق. فيُطلب سببٌ
        // مكتوب — والمطابق لا يُطلب منه شيء.
        if (bccomp($variance, '0', 4) !== 0 && mb_strlen(trim($note)) < 10) {
            throw new DomainException(
                'الفرق ' . $variance . ' — اكتب سبباً لا يقلّ عن عشرة أحرف',
            );
        }

        $branch = AgentBranch::findOrFail($shift->branch_id);

        return DB::transaction(function () use ($actor, $shift, $branch, $countedCash, $expected, $variance, $note) {
            $locked = AgentShift::where('id', $shift->id)->lockForUpdate()->first();

            if (!$locked->isOpen()) {
                throw new DomainException('الورديّة أُغلقت للتوّ من مكانٍ آخر');
            }

            // الفرق يُسجَّل حركةً بسببه ولا يُبتلع. ولو كُتب المعدود فوق
            // الرصيد لاختفى العجز أو الفائض بلا أثر.
            if (bccomp($variance, '0', 4) !== 0) {
                AgentCashMovement::create([
                    'branch_id' => $branch->id,
                    'shift_id' => $locked->id,
                    'staff_id' => $locked->staff_id,
                    'direction' => bccomp($variance, '0', 4) > 0 ? 'in' : 'out',
                    'reason' => 'count_adjustment',
                    'amount' => ltrim($variance, '-'),
                    'balance_before' => $expected,
                    'balance_after' => $countedCash,
                    'reference' => 'SHIFT-' . $locked->id,
                    'actor_user_id' => $branch->branch_user_id,
                    'note' => $note,
                    'created_at' => now(),
                ]);
            }

            $locked->fill([
                'counted_cash' => $countedCash,
                'variance' => $variance,
                'cash_on_hand' => '0',
                'close_note' => $note ?: null,
                'status' => AgentShift::STATUS_CLOSED,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ])->save();

            // ما عدّه الصرّاف يعود إلى الخزنة — المعدود لا المتوقَّع، فما
            // في يده هو ما يُسلَّم.
            if (bccomp($countedCash, '0', 4) > 0) {
                $this->moveBranchSafe($branch, 'in', 'shift_close', $countedCash, $actor, $locked->id,
                    'تسليم ورديّة #' . $locked->id);
            }

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $branch->agent_user_id,
                'action' => 'agent.shift.close',
                'severity' => bccomp($variance, '0', 4) === 0 ? 'info' : 'critical',
                'subject_type' => 'agent_shift',
                'subject_id' => $locked->id,
                'metadata' => [
                    'staff_id' => $locked->staff_id, 'branch_id' => $branch->id,
                    'expected' => $expected, 'counted' => $countedCash,
                    'variance' => $variance, 'closed_by' => $actor->id,
                ],
            ]);

            return $locked->fresh();
        });
    }

    /**
     * حركة نقدٍ في درج الصرّاف — إيداعُ عميلٍ أو سحبُه.
     *
     * تُسجَّل داخل قفل: شبّاكان على الدرج نفسه مستحيل، لكنّ نافذتين
     * للمتصفّح نفسه ممكنتان — والنقر المزدوج يقرأ الرصيد نفسه مرّتين.
     */
    public function recordDrawer(
        AgentShift $shift,
        string $direction,
        string $reason,
        string $amount,
        ?int $customerId = null,
        ?string $reference = null,
        ?string $note = null,
    ): AgentCashMovement {
        if (bccomp($amount, '0', 4) <= 0) {
            throw new DomainException('المبلغ يجب أن يكون موجباً');
        }

        return DB::transaction(function () use (
            $shift, $direction, $reason, $amount, $customerId, $reference, $note
        ) {
            $locked = AgentShift::where('id', $shift->id)->lockForUpdate()->first();

            if (!$locked || !$locked->isOpen()) {
                throw new DomainException('الورديّة مغلقة — افتح ورديّة قبل العمل على الشبّاك');
            }

            $before = (string) $locked->cash_on_hand;
            $after = $direction === 'in' ? bcadd($before, $amount, 4) : bcsub($before, $amount, 4);

            if ($direction === 'out' && bccomp($after, '0', 4) < 0) {
                throw new DomainException(
                    "النقد في درجك {$before} ولا يكفي لدفع {$amount} — اطلب توريداً من خزنة الفرع",
                );
            }

            $locked->cash_on_hand = $after;

            if ($reason === 'customer_deposit') {
                $locked->deposits_total = bcadd((string) $locked->deposits_total, $amount, 4);
                $locked->deposits_count++;
            } elseif ($reason === 'customer_withdraw') {
                $locked->withdrawals_total = bcadd((string) $locked->withdrawals_total, $amount, 4);
                $locked->withdrawals_count++;
            }

            $locked->save();

            $branch = AgentBranch::find($locked->branch_id);

            return AgentCashMovement::create([
                'branch_id' => $locked->branch_id,
                'shift_id' => $locked->id,
                'staff_id' => $locked->staff_id,
                'direction' => $direction,
                'reason' => $reason,
                'amount' => $amount,
                'balance_before' => $before,
                'balance_after' => $after,
                'reference' => $reference,
                'actor_user_id' => $branch?->branch_user_id,
                'customer_user_id' => $customerId,
                'note' => $note,
                'created_at' => now(),
            ]);
        });
    }

    /** توريدٌ بين خزنة الفرع ودرجٍ مفتوح أثناء الورديّة. */
    public function refill(AgentStaff $actor, AgentShift $shift, string $amount, string $direction): AgentShift
    {
        if (!in_array($direction, ['to_drawer', 'to_safe'], true)) {
            throw new DomainException('اتّجاه غير معروف');
        }

        if (!$shift->isOpen()) {
            throw new DomainException('الورديّة مغلقة');
        }

        if (!in_array((int) $shift->branch_id, $actor->visibleBranchIds(), true)
            && $actor->id !== (int) $shift->staff_id) {
            throw new DomainException('لا تملك هذه الورديّة');
        }

        $branch = AgentBranch::findOrFail($shift->branch_id);

        return DB::transaction(function () use ($actor, $shift, $branch, $amount, $direction) {
            if ($direction === 'to_drawer') {
                $this->moveBranchSafe($branch, 'out', 'treasury_out', $amount, $actor, $shift->id,
                    'توريد إلى درج ورديّة #' . $shift->id);
                $this->recordDrawer($shift, 'in', 'treasury_in', $amount,
                    reference: 'REFILL-' . $shift->id);
            } else {
                $this->recordDrawer($shift, 'out', 'treasury_out', $amount,
                    reference: 'REFILL-' . $shift->id);
                $this->moveBranchSafe($branch, 'in', 'treasury_in', $amount, $actor, $shift->id,
                    'تسليم من درج ورديّة #' . $shift->id);
            }

            // العهدة تُعدَّل بالتوريد وإلّا صار المتوقَّع عند الإغلاق خاطئاً
            // بمقدار كلّ ما ورد أو سُلِّم أثناء الورديّة.
            $fresh = $shift->fresh();
            $fresh->opening_float = $direction === 'to_drawer'
                ? bcadd((string) $fresh->opening_float, $amount, 4)
                : bcsub((string) $fresh->opening_float, $amount, 4);
            $fresh->save();

            return $fresh->fresh();
        });
    }

    /** حالة الورديّة كما تُعرَض للصرّاف. */
    public function state(AgentShift $shift): array
    {
        return [
            'id' => (int) $shift->id,
            'status' => $shift->status,
            'branch_id' => (int) $shift->branch_id,
            'opening_float' => (string) $shift->opening_float,
            'cash_on_hand' => (string) $shift->cash_on_hand,
            'deposits_total' => (string) $shift->deposits_total,
            'withdrawals_total' => (string) $shift->withdrawals_total,
            'deposits_count' => (int) $shift->deposits_count,
            'withdrawals_count' => (int) $shift->withdrawals_count,
            'expected_cash' => $shift->expectedCash(),
            // الفرق لا يُعرض قبل الجرد: رقمٌ يظهر قبل أن يعدّ أحدٌ شيئاً
            // يُقرأ تأكيداً، فيَعدّ الصرّاف ليطابقه بدل أن يعدّ ما في يده.
            'counted_cash' => $shift->counted_cash !== null ? (string) $shift->counted_cash : null,
            'variance' => $shift->variance !== null ? (string) $shift->variance : null,
            'opened_at' => $shift->opened_at?->toDateTimeString(),
            'closed_at' => $shift->closed_at?->toDateTimeString(),
        ];
    }

    /** ورديّات فرعٍ — ما يراه المدير عن شبابيكه. */
    public function forBranch(int $branchId, ?string $date = null, int $limit = 100): array
    {
        $q = AgentShift::where('branch_id', $branchId)->with('staff');

        if ($date) {
            $q->whereBetween('opened_at', [$date . ' 00:00:00', $date . ' 23:59:59']);
        }

        return $q->orderByDesc('id')->limit($limit)->get()
            ->map(fn (AgentShift $s) => array_merge($this->state($s), [
                'staff' => $s->staff?->name,
                'username' => $s->staff?->username,
            ]))->all();
    }

    /** حركةُ خزنة الفرع — مقابلةُ حركةِ الدرج. */
    private function moveBranchSafe(
        AgentBranch $branch, string $direction, string $reason,
        string $amount, AgentStaff $actor, int $shiftId, string $note,
    ): void {
        $till = AgentCashTill::where('branch_id', $branch->id)->lockForUpdate()->first();

        if (!$till) {
            $till = AgentCashTill::create(['branch_id' => $branch->id, 'cash_on_hand' => '0']);
        }

        $before = (string) $till->cash_on_hand;
        $after = $direction === 'in' ? bcadd($before, $amount, 4) : bcsub($before, $amount, 4);

        if ($direction === 'out' && bccomp($after, '0', 4) < 0) {
            throw new DomainException(
                "خزنة الفرع فيها {$before} ولا تكفي لإخراج {$amount}",
            );
        }

        $till->cash_on_hand = $after;
        $till->save();

        AgentCashMovement::create([
            'branch_id' => $branch->id,
            'shift_id' => $shiftId,
            'staff_id' => $actor->id,
            'direction' => $direction,
            'reason' => $reason,
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
            'reference' => 'SHIFT-' . $shiftId,
            'actor_user_id' => $branch->branch_user_id,
            'note' => $note,
            'created_at' => now(),
        ]);
    }
}
