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

