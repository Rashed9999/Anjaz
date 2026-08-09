<?php

namespace App\Services;

use App\Models\Agent\AgentBranch;
use App\Models\Agent\AgentShift;
use App\Models\EMoney;
use App\Services\ReceiptService;
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
        private readonly FeeService $fees,
        private readonly AgentShiftService $shifts,
    ) {
    }

    /**
     * الرسم والعمولة — يُحسبان من محرّك الرسوم لا يُثبَّتان هنا.
     *
     * **ولماذا هذا ليس تفصيلاً:** بلا عمولةٍ تعمل شركة الصرافة مجّاناً. ولن
     * تفعل. وأوّل صياغةٍ لهذه الخدمة بُنيت بلا عمولةٍ أصلاً — أي بلا نموذج
     * عملٍ للوكيل، وهو ما كان سيُكتشف عند أوّل شركةٍ تُسأل: «وماذا نكسب؟».
     *
     * وبلا نسخةٍ نشطة يعيد المحرّك صفراً — فالخدمة مجانيّة حتى تُضبط الرسوم
     * من لوحة الإدارة، لا معطَّلة.
     *
     * @return array{fee: string, commission: string, platform: string}
     */
    private function quote(string $code, string $amount, string $zone): array
    {
        $q = $this->fees->calculate($code, $amount, [
            'zone_code' => $zone ?: 'SOUTH',
            'applies_to' => 'agent',
        ]);

        $fee = (string) ($q['fee'] ?? '0');
        $commission = (string) ($q['agent_commission'] ?? '0');
        $platform = (string) ($q['platform_profit'] ?? '0');

        // حصّتان لا تتجاوزان الرسم: خطأٌ في ضبط النسب يجعل المنصّة تدفع من
        // جيبها بلا أن ينتبه أحد — والمجموع يبقى متوازناً في الدفتر لأنّ كلّ
        // قيدٍ متوازنٌ وحده.
        if (bccomp(bcadd($commission, $platform, 4), $fee, 4) > 0) {
            throw new DomainException(
                'خطأ في ضبط الرسوم: مجموع العمولة وحصّة المنصّة يتجاوز الرسم',
            );
        }

        return ['fee' => $fee, 'commission' => $commission, 'platform' => $platform];
    }

    // ── إيداع ───────────────────────────────────────────────────────────

    /**
     * العميل يسلّم نقداً ويُضاف إلى محفظته.
     *
     * والقيد هنا هو **الرصيد الإلكترونيّ للفرع**: الفرع يمنح العميل رصيداً
     * من رصيده هو. فبلا رصيدٍ إلكترونيّ لا إيداع، مهما امتلأ الدرج.
     */
    public function deposit(
        AgentBranch $branch, User $customer, string $amount, User $actor,
        ?string $note = null, ?AgentShift $shift = null
    ): array {
        $this->assertOperable($branch, $customer, $amount);

        $q = $this->quote('AGENT_DEPOSIT', $amount, (string) $branch->account?->zone_code);

        // العميل يسلّم `amount` نقداً ويستلم `amount - fee` في محفظته.
        //
        // فالرسم يُدفع **نقداً** لا من الرصيد: من يودع ١٠٠٠ ويرى ٩٩٥ في
        // محفظته يفهم أنّه دفع ٥. ولو خُصم الرسم من الرصيد بعد إضافته لظهرت
        // حركتان يظنّ العميل الثانية خصماً بلا سبب.
        $net = bcsub($amount, $q['fee'], 4);

        if (bccomp($net, '0', 4) <= 0) {
            throw new DomainException('المبلغ لا يغطّي رسم العملية');
        }

        $branchBalance = (string) (EMoney::where('user_id', $branch->branch_user_id)
            ->value('current_balance') ?? '0');

        // القيد على **الصافي** لا على الإجماليّ: الفرع يمنح ما يصل العميل.
        if (bccomp($branchBalance, $net, 4) < 0) {
            throw new DomainException(sprintf(
                'رصيد الفرع الإلكترونيّ %s ولا يكفي لإيداع %s — اطلب شحن رصيد من الوكيل الأمّ',
                $branchBalance, $net,
            ));
        }

        $reference = 'DEP-' . strtoupper(Str::random(10));

        return DB::transaction(function () use ($branch, $customer, $amount, $actor, $note, $reference, $q, $net, $shift) {
            // القفل على الصفّين معاً وبترتيبٍ ثابت (الأصغر أوّلاً): ترتيبان
            // مختلفان في مسارين متزامنين يُنتجان جمود قفل.
            $ids = [$branch->branch_user_id, $customer->id];
            sort($ids);
            EMoney::whereIn('user_id', $ids)->lockForUpdate()->get();

            EMoney::where('user_id', $branch->branch_user_id)->decrement('current_balance', $net);
            EMoney::where('user_id', $customer->id)->increment('current_balance', $net);

            // النقد الداخل هو **الإجماليّ**: العميل سلّم `amount` ورقاً.
            // وتسجيلُ الصافي يجعل الجرد ينقص بمقدار الرسوم كلّ يوم.
            // النقد يدخل **درج الصرّاف** لا خزنة الفرع: الورق يقع في يد من
            // يقف على الشبّاك، وهو من يُسأل عنه آخر ورديّته. وتسجيلُه على
            // الخزنة يجعل عجز شبّاكٍ واحدٍ بلا صاحبٍ في فرعٍ فيه عشرون.
            if ($shift) {
                $this->shifts->recordDrawer(
                    $shift, 'in', 'customer_deposit', $amount,
                    customerId: $customer->id, reference: $reference, note: $note,
                );
            } else {
                $this->till->record(
                    $branch, 'in', 'customer_deposit', $amount, $actor,
                    customerId: $customer->id, reference: $reference,
                    note: $note,
                );
            }

            // الترحيل إلزاميّ وداخل المعاملة — لا `safeLedgerPost`.
            $this->ledgerTransfer(
                fromUserId: (int) $branch->branch_user_id,
                toUserId: (int) $customer->id,
                amount: $net,
                sourceType: 'agent_deposit',
                sourceId: $reference,
                description: "إيداع نقديّ عبر فرع {$branch->name}",
                metadata: [
                    'branch_id' => $branch->id, 'actor_id' => $actor->id,
                    'gross' => $amount, 'fee' => $q['fee'], 'commission' => $q['commission'],
                ],
            );

            $this->settlePlatformShare($branch, $q['platform'], $reference, 'agent_deposit_fee');

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

            $receiptNumber = $this->issueReceipt(
                $branch, $customer, 'cash_in', 'credit',
                $net, $q['fee'], $reference, $shift, $note,
            );

            return [
                'reference' => $reference,
                'receipt_number' => $receiptNumber,
                'amount' => $amount,
                'fee' => $q['fee'],
                'net' => $net,
                'commission' => $q['commission'],
                'customer_balance' => (string) EMoney::where('user_id', $customer->id)
                    ->value('current_balance'),
            ];
        });
    }


    /**
     * إيصالُ العملية — **نفسُ إيصال التطبيق، لا نسخةٌ خاصّةٌ بالشبّاك.**
     *
     * ══════════════════════════════════════════════════════════════════
     * كان الإيداع والسحب في الفرع **بلا إيصالٍ إطلاقاً**: لا في يد العميل
     * ولا في قائمة إيصالاته بالتطبيق. فمن أودع مئة ألفٍ في فرعٍ يخرج بلا
     * ورقةٍ تُثبت أنّه أودع — ولو أُنكرت العملية لما كان بيده إلّا كلامه.
     *
     * وهو يُصدَر بنفس `ReceiptService` الذي يُصدر بقيّة إيصالات المنصّة،
     * فيحمل رقم إيصالٍ ورمزَ تحقّقٍ عامّاً وسنداً PDF ويظهر في تطبيق
     * العميل. ولو بُني إيصالٌ خاصٌّ بالشبّاك لصار للعملية الواحدة مستندان
     * برقمين — وذلك أوّل ما يُربك في أيّ نزاع.
     *
     * **ولا يُفشل العمليّة إن سقط.** المال انتقل وقُيّد في الدفتر؛ وإسقاطُ
     * عمليّةٍ تمّت لأنّ ورقةً لم تُطبع خطأٌ أفدح من غياب الورقة.
     */
    private function issueReceipt(
        AgentBranch $branch, User $customer, string $type, string $direction,
        string $amount, string $fee, string $reference, ?AgentShift $shift, ?string $note,
    ): ?string {
        try {
            $staff = $shift?->staff_id ? \App\Models\Agent\AgentStaff::find($shift->staff_id) : null;

            $data = [
                'user_id' => (int) $customer->id,
                'counterparty_user_id' => (int) $branch->branch_user_id,
                'reference_transaction_id' => $reference,
                'reference_type' => 'agent_branch',
                'reference_id' => (int) $branch->id,
                'receipt_type' => $type,
                'amount' => $amount,
                'fee' => $fee,
                'zone_code' => (string) ($branch->account?->zone_code ?? ''),
                'metadata' => [
                    'branch_id' => (int) $branch->id,
                    'branch_name' => $branch->name,
                    'branch_code' => $branch->code,
                    'branch_city' => $branch->city,
                    'agent_user_id' => (int) $branch->agent_user_id,
                    'shift_id' => $shift?->id,
                    'staff_id' => $staff?->id,
                    'teller_name' => $staff?->name,
                    'teller_code' => $staff?->username,
                    'note' => $note,
                ],
            ];

            $receipt = $direction === 'credit'
                ? app(ReceiptService::class)->issueCredit($data)
                : app(ReceiptService::class)->issueDebit($data);

            return $receipt->receipt_number;
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    // ── سحب ─────────────────────────────────────────────────────────────

    /**
     * العميل يسحب نقداً من الفرع.
     *
     * والقيد هنا **النقد في الدرج** لا الرصيد الإلكترونيّ — والفرق بينهما
     * هو ما كان النظام يغفله.
     */
    public function withdraw(
        AgentBranch $branch, User $customer, string $amount, User $actor,
        ?string $note = null, ?AgentShift $shift = null
    ): array {
        $this->assertOperable($branch, $customer, $amount);

        $q = $this->quote('AGENT_WITHDRAW', $amount, (string) $branch->account?->zone_code);

        // العميل يطلب `amount` نقداً ويُخصم منه `amount + fee`.
        //
        // فيستلم بيده ما طلبه بالضبط: من يطلب ١٠٠٠ ويُعطى ٩٩٥ يعدّ الأوراق
        // ويظنّ الموظّف أخطأ أو سرق. والرسم يُخصم من الرصيد حيث يُقرأ لا من
        // الأوراق حيث تُعدّ.
        $total = bcadd($amount, $q['fee'], 4);

        $customerBalance = (string) (EMoney::where('user_id', $customer->id)
            ->value('current_balance') ?? '0');

        if (bccomp($customerBalance, $total, 4) < 0) {
            throw new DomainException(
                "رصيد العميل {$customerBalance} ولا يكفي لسحب {$amount} مع رسم {$q['fee']}",
            );
        }

        // **قبل أيّ خصم.** والنقد المطلوب هو `amount` لا `total`: الرسم
        // إلكترونيّ ولا يخرج من الدرج.
        //
        // ويُقاس على **درج الصرّاف** حين تكون هناك ورديّة، لا على خزنة
        // الفرع: خزنةٌ فيها خمسة ملايين لا تُعين صرّافاً في درجه عشرة آلاف
        // — لا يستطيع أن يعدّ ما ليس بيده. والقياس على الخزنة كان يقبل
        // سحباً يقف الصرّاف عاجزاً عن دفعه أمام العميل.
        $this->assertCashAvailable($branch, $shift, $amount);

        $reference = 'WDR-' . strtoupper(Str::random(10));

        return DB::transaction(function () use ($branch, $customer, $amount, $actor, $note, $reference, $q, $total, $shift) {
            $ids = [$branch->branch_user_id, $customer->id];
            sort($ids);
            EMoney::whereIn('user_id', $ids)->lockForUpdate()->get();

            // يُعاد الفحص داخل القفل: بين الفحص الأوّل وبدء المعاملة قد
            // يكون شبّاكٌ آخر في الفرع نفسه أفرغ الدرج.
            $this->assertCashAvailable($branch, $shift, $amount);

            EMoney::where('user_id', $customer->id)->decrement('current_balance', $total);
            EMoney::where('user_id', $branch->branch_user_id)->increment('current_balance', $total);

            // النقد الخارج هو المطلوب وحده — الرسم لم يخرج من الدرج.
            if ($shift) {
                $this->shifts->recordDrawer(
                    $shift, 'out', 'customer_withdraw', $amount,
                    customerId: $customer->id, reference: $reference, note: $note,
                );
            } else {
                $this->till->record(
                    $branch, 'out', 'customer_withdraw', $amount, $actor,
                    customerId: $customer->id, reference: $reference, note: $note,
                );
            }

            $this->ledgerTransfer(
                fromUserId: (int) $customer->id,
                toUserId: (int) $branch->branch_user_id,
                amount: $total,
                sourceType: 'agent_withdraw',
                sourceId: $reference,
                description: "سحب نقديّ من فرع {$branch->name}",
                metadata: [
                    'branch_id' => $branch->id, 'actor_id' => $actor->id,
                    'cash_paid' => $amount, 'fee' => $q['fee'], 'commission' => $q['commission'],
                ],
            );

            $this->settlePlatformShare($branch, $q['platform'], $reference, 'agent_withdraw_fee');

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

            $receiptNumber = $this->issueReceipt(
                $branch, $customer, 'cash_out', 'debit',
                $amount, $q['fee'], $reference, $shift, $note,
            );

            return [
                'reference' => $reference,
                'receipt_number' => $receiptNumber,
                'amount' => $amount,
                'fee' => $q['fee'],
                'total_debited' => $total,
                'commission' => $q['commission'],
                'customer_balance' => (string) EMoney::where('user_id', $customer->id)
                    ->value('current_balance'),
            ];
        });
    }


    /**
     * هل يستطيع من يقف على الشبّاك أن يدفع هذا المبلغ نقداً؟
     *
     * مع ورديّة: السؤال عن الدرج. بلا ورديّة (فرعٌ صغيرٌ بشبّاكٍ واحدٍ يعمل
     * على الخزنة مباشرةً): السؤال عن الخزنة.
     */
    private function assertCashAvailable(AgentBranch $branch, ?AgentShift $shift, string $amount): void
    {
        if (!$shift) {
            $this->till->assertCanPayCash($branch, $amount);
            return;
        }

        $fresh = $shift->fresh();

        if (!$fresh || !$fresh->isOpen()) {
            throw new DomainException('لا توجد ورديّة مفتوحة — افتح ورديّتك قبل العمل على الشبّاك');
        }

        if (!$fresh->canPayOut($amount)) {
            throw new DomainException(sprintf(
                'النقد في درجك %s ولا يكفي لدفع %s — اطلب توريداً من خزنة الفرع',
                (string) $fresh->cash_on_hand, $amount,
            ));
        }
    }

    /**
     * حصّة المنصّة من الرسم تنتقل من الفرع إليها.
     *
     * وتبقى **عمولة الوكيل** عند الفرع بلا قيدٍ إضافيّ: هي الفرق بين ما
     * قبضه نقداً وما منحه رصيداً. وإخراجُها ثمّ إعادتُها قيدان لا يغيّران
     * شيئاً ويُثقلان الدفتر.
     */
    private function settlePlatformShare(
        AgentBranch $branch, string $platformShare, string $reference, string $sourceType
    ): void {
        if (bccomp($platformShare, '0', 4) <= 0) {
            return;
        }

        $ledger = $this->ledgerService();
        $branchWallet = $ledger->getOrCreateUserWallet((int) $branch->branch_user_id);
        $feeAccount = $ledger->getOrCreateSystemAccount(
            'PLATFORM_FEE', 'revenue', 'رسوم المنصة', 'credit',
        );

        EMoney::where('user_id', $branch->branch_user_id)
            ->decrement('current_balance', $platformShare);

        $ledger->post(
            sourceType: $sourceType,
            sourceId: $reference,
            description: 'حصّة المنصّة من رسم عملية الفرع',
            lines: [
                ['account' => $branchWallet->account_code, 'direction' => 'debit', 'amount' => $platformShare],
                ['account' => $feeAccount->account_code, 'direction' => 'credit', 'amount' => $platformShare],
            ],
            idempotencyKey: $sourceType . '_' . $reference,
        );
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
