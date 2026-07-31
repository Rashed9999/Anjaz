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

