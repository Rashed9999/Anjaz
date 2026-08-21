<?php

namespace App\Traits;

use App\Services\LedgerService;

/**
 * AMIAL-LEDGER-001 (v1.8)
 *
 * PostsToLedger — trait يبسّط تسجيل القيود المحاسبية من الخدمات المالية.
 *
 * استخدام في أي service:
 *   use PostsToLedger;
 *
 *   $this->ledgerTransfer(
 *     fromUserId: $sender->id,
 *     toUserId: $receiver->id,
 *     amount: '100',
 *     sourceType: 'send_money',
 *     sourceId: $txId,
 *     description: 'تحويل',
 *   );
 *
 *   // أو مع رسوم:
 *   $this->ledgerTransferWithFee(
 *     fromUserId, toUserId, grossAmount, feeAmount, ...
 *   );
 */
trait PostsToLedger
{
    private function ledgerService(): LedgerService
    {
        return app(LedgerService::class);
    }

    /**
     * تحويل بسيط بين مستخدمين (قيدان).
     */
    protected function ledgerTransfer(
        int $fromUserId,
        int $toUserId,
        string $amount,
        string $sourceType,
        ?string $sourceId,
        string $description,
        ?string $idempotencyKey = null,
        array $metadata = [],
    ): void {
        $ledger = $this->ledgerService();
        $fromWallet = $ledger->getOrCreateUserWallet($fromUserId);
        $toWallet = $ledger->getOrCreateUserWallet($toUserId);

        $ledger->post(
            sourceType: $sourceType,
            sourceId: $sourceId,
            description: $description,
            lines: [
                ['account' => $fromWallet->account_code, 'direction' => 'debit', 'amount' => $amount],
                ['account' => $toWallet->account_code, 'direction' => 'credit', 'amount' => $amount],
            ],
            idempotencyKey: $idempotencyKey ?? ($sourceType . '_' . $sourceId),
            metadata: $metadata,
        );
    }

    /**
     * تحويل مع رسوم منصة (3 قيود): from → to (net) + platform_fee.
     */
    protected function ledgerTransferWithFee(
        int $fromUserId,
        int $toUserId,
        string $grossAmount,
        string $feeAmount,
        string $sourceType,
        ?string $sourceId,
        string $description,
        ?string $idempotencyKey = null,
        array $metadata = [],
    ): void {
        $ledger = $this->ledgerService();
        $fromWallet = $ledger->getOrCreateUserWallet($fromUserId);
        $toWallet = $ledger->getOrCreateUserWallet($toUserId);
        $feeAccount = $ledger->getOrCreateSystemAccount(
            'PLATFORM_FEE', 'revenue', 'رسوم المنصة', 'credit'
        );

        $netAmount = bcsub($grossAmount, $feeAmount, 4);

        $lines = [
            ['account' => $fromWallet->account_code, 'direction' => 'debit', 'amount' => $grossAmount],
            ['account' => $toWallet->account_code, 'direction' => 'credit', 'amount' => $netAmount],
        ];
        if (bccomp($feeAmount, '0', 4) > 0) {
            $lines[] = ['account' => $feeAccount->account_code, 'direction' => 'credit', 'amount' => $feeAmount];
        }

        $ledger->post(
            sourceType: $sourceType,
            sourceId: $sourceId,
            description: $description,
            lines: $lines,
            idempotencyKey: $idempotencyKey ?? ($sourceType . '_' . $sourceId),
            metadata: $metadata,
        );
    }

    /**
     * سحب نقديّ عبر وكيل: العميل → الوكيل، والرسم يُقسَم بين الوكيل والمنصّة.
     *
     * AMIAL-LEDGER-CASHOUT-001 — كان هذا المسار **بلا ترحيل إطلاقاً**.
     *
     * لا «مؤجَّلاً» ولا «مبلوعاً» كبقيّة المسارات، بل غير مكتوبٍ أصلاً: يخرج
     * المال من محفظة العميل ويدخل محفظة الوكيل ولا يمرّ بدفتر الأستاذ بحرف.
     * وهو من أكثر المسارات حركةً في محفظةٍ يمنيّة — السحب النقديّ من وكيل هو
     * ما يفعله المستخدم فعلاً، لا التحويل.
     *
     * **بنية القيد:** العميل يُخصم المبلغ + الرسم كاملاً. والرسم يُقسَم:
     * حصّة الوكيل تُضاف إلى محفظته مع المبلغ، وحصّة المنصّة إلى حساب الإيراد.
     * فالمدين = المبلغ + العمولة + حصّة المنصّة، والدائن مثله تماماً.
     *
     * ولا يُدمَج سطرا الوكيل (المبلغ والعمولة) في سطرٍ واحد رغم ذهابهما إلى
     * الحساب نفسه: الفصل يجعل تقرير العمولات يُقرأ من الدفتر مباشرةً بدل
     * استنتاجه بالطرح.
     */
    protected function ledgerAgentCashOut(
        int $customerUserId,
        int $agentUserId,
        string $amount,
        string $agentCommission,
        string $platformFee,
        string $sourceId,
        string $description,
    ): void {
        $ledger = $this->ledgerService();
        $customer = $ledger->getOrCreateUserWallet($customerUserId);
        $agent = $ledger->getOrCreateUserWallet($agentUserId);

        $totalDebit = bcadd(bcadd($amount, $agentCommission, 4), $platformFee, 4);

        $lines = [
            ['account' => $customer->account_code, 'direction' => 'debit', 'amount' => $totalDebit],
            ['account' => $agent->account_code, 'direction' => 'credit', 'amount' => $amount],
        ];

        if (bccomp($agentCommission, '0', 4) > 0) {
            $lines[] = [
                'account' => $agent->account_code,
                'direction' => 'credit',
                'amount' => $agentCommission,
                'description' => 'عمولة الوكيل',
            ];
        }

        if (bccomp($platformFee, '0', 4) > 0) {
            $feeAccount = $ledger->getOrCreateSystemAccount(
                'PLATFORM_FEE', 'revenue', 'رسوم المنصة', 'credit'
            );
            $lines[] = [
                'account' => $feeAccount->account_code,
                'direction' => 'credit',
                'amount' => $platformFee,
                'description' => 'حصّة المنصّة من رسم السحب',
            ];
        }

        $ledger->post(
            sourceType: 'agent_cash_out',
            sourceId: $sourceId,
            description: $description,
            lines: $lines,
            idempotencyKey: "agent_cash_out_{$sourceId}",
        );
    }

    /**
     * السحب البنكيّ — ثلاث نقاط وحسابُ حجزٍ واحد بينها.
     *
     * AMIAL-LEDGER-WITHDRAW-001 — كان المسار كلّه بلا ترحيل.
     *
     * **لماذا حساب حجز؟** المال في السحب يمرّ بحالةٍ وسطى: يخرج من رصيد
     * العميل المتاح (`current_balance`) إلى رصيدٍ معلّق (`pending_balance`)
     * حين يطلب، ثم إمّا يخرج إلى الإدارة عند القبول وإمّا يعود إليه عند
     * الرفض. وثلاثتها تحرّك مالاً فتحتاج ثلاثة قيود.
     *
     * وبلا حساب وسيط تُكتب حركةٌ واحدة عند القبول، فيبدو الدفتر كأن المال
     * بقي عند العميل طوال أيام الانتظار وهو غير متاحٍ له. ودفترٌ يقول إن
     * الرصيد متاح وهو محجوز أسوأ من دفترٍ صامت — يُبنى عليه قرار.
     *
     * `WITHDRAW_PENDING` التزامٌ على المنصّة: مالٌ تحتفظ به لحساب العميل ولم
     * يصر ملكاً لها بعد.
     */
    protected function ledgerWithdrawRequested(
        int $userId,
        string $total,
        string $sourceId,
    ): void {
        $ledger = $this->ledgerService();
        $wallet = $ledger->getOrCreateUserWallet($userId);
        $pending = $ledger->getOrCreateSystemAccount(
            'WITHDRAW_PENDING', 'liability', 'سحوبات معلّقة (طُلبت ولم تُبتّ)', 'credit'
        );

        $ledger->post(
            sourceType: 'withdraw_requested',
            sourceId: $sourceId,
            description: 'طلب سحب — حجز المبلغ',
            lines: [
                ['account' => $wallet->account_code, 'direction' => 'debit', 'amount' => $total],
                ['account' => $pending->account_code, 'direction' => 'credit', 'amount' => $total],
            ],
            idempotencyKey: "withdraw_req_{$sourceId}",
        );
    }

    /** رُفض الطلب: يعود المال من الحجز إلى العميل. */
    protected function ledgerWithdrawDenied(
        int $userId,
        string $total,
        string $sourceId,
    ): void {
        $ledger = $this->ledgerService();
        $wallet = $ledger->getOrCreateUserWallet($userId);
        $pending = $ledger->getOrCreateSystemAccount(
            'WITHDRAW_PENDING', 'liability', 'سحوبات معلّقة (طُلبت ولم تُبتّ)', 'credit'
        );

        $ledger->post(
            sourceType: 'withdraw_denied',
            sourceId: $sourceId,
            description: 'رفض طلب سحب — فكّ الحجز',
            lines: [
                ['account' => $pending->account_code, 'direction' => 'debit', 'amount' => $total],
                ['account' => $wallet->account_code, 'direction' => 'credit', 'amount' => $total],
            ],
            idempotencyKey: "withdraw_deny_{$sourceId}",
        );
    }

    /**
     * قُبل الطلب: يخرج المال من الحجز إلى الإدارة، والرسم إلى إيراد المنصّة.
     *
     * ولا يُخصم من محفظة العميل هنا: خُصم منها عند الطلب. ومن أعاد خصمه
     * هنا خصم مرّتين — وهو خطأٌ لا يكشفه فحصُ التوازن لأن الطرفين يتساويان
     * في الحالتين.
     */
    protected function ledgerWithdrawApproved(
        int $adminUserId,
        string $amount,
        string $charge,
        string $sourceId,
    ): void {
        $ledger = $this->ledgerService();
        $adminWallet = $ledger->getOrCreateUserWallet($adminUserId);
        $pending = $ledger->getOrCreateSystemAccount(
            'WITHDRAW_PENDING', 'liability', 'سحوبات معلّقة (طُلبت ولم تُبتّ)', 'credit'
        );

        $total = bcadd($amount, $charge, 4);
        $lines = [
            ['account' => $pending->account_code, 'direction' => 'debit', 'amount' => $total],
            ['account' => $adminWallet->account_code, 'direction' => 'credit', 'amount' => $amount],
        ];

        if (bccomp($charge, '0', 4) > 0) {
            $feeAccount = $ledger->getOrCreateSystemAccount(
                'PLATFORM_FEE', 'revenue', 'رسوم المنصة', 'credit'
            );
            $lines[] = [
                'account' => $feeAccount->account_code,
                'direction' => 'credit',
                'amount' => $charge,
                'description' => 'رسم السحب',
            ];
        }

        $ledger->post(
            sourceType: 'withdraw_approved',
            sourceId: $sourceId,
            description: 'قبول طلب سحب',
            lines: $lines,
            idempotencyKey: "withdraw_appr_{$sourceId}",
        );
    }

    /**
     * حجز escrow (الدفع الآمن): from user → escrow hold account.
     */
    protected function ledgerHoldEscrow(
        int $fromUserId,
        string $amount,
        string $sourceId,
        string $description,
    ): void {
        $ledger = $this->ledgerService();
        $fromWallet = $ledger->getOrCreateUserWallet($fromUserId);
        $escrow = $ledger->getOrCreateSystemAccount(
            'ESCROW_HOLD', 'liability', 'حجز الدفع الآمن', 'credit'
        );

        $ledger->post(
            sourceType: 'safe_payment_fund',
            sourceId: $sourceId,
            description: $description,
            lines: [
                ['account' => $fromWallet->account_code, 'direction' => 'debit', 'amount' => $amount],
                ['account' => $escrow->account_code, 'direction' => 'credit', 'amount' => $amount],
            ],
            idempotencyKey: "escrow_hold_{$sourceId}",
        );
    }

    /**
     * إفراج escrow للبائع (مع رسوم): escrow → seller (net) + platform_fee.
     */
    protected function ledgerReleaseEscrow(
        int $toSellerUserId,
        string $grossAmount,
        string $feeAmount,
        string $sourceId,
        string $description,
    ): void {
        $ledger = $this->ledgerService();
        $sellerWallet = $ledger->getOrCreateUserWallet($toSellerUserId);
        $escrow = $ledger->getOrCreateSystemAccount(
            'ESCROW_HOLD', 'liability', 'حجز الدفع الآمن', 'credit'
        );
        $feeAccount = $ledger->getOrCreateSystemAccount(
            'PLATFORM_FEE', 'revenue', 'رسوم المنصة', 'credit'
        );

        $netAmount = bcsub($grossAmount, $feeAmount, 4);
        $lines = [
            ['account' => $escrow->account_code, 'direction' => 'debit', 'amount' => $grossAmount],
            ['account' => $sellerWallet->account_code, 'direction' => 'credit', 'amount' => $netAmount],
        ];
        if (bccomp($feeAmount, '0', 4) > 0) {
            $lines[] = ['account' => $feeAccount->account_code, 'direction' => 'credit', 'amount' => $feeAmount];
        }

        $ledger->post(
            sourceType: 'safe_payment_release',
            sourceId: $sourceId,
            description: $description,
            lines: $lines,
            idempotencyKey: "escrow_release_{$sourceId}",
        );
    }

    /**
     * استرجاع escrow للمشتري: escrow → buyer.
     */
    protected function ledgerRefundEscrow(
        int $toBuyerUserId,
        string $amount,
        string $sourceId,
        string $description,
    ): void {
        $ledger = $this->ledgerService();
        $buyerWallet = $ledger->getOrCreateUserWallet($toBuyerUserId);
        $escrow = $ledger->getOrCreateSystemAccount(
            'ESCROW_HOLD', 'liability', 'حجز الدفع الآمن', 'credit'
        );

        $ledger->post(
            sourceType: 'safe_payment_refund',
            sourceId: $sourceId,
            description: $description,
            lines: [
                ['account' => $escrow->account_code, 'direction' => 'debit', 'amount' => $amount],
                ['account' => $buyerWallet->account_code, 'direction' => 'credit', 'amount' => $amount],
            ],
            idempotencyKey: "escrow_refund_{$sourceId}",
        );
    }

    /**
     * تبرع: from donor → charity hold (net) + platform_fee.
     */
    protected function ledgerDonation(
        int $donorUserId,
        int $orgId,
        string $grossAmount,
        string $feeAmount,
        string $sourceId,
        string $description,
    ): void {
        $ledger = $this->ledgerService();
        $donorWallet = $ledger->getOrCreateUserWallet($donorUserId);
        $charityAccount = $ledger->getOrCreateSystemAccount(
            "CHARITY_HOLD_{$orgId}", 'liability', "حساب المنظمة {$orgId}", 'credit'
        );
        $feeAccount = $ledger->getOrCreateSystemAccount(
            'PLATFORM_FEE', 'revenue', 'رسوم المنصة', 'credit'
        );

        $netAmount = bcsub($grossAmount, $feeAmount, 4);
        $lines = [
            ['account' => $donorWallet->account_code, 'direction' => 'debit', 'amount' => $grossAmount],
            ['account' => $charityAccount->account_code, 'direction' => 'credit', 'amount' => $netAmount],
        ];
        if (bccomp($feeAmount, '0', 4) > 0) {
            $lines[] = ['account' => $feeAccount->account_code, 'direction' => 'credit', 'amount' => $feeAmount];
        }

        $ledger->post(
            sourceType: 'donation',
            sourceId: $sourceId,
            description: $description,
            lines: $lines,
            idempotencyKey: "donation_{$sourceId}",
        );
    }

    /**
     * دفع فاتورة: from user → biller payable (net) + platform_fee.
     * (المال يخرج من المستخدم لحساب المزود/الفوترة)
     */
    protected function ledgerBillPayment(
        int $fromUserId,
        int $providerId,
        string $grossAmount,
        string $feeAmount,
        string $sourceId,
        string $description,
    ): void {
        $ledger = $this->ledgerService();
        $userWallet = $ledger->getOrCreateUserWallet($fromUserId);
        $billerAccount = $ledger->getOrCreateSystemAccount(
            "BILLER_PAYABLE_{$providerId}", 'liability', "مستحقات المزود {$providerId}", 'credit'
        );
        $feeAccount = $ledger->getOrCreateSystemAccount(
            'PLATFORM_FEE', 'revenue', 'رسوم المنصة', 'credit'
        );

        $netToBiller = bcsub($grossAmount, $feeAmount, 4);
        $lines = [
            ['account' => $userWallet->account_code, 'direction' => 'debit', 'amount' => $grossAmount],
            ['account' => $billerAccount->account_code, 'direction' => 'credit', 'amount' => $netToBiller],
        ];
        if (bccomp($feeAmount, '0', 4) > 0) {
            $lines[] = ['account' => $feeAccount->account_code, 'direction' => 'credit', 'amount' => $feeAmount];
        }

        $ledger->post(
            sourceType: 'bill_payment',
            sourceId: $sourceId,
            description: $description,
            lines: $lines,
            idempotencyKey: "bill_pay_{$sourceId}",
        );
    }

    /**
     * AMIAL-LEDGER-CASHOUT-001 — **السحبُ النقديُّ عند الوكيل: مسارٌ كاملٌ
     * بلا قيدٍ واحد.**
     *
     * ══════════════════════════════════════════════════════════════════
     * **ما قِيس:** `CustomerWithdrawService` يُحرّك المالَ في ثلاث خطوات —
     * حجزٌ عند الطلب، وفكٌّ عند الإلغاء أو الانتهاء، وصرفٌ عند حضور
     * العميل إلى الوكيل — **وصفرُ قيودٍ في الدفتر في الثلاث**. وهو مُعلَنٌ
     * بقعةً عمياء في `config('amial.reconciliation.blind_spots')` منذ
     * كُتب، وتعتذر عنه المصالحةُ الليليّةُ في كلّ ليلة.
     *
     * والسحبُ النقديُّ ليس تدفّقاً هامشيّاً: **هو الطريقُ الوحيدُ الذي
     * يخرج به المالُ من المنصّة إلى يدِ العميل ورقاً**. فدفترٌ لا يراه
     * يرى الأموالَ داخلةً ولا يراها خارجة — وميزانُ مراجعةٍ متوازنٌ فوق
     * ذلك يُطمئن ولا يقول شيئاً.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولماذا حسابُ حجزٍ وسيط (`CASH_OUT_HOLD`) لا قيدٌ واحدٌ عند الصرف:**
     *
     * بين الطلب والصرف تمرّ دقائقُ أو ساعات. والمالُ في تلك المدّة **خرج
     * من متاح العميل ولم يصل الوكيل**: `hold()` تنقله من `current_balance`
     * إلى `held_balance`. فقيدٌ واحدٌ عند الصرف يجعل الدفترَ يقول إنّ
     * المالَ بقي متاحاً للعميل طوال الانتظار — **ورصيدٌ يُعرَض متاحاً وهو
     * محجوزٌ يَعِد العميلَ بما لا يستطيع**، ويُبنى عليه قرارُ إقراضٍ أو حدٍّ.
     *
     * وهو التزامٌ لا حقُّ ملكيّة: مالٌ تحتفظ به المنصّةُ لحساب العميل ولم
     * يصر ملكاً لأحدٍ بعد — يعود إليه إن ألغى، ويذهب للوكيل إن صرف.
     *
     * **وأربعةُ مخارجَ من الحجز لا ثلاثة**: إلغاءٌ صريح · انتهاءُ صلاحيّةٍ
     * يكتشفه الوكيلُ عند التنفيذ · كنسُ `expireStale` المجدول · والصرف.
     * ومخرجٌ واحدٌ بلا قيدٍ يترك الحجزَ معلّقاً في الدفتر إلى الأبد.
     */
    protected function ledgerCashOutRequested(
        int $customerUserId,
        string $totalDebit,
        string $sourceId,
    ): void {
        $ledger = $this->ledgerService();
        $wallet = $ledger->getOrCreateUserWallet($customerUserId);

        $ledger->post(
            sourceType: 'cash_out_requested',
            sourceId: $sourceId,
            description: 'طلب سحب نقديّ — حجز المبلغ والرسم',
            lines: [
                ['account' => $wallet->account_code, 'direction' => 'debit', 'amount' => $totalDebit],
                ['account' => $this->cashOutHold()->account_code, 'direction' => 'credit', 'amount' => $totalDebit],
            ],
            idempotencyKey: "cash_out_req_{$sourceId}",
        );
    }

    /**
     * رجع المال إلى العميل — إلغاءً أو انتهاءَ صلاحيّة.
     *
     * `$why` يدخل في مفتاح التفرّد لا في الوصف وحدَه: طلبٌ يُلغى ثمّ
     * يُكنَس (‏وكلاهما يفكّ حتّى السقف) يكتب قيداً واحداً لا اثنين —
     * ومفتاحٌ واحدٌ لسببين مختلفين يبتلع الثاني صامتاً.
     */
    protected function ledgerCashOutReleased(
        int $customerUserId,
        string $totalDebit,
        string $sourceId,
        string $why = 'cancelled',
    ): void {
        $ledger = $this->ledgerService();
        $wallet = $ledger->getOrCreateUserWallet($customerUserId);

        $ledger->post(
            sourceType: 'cash_out_released',
            sourceId: $sourceId,
            description: $why === 'expired'
                ? 'انتهت صلاحيّة طلب السحب — فكّ الحجز'
                : 'إلغاء طلب سحب نقديّ — فكّ الحجز',
            lines: [
                ['account' => $this->cashOutHold()->account_code, 'direction' => 'debit', 'amount' => $totalDebit],
                ['account' => $wallet->account_code, 'direction' => 'credit', 'amount' => $totalDebit],
            ],
            idempotencyKey: "cash_out_rel_{$sourceId}",
        );
    }

    /**
     * صُرف المال: من الحجز إلى الوكيل (‏المبلغُ تعويضُ نقدِه، والعمولةُ
     * أجرُه) وإلى إيراد المنصّة (‏حصّتُها من الرسم).
     *
     * **ولا يُخصم من محفظة العميل هنا** — خُصم عند الطلب. ومن أعاد خصمَه
     * خصم مرّتين، **وفحصُ التوازن لا يمسكه** لأنّ الطرفين يتساويان في
     * الحالتين. (‏وهذا التحذيرُ بعينه مكتوبٌ فوق `ledgerWithdrawApproved`
     * لأنّ العطلَ وقع هناك من قبل.)
     */
    protected function ledgerCashOutExecuted(
        int $agentUserId,
        string $amount,
        string $agentCommission,
        string $platformProfit,
        string $sourceId,
    ): void {
        $ledger = $this->ledgerService();
        $agent = $ledger->getOrCreateUserWallet($agentUserId);

        $total = bcadd(bcadd($amount, $agentCommission, 4), $platformProfit, 4);
        $agentCredit = bcadd($amount, $agentCommission, 4);

        $lines = [
            ['account' => $this->cashOutHold()->account_code, 'direction' => 'debit', 'amount' => $total],
            [
                'account' => $agent->account_code,
                'direction' => 'credit',
                'amount' => $agentCredit,
                'description' => 'تعويضُ نقدِ الوكيل وعمولتُه',
            ],
        ];

        if (bccomp($platformProfit, '0', 4) > 0) {
            $fee = $ledger->getOrCreateSystemAccount(
                'PLATFORM_FEE', 'revenue', 'رسوم المنصة', 'credit'
            );
            $lines[] = [
                'account' => $fee->account_code,
                'direction' => 'credit',
                'amount' => $platformProfit,
                'description' => 'حصّة المنصّة من رسم السحب النقديّ',
            ];
        }

        $ledger->post(
            sourceType: 'cash_out_executed',
            sourceId: $sourceId,
            description: 'تنفيذ سحب نقديّ عند وكيل',
            lines: $lines,
            idempotencyKey: "cash_out_exec_{$sourceId}",
        );
    }

    /**
     * AMIAL-LEDGER-FAMILYFUND-001 — **مالٌ يخرج من محفظةٍ ولا يدخل أخرى.**
     *
     * ══════════════════════════════════════════════════════════════════
     * صندوقُ العائلة **حوضٌ مشترك**: المساهمةُ تخصم من محفظة العضو وتزيد
     * `family_funds.balance` — وهو عمودٌ في جدولٍ لا محفظةَ مستخدم. فالمالُ
     * يغادر الدفترَ من طرفٍ ولا يدخله من طرف، **ولا يظهر ذلك في ميزان
     * المراجعة** لأنّ الدفترَ لا يعرف أنّ الحوضَ موجودٌ أصلاً.
     *
     * وهو **التزامٌ على المنصّة**: مالٌ تحتفظ به لحساب أعضاء الصندوق، ولا
     * يصير ملكاً لها في أيّ لحظة. وتصنيفُه أصلاً أو حقوقَ ملكيّةٍ يجعل
     * كلَّ ريالٍ يودعه الناسُ يُقرأ ثروةً للمنصّة.
     *
     * **وحسابٌ لكلّ صندوقٍ لا حسابٌ واحدٌ يجمعها:** الوثيقةُ تطلب
     * `Drill-down حقيقي` — ورقمٌ إجماليٌّ لعشرة آلاف صندوقٍ لا يُفضي إلى
     * شيء. وهو النمطُ نفسُه في `USER_WALLET_{id}`.
     */
    protected function ledgerFamilyFundContribution(
        int $memberUserId,
        int $fundId,
        string $amount,
        string $sourceId,
    ): void {
        $ledger = $this->ledgerService();
        $wallet = $ledger->getOrCreateUserWallet($memberUserId);

        $ledger->post(
            sourceType: 'family_fund_contribute',
            sourceId: $sourceId,
            description: 'مساهمةٌ في صندوق عائلة #'.$fundId,
            lines: [
                ['account' => $wallet->account_code, 'direction' => 'debit', 'amount' => $amount],
                ['account' => $this->familyFundAccount($fundId)->account_code,
                    'direction' => 'credit', 'amount' => $amount],
            ],
            idempotencyKey: "family_fund_contrib_{$sourceId}",
        );
    }

    /** والصرفُ عكسُها: من الحوض إلى محفظة المستفيد. */
    protected function ledgerFamilyFundDisbursement(
        int $beneficiaryUserId,
        int $fundId,
        string $amount,
        string $sourceId,
    ): void {
        $ledger = $this->ledgerService();
        $wallet = $ledger->getOrCreateUserWallet($beneficiaryUserId);

        $ledger->post(
            sourceType: 'family_fund_disburse',
            sourceId: $sourceId,
            description: 'صرفٌ من صندوق عائلة #'.$fundId,
            lines: [
                ['account' => $this->familyFundAccount($fundId)->account_code,
                    'direction' => 'debit', 'amount' => $amount],
                ['account' => $wallet->account_code, 'direction' => 'credit', 'amount' => $amount],
            ],
            idempotencyKey: "family_fund_disb_{$sourceId}",
        );
    }

    private function familyFundAccount(int $fundId): \App\Models\Ledger\LedgerAccount
    {
        return $this->ledgerService()->getOrCreateSystemAccount(
            'FAMILY_FUND_'.$fundId, 'liability',
            'حوض صندوق عائلة #'.$fundId.' — مالُ الأعضاء بعهدة المنصّة', 'credit'
        );
    }

    private function cashOutHold(): \App\Models\Ledger\LedgerAccount
    {
        return $this->ledgerService()->getOrCreateSystemAccount(
            'CASH_OUT_HOLD', 'liability',
            'سحوبات نقديّة محجوزة (طُلبت ولم تُصرف)', 'credit'
        );
    }

    // AMIAL-LEDGER-BLOCKING-003 — حُذف `safeLedgerPost` عمداً.
    //
    // كان يبتلع أي استثناء من الدفتر ويكتفي بسطرٍ في اللوج. وقياسٌ حيّ
    // أثبت أن الترحيل لم يكن يقع أصلاً على محفظةٍ مموَّلة من خارج الدفتر:
    // نزل الرصيد من 10000 إلى 9000 وعدد قيود send_money **صفر**.
    //
    // ولا يُعاد. من أراد ترحيلاً «لا يُفشل شيئاً» فقد أراد سجلّاً اختيارياً،
    // وسجلٌّ اختياريّ ليس سجلّاً. القيد يوضع داخل المعاملة نفسها: إمّا أن
    // يتمّ المال وقيدُه معاً وإمّا لا يتمّ شيء.
    //
    // ويحرسه `LedgerBlockingGuardTest`: يسقط إن عاد النمط.
}

