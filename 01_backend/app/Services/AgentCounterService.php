<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\EMoney;
use App\Models\User;
use App\Traits\PostsToLedger;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-AGENT-PORTAL-001 — شبّاك الفرع: إيداع وسحب.
 *
 * ══════════════════════════════════════════════════════════════
 * **العمليّة الواحدة تمسّ ثلاثة أرصدة، وكلّها في معاملةٍ واحدة:**
 *
 * الإيداع (العميل يسلّم نقداً):
 *   ١) محفظة الفرع الإلكترونية  ↓
 *   ٢) محفظة العميل            ↑
 *   ٣) نقد الدرج               ↑
 *
 * السحب (الفرع يسلّم نقداً):
 *   ١) محفظة العميل            ↓
 *   ٢) محفظة الفرع الإلكترونية ↑
 *   ٣) نقد الدرج               ↓
 *
 * ولو خرج أحدها من المعاملة لانحرف الجرد: يُخصَم رصيد العميل ولا يُسجَّل
 * خروج النقد، فيبدو الدرج ممتلئاً وهو فارغ — ويُكتشف الفرق آخر اليوم بلا
 * تفسير.
 * ══════════════════════════════════════════════════════════════
 *
 * **والتحقّق يسبق كلّ شيء:** يُسأل «هل يكفي النقد؟» و«هل يكفي الرصيد؟»
 * **قبل** أن يُلمس أيّ رصيد. فخصمٌ ثمّ اكتشافُ أنّ الدرج فارغ يترك العميل
 * بلا مالٍ ولا أوراق.
 */
class AgentCounterService
{
    use PostsToLedger;

    public function __construct(
        private readonly AgentTillService $till,
        private readonly AuditService $audit,
    ) {
    }

    // ── إيداع ───────────────────────────────────────────────────────────

    /**
     * العميل يسلّم نقداً ويُضاف إلى محفظته.
     *
     * والقيد هنا هو **الرصيد الإلكترونيّ للفرع**: الفرع يمنح العميل رصيداً
     * من رصيده هو. فبلا رصيدٍ إلكترونيّ لا إيداع، مهما امتلأ الدرج.
     */
    public function deposit(
        AgentBranch $branch, User $customer, string $amount, User $actor, ?string $note = null
    ): array {
        $this->assertOperable($branch, $customer, $amount);

        $branchBalance = (string) (EMoney::where('user_id', $branch->branch_user_id)
            ->value('current_balance') ?? '0');

        if (bccomp($branchBalance, $amount, 4) < 0) {
            throw new DomainException(sprintf(
                'رصيد الفرع الإلكترونيّ %s ولا يكفي لإيداع %s — اطلب شحن رصيد من الوكيل الأمّ',
                $branchBalance, $amount,
            ));
        }

        $reference = 'DEP-' . strtoupper(Str::random(10));

        return DB::transaction(function () use ($branch, $customer, $amount, $actor, $note, $reference) {
            // القفل على الصفّين معاً وبترتيبٍ ثابت (الأصغر أوّلاً): ترتيبان
            // مختلفان في مسارين متزامنين يُنتجان جمود قفل.
            $ids = [$branch->branch_user_id, $customer->id];
            sort($ids);
            EMoney::whereIn('user_id', $ids)->lockForUpdate()->get();

            EMoney::where('user_id', $branch->branch_user_id)->decrement('current_balance', $amount);
            EMoney::where('user_id', $customer->id)->increment('current_balance', $amount);

            // النقد يدخل الدرج — الطرف الثالث الذي كان غائباً.
            $this->till->record(
                $branch, 'in', 'customer_deposit', $amount, $actor,
                customerId: $customer->id, reference: $reference,
                note: $note,
            );

            // الترحيل إلزاميّ وداخل المعاملة — لا `safeLedgerPost`.
            $this->ledgerTransfer(
                fromUserId: (int) $branch->branch_user_id,
                toUserId: (int) $customer->id,
                amount: $amount,
                sourceType: 'agent_deposit',
                sourceId: $reference,
                description: "إيداع نقديّ عبر فرع {$branch->name}",
                metadata: ['branch_id' => $branch->id, 'actor_id' => $actor->id],
            );

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $actor->id,
                'subject_type' => 'user',
                'subject_id' => (string) $customer->id,
                'action' => 'AGENT_CASH_DEPOSIT',
                'decision_code' => 'DEPOSIT',
                'severity' => 'critical',
                'context' => [
                    'branch_id' => $branch->id, 'amount' => $amount, 'reference' => $reference,
                ],
            ]);

            return [
                'reference' => $reference,
                'amount' => $amount,
                'customer_balance' => (string) EMoney::where('user_id', $customer->id)
                    ->value('current_balance'),
            ];
        });
    }

    // ── سحب ─────────────────────────────────────────────────────────────

    /**
     * العميل يسحب نقداً من الفرع.
     *
     * والقيد هنا **النقد في الدرج** لا الرصيد الإلكترونيّ — والفرق بينهما
     * هو ما كان النظام يغفله.
     */
    public function withdraw(
        AgentBranch $branch, User $customer, string $amount, User $actor, ?string $note = null
    ): array {
        $this->assertOperable($branch, $customer, $amount);

        $customerBalance = (string) (EMoney::where('user_id', $customer->id)
            ->value('current_balance') ?? '0');

        if (bccomp($customerBalance, $amount, 4) < 0) {
            throw new DomainException("رصيد العميل {$customerBalance} ولا يكفي لسحب {$amount}");
        }

        // **قبل أيّ خصم.** انظر شرح الصنف.
        $this->till->assertCanPayCash($branch, $amount);

        $reference = 'WDR-' . strtoupper(Str::random(10));

        return DB::transaction(function () use ($branch, $customer, $amount, $actor, $note, $reference) {
            $ids = [$branch->branch_user_id, $customer->id];
            sort($ids);
            EMoney::whereIn('user_id', $ids)->lockForUpdate()->get();

            // يُعاد الفحص داخل القفل: بين الفحص الأوّل وبدء المعاملة قد
            // يكون شبّاكٌ آخر في الفرع نفسه أفرغ الدرج.
            $this->till->assertCanPayCash($branch, $amount);

            EMoney::where('user_id', $customer->id)->decrement('current_balance', $amount);
            EMoney::where('user_id', $branch->branch_user_id)->increment('current_balance', $amount);

            $this->till->record(
                $branch, 'out', 'customer_withdraw', $amount, $actor,
                customerId: $customer->id, reference: $reference, note: $note,
            );

            $this->ledgerTransfer(
                fromUserId: (int) $customer->id,
                toUserId: (int) $branch->branch_user_id,
                amount: $amount,
                sourceType: 'agent_withdraw',
                sourceId: $reference,
                description: "سحب نقديّ من فرع {$branch->name}",
                metadata: ['branch_id' => $branch->id, 'actor_id' => $actor->id],
            );

            $this->audit->record([
                'actor_type' => 'agent',
                'actor_user_id' => $actor->id,
                'subject_type' => 'user',
                'subject_id' => (string) $customer->id,
                'action' => 'AGENT_CASH_WITHDRAW',
                'decision_code' => 'WITHDRAW',
                'severity' => 'critical',
                'context' => [
                    'branch_id' => $branch->id, 'amount' => $amount, 'reference' => $reference,
                ],
            ]);

            return [
                'reference' => $reference,
                'amount' => $amount,
                'customer_balance' => (string) EMoney::where('user_id', $customer->id)
                    ->value('current_balance'),
            ];
        });
    }

    // ── الفحوص المشتركة ─────────────────────────────────────────────────

    private function assertOperable(AgentBranch $branch, User $customer, string $amount): void
    {
        if (bccomp($amount, '0', 4) <= 0) {
            throw new DomainException('المبلغ يجب أن يكون موجباً');
        }

        if (!$branch->is_active) {
            throw new DomainException('الفرع موقوف — لا تُنفَّذ عليه عمليات');
        }

        // حالةُ العميل تُفحص هنا لا في الواجهة: موظّف الشبّاك قد لا يقرأ
        // التحذير، والمنع يجب أن يقع عند المال لا عند الشاشة.
        $status = app(CustomerStatusResolver::class)->resolve($customer);

        if (in_array($status['status'], [
            CustomerStatusResolver::BLACKLISTED,
            CustomerStatusResolver::CLOSED,
            CustomerStatusResolver::DECEASED,
            CustomerStatusResolver::FROZEN,
        ], true)) {
            throw new DomainException(
                "لا تُنفَّذ عمليات على هذا العميل — حالته: {$status['label']}",
            );
        }

        // لا يُنفِّذ الموظّف عمليةً على حسابه هو.
        if ((int) $customer->id === (int) $branch->branch_user_id) {
            throw new DomainException('لا تُنفَّذ العملية على حساب الفرع نفسه');
        }
    }
}
