<?php

namespace App\Traits;

use App\CentralLogics\Helpers;
use App\Exceptions\InsufficientBalanceException;
use App\Jobs\SendTransactionNotificationJob;
use App\Models\EMoney;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AuditService;
use App\Services\FeeService;
use App\Services\FinancialGuardService;
use App\Services\MoneyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AMIAL-REFACTOR-CORE-001
 *
 * TransactionTrait — قلب النظام المالي، أُعيدت كتابته كاملاً.
 *
 * تصنيف: REPLACE (النسخة الأصلية محفوظة في cash6_original/app/Traits/TransactionTrait.php)
 *
 * التغييرات الجوهرية مقابل الأصلي:
 *
 *   1. كل قراءة EMoney تستخدم lockForUpdate (راجع AUDIT_v0.6.md 1.1)
 *      → عبر FinancialGuardService::lockWallet()
 *
 *   2. سدّ ثغرة customer_send_money_transaction التي لم تكن تفحص الرصيد إطلاقاً (AUDIT 1.2)
 *
 *   3. transaction_id يستخدم Str::ulid() بدلاً من Str::random(5).timestamp (AUDIT 1.3)
 *
 *   4. كل المبالغ DECIMAL strings عبر MoneyService (AUDIT 1.4)
 *
 *   5. إصلاح bug earned_charge — لا يُضاف إلا للأدمن (AUDIT 1.7)
 *      → عبر FinancialGuardService::creditAdminCharge()
 *
 *   6. الإشعارات async عبر SendTransactionNotificationJob (AUDIT 1.9)
 *      → DB::afterCommit() لضمان أن الإشعار يخرج فقط لو الـ transaction نجح
 *
 *   7. InsufficientBalanceException بدلاً من return null الغامض (AUDIT 2.2)
 *      → Laravel يقوم بـ rollback تلقائياً
 *
 *   8. كل عملية تنتج audit_decision عبر AuditService
 *
 *   9. كل عملية تقبل idempotency_key لربط الـ row بسجل الـ idempotency
 *
 * البصمات (signatures) متوافقة للخلف مع الكود القائم — نضيف opt parameters فقط
 * كي لا نكسر الـ controllers الحالية أثناء v0.6.
 */
trait TransactionTrait
{
    use PostsToLedger;
    protected ?FinancialGuardService $_amialGuard = null;
    protected ?AuditService $_amialAudit = null;

    /** lazy-resolve لتجنب كسر __construct القائم */
    protected function guard(): FinancialGuardService
    {
        return $this->_amialGuard ??= app(FinancialGuardService::class);
    }

    protected function audit(): AuditService
    {
        return $this->_amialAudit ??= app(AuditService::class);
    }

    /**
     * AMIAL-RECOVERY-001 + AMIAL-ZONE-001 (v0.7-A): defense-in-depth.
     * يُستدعى في بداية كل دالة مالية للتأكد من أن المستخدم:
     *   - ليس تحت security hold
     *   - في zone مسموحة (احتياط بعد الـ middleware)
     *
     * يرمي \RuntimeException عند الفشل (الـ middleware يجب أن يلتقطها قبل).
     */
    protected function assertFinancialEligibility(int $userId): void
    {
        $user = User::find($userId);
        if (!$user) {
            throw new \RuntimeException("User {$userId} not found");
        }

        // 1) security hold
        if ($user->security_hold_until && $user->security_hold_until->isFuture()) {
            $this->audit()->record([
                'actor_type' => 'system',
                'actor_user_id' => $userId,
                'subject_type' => 'user',
                'subject_id' => (string)$userId,
                'action' => 'TRANSACTION_BLOCKED_BY_HOLD',
                'decision_code' => 'TX_SECURITY_HOLD',
                'reason' => 'User is in security hold until ' . $user->security_hold_until,
                'severity' => 'warning',
            ]);
            throw new \RuntimeException(
                'Account is in security hold until ' . $user->security_hold_until->toIso8601String()
            );
        }

        // 2) zone — احتياط (الـ middleware يجب أن يلتقطها أولاً)
        $zone = $user->zone_code ?? 'UNKNOWN';
        if ($zone !== 'SOUTH') {
            $this->audit()->record([
                'actor_type' => 'system',
                'actor_user_id' => $userId,
                'subject_type' => 'user',
                'subject_id' => (string)$userId,
                'action' => 'TRANSACTION_BLOCKED_BY_ZONE',
                'decision_code' => 'TX_ZONE_BLOCKED',
                'reason' => "User zone={$zone} is not SOUTH",
                'zone_code' => $zone,
                'severity' => 'warning',
            ]);
            throw new \RuntimeException(
                "Financial operations only allowed in SOUTH zone (current: {$zone})"
            );
        }
    }

    /**
     * يولد ULID آمن للمعرف العام (transaction_id).
     * 26 حرف، monotonic داخل ms، UNIQUE INDEX يصد أي تصادم.
     */
    protected function newTransactionId(): string
    {
        return (string) Str::ulid();
    }

    /**
     * يُسجل صف Transaction واحد. لا يلمس الأرصدة (هذا عمل FinancialGuard).
     */
    protected function recordTransaction(array $data): Transaction
    {
        $data['transaction_id'] = $data['transaction_id'] ?? $this->newTransactionId();

        // الأمن: نعرف user_id, transaction_type, و from/to كحد أدنى
        if (empty($data['transaction_type']) || empty($data['user_id'])) {
            throw new \InvalidArgumentException(
                'recordTransaction: user_id and transaction_type are required'
            );
        }

        return Transaction::create($data);
    }

    /** ========= CUSTOMER ↔ CUSTOMER ========= */

    /**
     * Send money: Customer → Customer.
     * - Sender debited (amount + charge)
     * - Receiver credited (amount)
     * - Admin credited charge_earned (charge)
     *
     * @param  int      $from_user_id
     * @param  int      $to_user_id
     * @param  string|float $amount  decimal-safe (الـ controller يمرر string في v0.7+)
     * @param  string|float $charge
     * @param  ?string  $note
     * @param  ?string  $idempotencyKey   لربط الـ row بـ idempotency_keys
     * @return ?string  transaction_id الناتج (null لو فشل بطريقة غير exception)
     */
    public function customer_send_money_transaction(
        int $from_user_id,
        int $to_user_id,
        float|string $amount,
        float|string $charge,
        ?string $note = null,
        ?string $idempotencyKey = null,
        ?array $feeMeta = null, // AMIAL-FEE-ENGINE-001 snapshot (scheme_id/scheme_version)
    ): ?string {
        $amount = MoneyService::normalize($amount);
        $charge = MoneyService::normalize($charge);
        $total = MoneyService::add($amount, $charge);

        // AMIAL-ZONE-001 hotfix (v0.7-A.1): فحص zone + security hold في بداية كل عملية
        // هذا defense-in-depth — الـ middleware amial.zone يجب أن يلتقطها أولاً،
        // لكن لو الـ controller استدعى الـ method مباشرة (مثل من admin panel)، فحص هنا يحميها.
        $this->assertFinancialEligibility($from_user_id);

        $txId = DB::transaction(function () use ($from_user_id, $to_user_id, $amount, $charge, $total, $note, $idempotencyKey, $feeMeta) {
            // AMIAL-SCALE-DEADLOCK-001 — قفل بترتيب ثابت قبل أي خصم/إضافة
            $this->guard()->lockWalletsOrdered([$from_user_id, $to_user_id]);

            // 1) خصم المرسل (مع lock + فحص رصيد) — يرمي InsufficientBalanceException لو لم يكف
            $senderWallet = $this->guard()->debit(
                userId: $from_user_id,
                amount: $total,
                reason: 'customer_send_money',
            );

            $primaryId = $this->newTransactionId();

            // 2) سجل عملية المرسل (debit)
            $this->recordTransaction([
                'user_id' => $from_user_id,
                'transaction_id' => $primaryId,
                'ref_trans_id' => null,
                'transaction_type' => SEND_MONEY,
                'debit' => $amount,
                'credit' => '0',
                'charge' => $charge,
                'amount' => $amount,
                'balance' => (string)$senderWallet->current_balance,
                'from_user_id' => $from_user_id,
                'to_user_id' => $to_user_id,
                'note' => null,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $senderWallet->zone_code ?? 'SOUTH',
                'fee_scheme_id' => $feeMeta['scheme_id'] ?? null,
                'fee_scheme_version' => $feeMeta['scheme_version'] ?? null,
            ]);

            // 3) إضافة للمستلم
            $receiverWallet = $this->guard()->credit(
                userId: $to_user_id,
                amount: $amount,
                reason: 'customer_receive_money',
            );

            $this->recordTransaction([
                'user_id' => $to_user_id,
                'transaction_id' => $this->newTransactionId(),
                'ref_trans_id' => $primaryId,
                'transaction_type' => RECEIVED_MONEY,
                'debit' => '0',
                'credit' => $amount,
                'charge' => '0',
                'amount' => $amount,
                'balance' => (string)$receiverWallet->current_balance,
                'from_user_id' => $from_user_id,
                'to_user_id' => $to_user_id,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $receiverWallet->zone_code ?? 'SOUTH',
            ]);

            // 4) إضافة رسوم للأدمن (charge_earned فقط، ليس current_balance) — هذا إصلاح bug 1.7
            if (MoneyService::isPositive($charge)) {
                $adminId = Helpers::get_admin_id();
                $adminWallet = $this->guard()->creditAdminCharge($adminId, $charge);

                $this->recordTransaction([
                    'user_id' => $adminId,
                    'transaction_id' => $this->newTransactionId(),
                    'ref_trans_id' => $primaryId,
                    'transaction_type' => ADMIN_CHARGE,
                    'debit' => '0',
                    'credit' => $charge,
                    'charge' => '0',
                    'amount' => $charge,
                    'balance' => (string)$adminWallet->charge_earned, // snapshot لـ charge_earned
                    'from_user_id' => $from_user_id,
                    'to_user_id' => $adminId,
                    'note' => null,
                    'idempotency_key' => $idempotencyKey,
                    'decision_code' => 'TX_OK',
                    'zone_code' => 'SOUTH',
                ]);
            }

            // 5) audit decision (موحّد للعملية كلها)
            $this->audit()->record([
                'actor_type' => 'user',
                'actor_user_id' => $from_user_id,
                'subject_type' => 'transaction',
                'subject_id' => $primaryId,
                'action' => 'SEND_MONEY_COMPLETED',
                'decision_code' => 'TX_OK',
                'reason' => 'customer → customer send money',
                'transaction_id' => $primaryId,
                'idempotency_key' => $idempotencyKey,
                'zone_code' => $senderWallet->zone_code ?? 'SOUTH',
                'severity' => 'info',
                'context' => [
                    'to_user_id' => $to_user_id,
                    'amount' => $amount,
                    'charge' => $charge,
                ],
            ]);

            // 6) إشعارات async بعد commit (افتراضياً: ما لم يفلش الـ DB، لن ينتشر الـ job)
            DB::afterCommit(function () use ($from_user_id, $to_user_id, $amount, $primaryId) {
                SendTransactionNotificationJob::dispatch(
                    userId: $from_user_id,
                    amount: $amount,
                    transactionType: SEND_MONEY,
                    transactionId: $primaryId,
                );
                SendTransactionNotificationJob::dispatch(
                    userId: $to_user_id,
                    amount: $amount,
                    transactionType: RECEIVED_MONEY,
                    transactionId: $primaryId,
                );
            });

            return $primaryId;
        });

        // AMIAL-RECEIPTS-001 (v0.9-A): إصدار إيصالات بعد commit ناجح.
        // خارج DB::transaction كي لا يفشل المالي بسبب issue الإيصال.
        if ($txId) {
            $this->safeIssueReceipts([
                'from_user_id' => $from_user_id,
                'to_user_id' => $to_user_id,
                'reference_transaction_id' => $txId,
                'receipt_type' => 'send_money',
                'amount' => $amount,
                'fee' => $charge,
                'zone_code' => 'SOUTH',
            ]);
        }

        // AMIAL-LEDGER-001 (v2.1): قيد محاسبي مزدوج بعد commit
        if ($txId) {
            $this->safeLedgerPost(fn() => $this->ledgerTransferWithFee(
                fromUserId: $from_user_id,
                toUserId: $to_user_id,
                grossAmount: $total,      // المبلغ + الرسوم خرجت من المرسل
                feeAmount: $charge,
                sourceType: 'send_money',
                sourceId: $txId,
                description: 'تحويل بين مستخدمين',
            ));

            // AMIAL-MERCHANT-RISK-001 (v2.10): مراقبة المستلم إن كان تاجراً (خلفية)
            $this->maybeAnalyzeMerchantRisk($to_user_id, $from_user_id, $amount);
        }

        return $txId;
    }

    /**
     * AMIAL-MERCHANT-RISK-001 (v2.10): إن كان المستلم تاجراً، أطلق تحليل المخاطر
     * في الخلفية (non-blocking) — لا يبطئ العميل.
     */
    protected function maybeAnalyzeMerchantRisk(int $toUserId, int $fromUserId, string $amount): void
    {
        try {
            $recipient = User::find($toUserId);
            if ($recipient && (int)$recipient->type === 3) { // 3 = merchant
                \App\Jobs\AnalyzeMerchantRiskJob::dispatch($toUserId, $amount, $fromUserId);
            }
        } catch (\Throwable $e) {
            \Log::warning('Merchant risk dispatch failed', ['err' => $e->getMessage()]);
        }
    }

    /**
     * AMIAL-RECEIPTS-001 (v0.9-A) helper.
     *
     * يُصدر إيصالاً مزدوجاً (debit للمرسل + credit للمستلم).
     * يلتقط أي خطأ بصمت — العملية المالية ناجحة بالفعل، فشل الإيصال يُسجَّل ولا يفشل الـ caller.
     *
     * يفترض ReceiptService مُسجَّل في الـ container ومُتاح عبر app() helper.
     */
    protected function safeIssueReceipts(array $data): void
    {
        try {
            // Lazy resolve لأن TransactionTrait قد يُستخدم في contexts بدون container
            if (!class_exists(\App\Services\ReceiptService::class)) {
                return;
            }
            app(\App\Services\ReceiptService::class)->issueDualForTransfer($data);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Receipt issuance failed (non-fatal)', [
                'reference' => $data['reference_transaction_id'] ?? null,
                'type' => $data['receipt_type'] ?? null,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }

    /**
     * صياغة بديلة للإيصال المنفرد (للعمليات أحادية الجانب: cash_in/out, add_money).
     */
    protected function safeIssueSingleReceipt(string $direction, array $data): void
    {
        try {
            if (!class_exists(\App\Services\ReceiptService::class)) {
                return;
            }
            $svc = app(\App\Services\ReceiptService::class);
            if ($direction === 'debit') {
                $svc->issueDebit($data);
            } else {
                $svc->issueCredit($data);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Single receipt issuance failed (non-fatal)', [
                'direction' => $direction,
                'reference' => $data['reference_transaction_id'] ?? null,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }

    /**
    /**
     * Customer cash-out via Agent.
     * - Customer debited (amount + charge)
     * - Agent credited (amount + agent_commission)
     * - Admin credited (charge - agent_commission)
     */
    public function customer_cash_out_transaction(
        int $from_user_id,
        int $to_user_id,
        float|string $amount,
        float|string $charge,
        ?string $note = null,
        ?string $idempotencyKey = null,
        ?string $agentCommissionOverride = null, // AMIAL-FEE-ENGINE-001: حصة الوكيل من المحرّك
        ?array $feeMeta = null,
    ): ?string {
        $amount = MoneyService::normalize($amount);
        $charge = MoneyService::normalize($charge);
        $total = MoneyService::add($amount, $charge);

        // حصة الوكيل: من محرّك الرسوم إن مُرّرت، وإلا fallback للسلوك القديم
        $agentCommission = $agentCommissionOverride !== null
            ? MoneyService::normalize($agentCommissionOverride)
            : MoneyService::normalize(Helpers::get_agent_commission((float)$charge));
        $adminPortion = MoneyService::sub($charge, $agentCommission);

        // AMIAL-ZONE-001 hotfix (v0.7-A.1): defense-in-depth zone/security check
        $this->assertFinancialEligibility($from_user_id);

        $cashOutTxId = DB::transaction(function () use ($from_user_id, $to_user_id, $amount, $charge, $total, $note, $idempotencyKey, $agentCommission, $adminPortion, $feeMeta) {
            // 1) خصم العميل
            $customerWallet = $this->guard()->debit(
                userId: $from_user_id,
                amount: $total,
                reason: 'customer_cash_out',
            );

            $primaryId = $this->newTransactionId();

            $this->recordTransaction([
                'user_id' => $from_user_id,
                'transaction_id' => $primaryId,
                'transaction_type' => CASH_OUT,
                'debit' => $amount,
                'credit' => '0',
                'charge' => $charge,
                'amount' => $amount,
                'balance' => (string)$customerWallet->current_balance,
                'from_user_id' => $from_user_id,
                'to_user_id' => $to_user_id,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $customerWallet->zone_code ?? 'SOUTH',
                'fee_scheme_id' => $feeMeta['scheme_id'] ?? null,
                'fee_scheme_version' => $feeMeta['scheme_version'] ?? null,
            ]);

            // 2) إضافة المبلغ للوكيل
            $agentWallet = $this->guard()->credit($to_user_id, $amount, 'agent_cash_in');
            $this->recordTransaction([
                'user_id' => $to_user_id,
                'transaction_id' => $this->newTransactionId(),
                'ref_trans_id' => $primaryId,
                'transaction_type' => CASH_IN,
                'debit' => '0',
                'credit' => $amount,
                'amount' => $amount,
                'balance' => (string)$agentWallet->current_balance,
                'from_user_id' => $from_user_id,
                'to_user_id' => $to_user_id,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $agentWallet->zone_code ?? 'SOUTH',
            ]);

            // 3) عمولة الوكيل
            if (MoneyService::isPositive($agentCommission)) {
                $agentWallet = $this->guard()->credit($to_user_id, $agentCommission, 'agent_commission');
                $this->recordTransaction([
                    'user_id' => $to_user_id,
                    'transaction_id' => $this->newTransactionId(),
                    'ref_trans_id' => $primaryId,
                    'transaction_type' => AGENT_COMMISSION,
                    'debit' => '0',
                    'credit' => $agentCommission,
                    'amount' => $agentCommission,
                    'balance' => (string)$agentWallet->current_balance,
                    'from_user_id' => $from_user_id,
                    'to_user_id' => $to_user_id,
                    'note' => $note,
                    'idempotency_key' => $idempotencyKey,
                    'decision_code' => 'TX_OK',
                    'zone_code' => $agentWallet->zone_code ?? 'SOUTH',
                ]);
            }

            // 4) رسوم الأدمن (charge_earned)
            if (MoneyService::isPositive($adminPortion)) {
                $adminId = Helpers::get_admin_id();
                $adminWallet = $this->guard()->creditAdminCharge($adminId, $adminPortion);

                $this->recordTransaction([
                    'user_id' => $adminId,
                    'transaction_id' => $this->newTransactionId(),
                    'ref_trans_id' => $primaryId,
                    'transaction_type' => ADMIN_CHARGE,
                    'debit' => '0',
                    'credit' => $adminPortion,
                    'amount' => $adminPortion,
                    'balance' => (string)$adminWallet->charge_earned,
                    'from_user_id' => $from_user_id,
                    'to_user_id' => $adminId,
                    'note' => $note,
                    'idempotency_key' => $idempotencyKey,
                    'decision_code' => 'TX_OK',
                    'zone_code' => 'SOUTH',
                ]);
            }

            $this->audit()->record([
                'actor_type' => 'user',
                'actor_user_id' => $from_user_id,
                'subject_type' => 'transaction',
                'subject_id' => $primaryId,
                'action' => 'CASH_OUT_COMPLETED',
                'decision_code' => 'TX_OK',
                'transaction_id' => $primaryId,
                'idempotency_key' => $idempotencyKey,
                'zone_code' => $customerWallet->zone_code ?? 'SOUTH',
                'severity' => 'info',
                'context' => [
                    'agent_id' => $to_user_id,
                    'amount' => $amount,
                    'charge' => $charge,
                    'agent_commission' => $agentCommission,
                ],
            ]);

            DB::afterCommit(function () use ($from_user_id, $to_user_id, $amount, $primaryId) {
                SendTransactionNotificationJob::dispatch($from_user_id, $amount, CASH_OUT, transactionId: $primaryId);
                SendTransactionNotificationJob::dispatch($to_user_id, $amount, CASH_IN, transactionId: $primaryId);
            });

            return $primaryId;
        });

        // AMIAL-RECEIPTS-001: receipt للعميل (الذي يسحب المال)
        if ($cashOutTxId) {
            $this->safeIssueSingleReceipt('debit', [
                'user_id' => $from_user_id,
                'counterparty_user_id' => $to_user_id,
                'reference_transaction_id' => $cashOutTxId,
                'receipt_type' => 'cash_out',
                'amount' => $amount,
                'fee' => $charge,
                'zone_code' => 'SOUTH',
            ]);
        }

        return $cashOutTxId;
    }

    // ============================================================
    // AMIAL-FEE-ENGINE-001 — ربط محرّك الرسوم بالمسارين
    // ============================================================

    /**
     * تحويل بحساب الرسم من محرّك الرسوم المركزي (FeeService).
     * يحسب الرسم حسب النسخة النشطة لـ SEND_MONEY ثم يفوّض للمنطق المُختبَر،
     * ويخزّن snapshot النسخة المستخدَمة على العملية.
     */
    public function send_money_with_fee_engine(
        int $from_user_id,
        int $to_user_id,
        float|string $amount,
        ?string $note = null,
        ?string $idempotencyKey = null,
        array $context = [],
    ): ?string {
        $breakdown = app(FeeService::class)->calculate('SEND_MONEY', $amount, $context);

        return $this->customer_send_money_transaction(
            from_user_id: $from_user_id,
            to_user_id: $to_user_id,
            amount: $amount,
            charge: $breakdown['fee'],
            note: $note,
            idempotencyKey: $idempotencyKey,
            feeMeta: [
                'scheme_id' => $breakdown['scheme_id'],
                'scheme_version' => $breakdown['scheme_version'],
            ],
        );
    }

    /**
     * سحب نقدي بحساب الرسم وحصة الوكيل من محرّك الرسوم المركزي (FeeService).
     */
    public function cash_out_with_fee_engine(
        int $from_user_id,
        int $to_user_id,
        float|string $amount,
        ?string $note = null,
        ?string $idempotencyKey = null,
        array $context = [],
    ): ?string {
        $breakdown = app(FeeService::class)->calculate('CASH_OUT', $amount, $context);

        return $this->customer_cash_out_transaction(
            from_user_id: $from_user_id,
            to_user_id: $to_user_id,
            amount: $amount,
            charge: $breakdown['fee'],
            note: $note,
            idempotencyKey: $idempotencyKey,
            agentCommissionOverride: $breakdown['agent_commission'],
            feeMeta: [
                'scheme_id' => $breakdown['scheme_id'],
                'scheme_version' => $breakdown['scheme_version'],
            ],
        );
    }

    // ============================================================
    // AMIAL-MERCHANT-PAY-001 — دفع التاجر (QR / POS)
    // ============================================================

    /**
     * دفع عميل → تاجر عبر QR أو POS.
     * - العميل يُخصم منه `amount` كاملاً.
     * - التاجر يُضاف له `amount - fee` (التاجر يتحمّل الرسم — bearer=merchant).
     * - المنصّة (الأدمن) تكسب `fee`.
     * - الرسم من محرّك الرسوم: MERCHANT_QR أو MERCHANT_POS.
     * - يُنسب لموظف POS عبر pos_user_id إن وُجد (المال يبقى لحساب التاجر الرئيسي).
     *
     * @param string $channel  'qr' | 'pos'
     */
    public function merchant_payment_transaction(
        int $customer_user_id,
        int $merchant_user_id,
        float|string $amount,
        string $channel = 'qr',
        ?int $pos_user_id = null,
        ?string $note = null,
        ?string $idempotencyKey = null,
        ?int $split_bill_id = null,
        ?int $split_participant_id = null,
        array $context = [],
    ): ?string {
        $amount = MoneyService::normalize($amount);
        if (!MoneyService::isPositive($amount)) {
            throw new \InvalidArgumentException('Merchant payment amount must be positive');
        }

        $code = $channel === 'pos' ? 'MERCHANT_POS' : 'MERCHANT_QR';
        $receiptType = $channel === 'pos' ? 'pos_payment' : 'qr_payment';

        $breakdown = app(FeeService::class)->calculate(
            $code,
            $amount,
            array_merge(['applies_to' => 'merchant'], $context)
        );
        $fee = $breakdown['fee'];
        $net = MoneyService::sub($amount, $fee); // ما يصل للتاجر

        $this->assertFinancialEligibility($customer_user_id);

        $txId = DB::transaction(function () use (
            $customer_user_id, $merchant_user_id, $amount, $fee, $net,
            $note, $idempotencyKey, $breakdown, $pos_user_id,
            $split_bill_id, $split_participant_id
        ) {
            // AMIAL-SCALE-DEADLOCK-001 — قفل بترتيب ثابت قبل أي خصم/إضافة
            $this->guard()->lockWalletsOrdered([$customer_user_id, $merchant_user_id]);

            // 1) خصم العميل (المبلغ كاملاً — التاجر يتحمّل الرسم)
            $customerWallet = $this->guard()->debit(
                userId: $customer_user_id,
                amount: $amount,
                reason: 'merchant_payment',
            );

            $primaryId = $this->newTransactionId();

            $this->recordTransaction([
                'user_id' => $customer_user_id,
                'transaction_id' => $primaryId,
                'transaction_type' => 'merchant_payment',
                'debit' => $amount,
                'credit' => '0',
                'charge' => $fee,
                'amount' => $amount,
                'balance' => (string)$customerWallet->current_balance,
                'from_user_id' => $customer_user_id,
                'to_user_id' => $merchant_user_id,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $customerWallet->zone_code ?? 'SOUTH',
                'fee_scheme_id' => $breakdown['scheme_id'],
                'fee_scheme_version' => $breakdown['scheme_version'],
                'pos_user_id' => $pos_user_id,
                'split_bill_id' => $split_bill_id,
                'split_participant_id' => $split_participant_id,
            ]);

            // 2) إضافة الصافي لحساب التاجر الرئيسي
            $merchantWallet = $this->guard()->credit($merchant_user_id, $net, 'merchant_received');
            $this->recordTransaction([
                'user_id' => $merchant_user_id,
                'transaction_id' => $this->newTransactionId(),
                'ref_trans_id' => $primaryId,
                'transaction_type' => 'merchant_received',
                'debit' => '0',
                'credit' => $net,
                'charge' => '0',
                'amount' => $net,
                'balance' => (string)$merchantWallet->current_balance,
                'from_user_id' => $customer_user_id,
                'to_user_id' => $merchant_user_id,
                'note' => $note,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $merchantWallet->zone_code ?? 'SOUTH',
                'pos_user_id' => $pos_user_id,
            ]);

            // 3) ربح المنصّة (charge_earned للأدمن)
            if (MoneyService::isPositive($fee)) {
                $adminId = Helpers::get_admin_id();
                $adminWallet = $this->guard()->creditAdminCharge($adminId, $fee);
                $this->recordTransaction([
                    'user_id' => $adminId,
                    'transaction_id' => $this->newTransactionId(),
                    'ref_trans_id' => $primaryId,
                    'transaction_type' => ADMIN_CHARGE,
                    'debit' => '0',
                    'credit' => $fee,
                    'charge' => '0',
                    'amount' => $fee,
                    'balance' => (string)$adminWallet->charge_earned,
                    'from_user_id' => $customer_user_id,
                    'to_user_id' => $adminId,
                    'note' => null,
                    'idempotency_key' => $idempotencyKey,
                    'decision_code' => 'TX_OK',
                    'zone_code' => 'SOUTH',
                ]);
            }

            // 4) audit
            $this->audit()->record([
                'actor_type' => 'user',
                'actor_user_id' => $customer_user_id,
                'subject_type' => 'transaction',
                'subject_id' => $primaryId,
                'action' => 'MERCHANT_PAYMENT_COMPLETED',
                'decision_code' => 'TX_OK',
                'transaction_id' => $primaryId,
                'idempotency_key' => $idempotencyKey,
                'zone_code' => $customerWallet->zone_code ?? 'SOUTH',
                'severity' => 'info',
                'context' => [
                    'merchant_user_id' => $merchant_user_id,
                    'pos_user_id' => $pos_user_id,
                    'amount' => $amount,
                    'fee' => $fee,
                    'net' => $net,
                ],
            ]);

            // 5) إشعارات async بعد commit
            DB::afterCommit(function () use ($customer_user_id, $merchant_user_id, $amount, $net, $primaryId) {
                SendTransactionNotificationJob::dispatch($customer_user_id, $amount, 'merchant_payment', transactionId: $primaryId);
                SendTransactionNotificationJob::dispatch($merchant_user_id, $net, 'merchant_received', transactionId: $primaryId);
            });

            return $primaryId;
        });

        if ($txId) {
            $this->safeIssueReceipts([
                'from_user_id' => $customer_user_id,
                'to_user_id' => $merchant_user_id,
                'reference_transaction_id' => $txId,
                'receipt_type' => $receiptType,
                'amount' => $amount,
                'fee' => $fee,
                'zone_code' => 'SOUTH',
            ]);

            $this->safeLedgerPost(fn() => $this->ledgerTransferWithFee(
                fromUserId: $customer_user_id,
                toUserId: $merchant_user_id,
                grossAmount: $amount,
                feeAmount: $fee,
                sourceType: 'merchant_payment',
                sourceId: $txId,
                description: 'دفع تاجر',
            ));

            $this->maybeAnalyzeMerchantRisk($merchant_user_id, $customer_user_id, $amount);
        }

        return $txId;
    }

    /**
     * Request money: same pattern as send_money — included for API compat.
     */
    public function customer_request_money_transaction(
        int $from_user_id,
        int $to_user_id,
        float|string $amount,
        float|string $charge,
        ?string $note = null,
        ?string $idempotencyKey = null,
    ): ?string {
        // نفس الـ send_money — نعيد التوجيه
        return $this->customer_send_money_transaction(
            $from_user_id, $to_user_id, $amount, $charge, $note, $idempotencyKey
        );
    }

    /** ========= AGENT ↔ CUSTOMER ========= */

    /**
     * Agent cash-in to customer.
     * Agent debited (amount), Customer credited (amount).
     */
    public function cash_in_transaction(
        int $from_user_id,
        int $to_user_id,
        float|string $amount,
        ?string $idempotencyKey = null,
    ): ?string {
        $amount = MoneyService::normalize($amount);

        // AMIAL-ZONE-001 hotfix (v0.7-A.1)
        $this->assertFinancialEligibility($from_user_id);
        $this->assertFinancialEligibility($to_user_id);

        // AMIAL-AGENT-NETWORK-001 (v2.3): فحص حدود الوكيل قبل cash-in
        app(\App\Services\AgentNetworkService::class)->assertCashInAllowed($from_user_id, $amount);

        $txId = DB::transaction(function () use ($from_user_id, $to_user_id, $amount, $idempotencyKey) {
            $agentWallet = $this->guard()->debit($from_user_id, $amount, 'agent_cash_in_debit');
            $primaryId = $this->newTransactionId();

            $this->recordTransaction([
                'user_id' => $from_user_id,
                'transaction_id' => $primaryId,
                'transaction_type' => CASH_OUT,
                'debit' => $amount,
                'credit' => '0',
                'amount' => $amount,
                'balance' => (string)$agentWallet->current_balance,
                'from_user_id' => $from_user_id,
                'to_user_id' => $to_user_id,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $agentWallet->zone_code ?? 'SOUTH',
            ]);

            $customerWallet = $this->guard()->credit($to_user_id, $amount, 'customer_cash_in');
            $this->recordTransaction([
                'user_id' => $to_user_id,
                'transaction_id' => $this->newTransactionId(),
                'ref_trans_id' => $primaryId,
                'transaction_type' => CASH_IN,
                'debit' => '0',
                'credit' => $amount,
                'amount' => $amount,
                'balance' => (string)$customerWallet->current_balance,
                'from_user_id' => $from_user_id,
                'to_user_id' => $to_user_id,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $customerWallet->zone_code ?? 'SOUTH',
            ]);

            $this->audit()->record([
                'actor_type' => 'agent',
                'actor_user_id' => $from_user_id,
                'subject_type' => 'transaction',
                'subject_id' => $primaryId,
                'action' => 'CASH_IN_COMPLETED',
                'decision_code' => 'TX_OK',
                'transaction_id' => $primaryId,
                'idempotency_key' => $idempotencyKey,
                'severity' => 'info',
                'context' => ['customer_id' => $to_user_id, 'amount' => $amount],
            ]);

            DB::afterCommit(function () use ($from_user_id, $to_user_id, $amount, $primaryId) {
                SendTransactionNotificationJob::dispatch($from_user_id, $amount, CASH_OUT, transactionId: $primaryId);
                SendTransactionNotificationJob::dispatch($to_user_id, $amount, CASH_IN, transactionId: $primaryId);
            });

            return $primaryId;
        });

        // AMIAL-LEDGER-001 (v2.2): قيد محاسبي للوكيل (cash-in) بعد commit
        if ($txId) {
            $this->safeLedgerPost(fn() => $this->ledgerTransfer(
                fromUserId: $from_user_id,   // الوكيل يخصم من محفظته
                toUserId: $to_user_id,        // العميل يستلم
                amount: $amount,
                sourceType: 'agent_cash_in',
                sourceId: $txId,
                description: 'إيداع وكيل لعميل (Cash-In)',
            ));

            // AMIAL-AGENT-NETWORK-001 (v2.3): تتبع سيولة الوكيل
            try {
                app(\App\Services\AgentNetworkService::class)
                    ->recordFloatMovement($from_user_id, 'cash_in', $amount);
            } catch (\Throwable $e) {
                \Log::warning('Float tracking failed', ['err' => $e->getMessage()]);
            }
        }

        return $txId;
    }

    /** ========= ADMIN ↔ USER (top-up + bonus) ========= */

    public static function add_money_transaction(
        int $from_user_id,
        int $to_user_id,
        float|string $amount,
        ?string $idempotencyKey = null,
    ): ?string {
        $amount = MoneyService::normalize($amount);
        $userInfo = User::find($to_user_id);
        $userType = $userInfo->type == 1 ? 'agent' : ($userInfo->type == 2 ? 'customer' : null);
        $bonus = MoneyService::normalize(
            Helpers::get_add_money_bonus((float)$amount, $to_user_id, $userType)
        );

        // AMIAL-ZONE-001 hotfix (v0.7-A.1): فحص zone للمستلم.
        // الأدمن (from_user_id) لا نفحصه — admin user دائماً مسموح كحامل لأموال النظام.
        // لكن المستلم: لو محفظته خارج SOUTH أو في security_hold، نرفض إضافة المال.
        if (!$userInfo) {
            throw new \RuntimeException("Recipient user {$to_user_id} not found");
        }
        if ($userInfo->security_hold_until && $userInfo->security_hold_until->isFuture()) {
            throw new \RuntimeException(
                "Recipient is in security hold until {$userInfo->security_hold_until}"
            );
        }
        $recipientZone = $userInfo->zone_code ?? 'UNKNOWN';
        if ($recipientZone !== 'SOUTH') {
            // نلوغ ونرفض — قرار الإدارة لا يتجاوز سياسة zone
            app(AuditService::class)->record([
                'actor_type' => 'admin',
                'actor_user_id' => $from_user_id,
                'subject_type' => 'user',
                'subject_id' => (string)$to_user_id,
                'action' => 'ADD_MONEY_BLOCKED_BY_ZONE',
                'decision_code' => 'TX_ZONE_BLOCKED',
                'reason' => "Recipient zone={$recipientZone} != SOUTH",
                'zone_code' => $recipientZone,
                'severity' => 'warning',
                'context' => ['amount' => $amount],
            ]);
            throw new \RuntimeException(
                "Cannot add money to user in zone {$recipientZone} (only SOUTH allowed)"
            );
        }

        /** @var FinancialGuardService $guard */
        $guard = app(FinancialGuardService::class);
        /** @var AuditService $audit */
        $audit = app(AuditService::class);

        $txId = DB::transaction(function () use ($from_user_id, $to_user_id, $amount, $bonus, $idempotencyKey, $guard, $audit) {
            $adminWallet = $guard->debit($from_user_id, $amount, 'admin_add_money');
            $primaryId = (string) Str::ulid();

            // primary admin debit
            Transaction::create([
                'user_id' => $from_user_id,
                'transaction_id' => $primaryId,
                'transaction_type' => SEND_MONEY,
                'debit' => $amount,
                'credit' => '0',
                'amount' => $amount,
                'balance' => (string)$adminWallet->current_balance,
                'from_user_id' => $from_user_id,
                'to_user_id' => $to_user_id,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => 'SOUTH',
            ]);

            // bonus debit on admin (if any)
            if (MoneyService::isPositive($bonus)) {
                $adminWallet = $guard->debit($from_user_id, $bonus, 'admin_add_money_bonus');
                Transaction::create([
                    'user_id' => $from_user_id,
                    'transaction_id' => (string) Str::ulid(),
                    'ref_trans_id' => $primaryId,
                    'transaction_type' => EXPENSE,
                    'debit' => $bonus,
                    'credit' => '0',
                    'amount' => $bonus,
                    'balance' => (string)$adminWallet->current_balance,
                    'from_user_id' => $from_user_id,
                    'to_user_id' => $to_user_id,
                    'bonus_id' => Helpers::get_applied_add_money_bonus((float)$amount, $to_user_id, 'customer')->id ?? null,
                    'idempotency_key' => $idempotencyKey,
                    'decision_code' => 'TX_OK',
                    'zone_code' => 'SOUTH',
                ]);
            }

            // credit customer
            $customerWallet = $guard->credit($to_user_id, $amount, 'customer_top_up');
            Transaction::create([
                'user_id' => $to_user_id,
                'transaction_id' => (string) Str::ulid(),
                'ref_trans_id' => $primaryId,
                'transaction_type' => ADD_MONEY,
                'debit' => '0',
                'credit' => $amount,
                'amount' => $amount,
                'balance' => (string)$customerWallet->current_balance,
                'from_user_id' => $from_user_id,
                'to_user_id' => $to_user_id,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $customerWallet->zone_code ?? 'SOUTH',
            ]);

            if (MoneyService::isPositive($bonus)) {
                $customerWallet = $guard->credit($to_user_id, $bonus, 'customer_top_up_bonus');
                Transaction::create([
                    'user_id' => $to_user_id,
                    'transaction_id' => (string) Str::ulid(),
                    'ref_trans_id' => $primaryId,
                    'transaction_type' => ADD_MONEY_BONUS,
                    'debit' => '0',
                    'credit' => $bonus,
                    'amount' => $bonus,
                    'balance' => (string)$customerWallet->current_balance,
                    'from_user_id' => $from_user_id,
                    'to_user_id' => $to_user_id,
                    'idempotency_key' => $idempotencyKey,
                    'decision_code' => 'TX_OK',
                    'zone_code' => $customerWallet->zone_code ?? 'SOUTH',
                ]);
            }

            $audit->record([
                'actor_type' => 'admin',
                'actor_user_id' => $from_user_id,
                'subject_type' => 'transaction',
                'subject_id' => $primaryId,
                'action' => 'ADD_MONEY_COMPLETED',
                'decision_code' => 'TX_OK',
                'transaction_id' => $primaryId,
                'idempotency_key' => $idempotencyKey,
                'severity' => 'info',
                'context' => ['customer_id' => $to_user_id, 'amount' => $amount, 'bonus' => $bonus],
            ]);

            $total = MoneyService::add($amount, $bonus);
            $type = MoneyService::isPositive($bonus) ? ADD_MONEY_BONUS : ADD_MONEY;
            DB::afterCommit(function () use ($to_user_id, $total, $type, $primaryId) {
                SendTransactionNotificationJob::dispatch($to_user_id, $total, $type, transactionId: $primaryId);
            });

            return $primaryId;
        });

        // AMIAL-LEDGER-001 (v2.2): قيد محاسبي لإضافة الرصيد (static — نستدعي الخدمة مباشرة)
        if ($txId ?? null) {
            try {
                $ledger = app(\App\Services\LedgerService::class);
                $reserve = $ledger->getOrCreateSystemAccount(
                    'CASH_RESERVE', 'asset', 'احتياطي النقد (إيداعات النظام)', 'debit'
                );
                $userWallet = $ledger->getOrCreateUserWallet($to_user_id);
                $total = MoneyService::add($amount, $bonus);
                $ledger->post(
                    sourceType: 'add_money',
                    sourceId: $txId,
                    description: 'إضافة رصيد من النظام',
                    lines: [
                        ['account' => $reserve->account_code, 'direction' => 'debit', 'amount' => $total],
                        ['account' => $userWallet->account_code, 'direction' => 'credit', 'amount' => $total],
                    ],
                    idempotencyKey: "add_money_{$txId}",
                );
            } catch (\Throwable $e) {
                \Log::error('Ledger post failed for add_money (non-blocking)', ['err' => $e->getMessage()]);
            }
        }

        return $txId;
    }

    /**
     * Admin يقبل withdraw. الخصم من pending_balance + إضافة للأدمن + رسوم.
     */
    public function accept_withdraw_transaction(
        int $receiver_user_id,
        float|string $amount,
        float|string $charge,
        ?string $idempotencyKey = null,
    ): void {
        $amount = MoneyService::normalize($amount);
        $charge = MoneyService::normalize($charge);
        $total = MoneyService::add($amount, $charge);

        $adminUserId = Helpers::get_admin_id();

        // AMIAL-ZONE-001 hotfix (v0.7-A.1)
        $this->assertFinancialEligibility($receiver_user_id);

        DB::transaction(function () use ($adminUserId, $receiver_user_id, $amount, $charge, $total, $idempotencyKey) {
            // قفل محفظة العميل
            $userWallet = $this->guard()->lockWallet($receiver_user_id);

            // فحص pending_balance (مكسور في الأصلي — راجع AUDIT 2.5)
            if (!MoneyService::gte((string)$userWallet->pending_balance, $total)) {
                $this->audit()->record([
                    'actor_type' => 'admin',
                    'actor_user_id' => $adminUserId,
                    'subject_type' => 'wallet',
                    'subject_id' => (string)$receiver_user_id,
                    'action' => 'WITHDRAW_DENIED',
                    'decision_code' => 'TX_INSUFFICIENT_PENDING',
                    'reason' => 'pending_balance < amount + charge',
                    'idempotency_key' => $idempotencyKey,
                    'severity' => 'warning',
                ]);

                throw new InsufficientBalanceException(
                    userId: $receiver_user_id,
                    required: $total,
                    available: (string)$userWallet->pending_balance,
                    message: 'Insufficient pending balance for withdraw',
                );
            }

            $userWallet->pending_balance = MoneyService::sub((string)$userWallet->pending_balance, $total);
            $userWallet->version = $userWallet->version + 1;
            $userWallet->save();

            $primaryId = $this->newTransactionId();

            $this->recordTransaction([
                'user_id' => $receiver_user_id,
                'transaction_id' => $primaryId,
                'transaction_type' => WITHDRAW,
                'debit' => $amount,
                'credit' => '0',
                'charge' => $charge,
                'amount' => $amount,
                'balance' => (string)$userWallet->current_balance,
                'from_user_id' => $receiver_user_id,
                'to_user_id' => $adminUserId,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => $userWallet->zone_code ?? 'SOUTH',
            ]);

            // إضافة المبلغ لرصيد admin (current_balance)
            $adminWallet = $this->guard()->credit($adminUserId, $amount, 'admin_withdraw_settled');
            $this->recordTransaction([
                'user_id' => $adminUserId,
                'transaction_id' => $this->newTransactionId(),
                'ref_trans_id' => $primaryId,
                'transaction_type' => WITHDRAW,
                'debit' => '0',
                'credit' => $amount,
                'amount' => $amount,
                'balance' => (string)$adminWallet->current_balance,
                'from_user_id' => $receiver_user_id,
                'to_user_id' => $adminUserId,
                'idempotency_key' => $idempotencyKey,
                'decision_code' => 'TX_OK',
                'zone_code' => 'SOUTH',
            ]);

            // رسوم withdraw → charge_earned
            if (MoneyService::isPositive($charge)) {
                $adminWallet = $this->guard()->creditAdminCharge($adminUserId, $charge);
                $this->recordTransaction([
                    'user_id' => $adminUserId,
                    'transaction_id' => $this->newTransactionId(),
                    'ref_trans_id' => $primaryId,
                    'transaction_type' => ADMIN_CHARGE,
                    'debit' => '0',
                    'credit' => $charge,
                    'amount' => $charge,
                    'balance' => (string)$adminWallet->charge_earned,
                    'from_user_id' => $receiver_user_id,
                    'to_user_id' => $adminUserId,
                    'idempotency_key' => $idempotencyKey,
                    'decision_code' => 'TX_OK',
                    'zone_code' => 'SOUTH',
                ]);
            }

            $this->audit()->record([
                'actor_type' => 'admin',
                'actor_user_id' => $adminUserId,
                'subject_type' => 'transaction',
                'subject_id' => $primaryId,
                'action' => 'WITHDRAW_APPROVED',
                'decision_code' => 'TX_OK',
                'transaction_id' => $primaryId,
                'idempotency_key' => $idempotencyKey,
                'severity' => 'info',
                'context' => ['receiver_id' => $receiver_user_id, 'amount' => $amount, 'charge' => $charge],
            ]);

            DB::afterCommit(function () use ($receiver_user_id, $amount, $primaryId) {
                SendTransactionNotificationJob::dispatch($receiver_user_id, $amount, WITHDRAW, transactionId: $primaryId);
            });
        });
    }

    /** ========= DISPUTE ========= */

    public function disputeTransaction(
        string $ref_transaction_id,
        int $dispute_claimed_user_id,
        int $disputed_user_id,
        float|string $amount,
    ): void {
        $amount = MoneyService::normalize($amount);

        // AMIAL-ZONE-001 hotfix (v0.7-A.1): فحص zone للطرفين.
        // dispute resolution هو admin action، لكنه ينقل مالاً بين محفظتَيْن.
        // السياسة: لا تحركات مالية على محافظ خارج SOUTH.
        // لو الـ admin أراد resolve لـ user خارج SOUTH، عليه أولاً تحديث الـ zone عبر
        // `php artisan amial:zone set <user_id> SOUTH` أو معالجة الـ dispute يدوياً خارج النظام.
        $this->assertFinancialEligibility($dispute_claimed_user_id);
        $this->assertFinancialEligibility($disputed_user_id);

        DB::transaction(function () use ($ref_transaction_id, $dispute_claimed_user_id, $disputed_user_id, $amount) {
            // إضافة للمطالِب
            $claimedWallet = $this->guard()->credit($dispute_claimed_user_id, $amount, 'dispute_refund');

            $this->recordTransaction([
                'user_id' => $dispute_claimed_user_id,
                'transaction_id' => $this->newTransactionId(),
                'ref_trans_id' => $ref_transaction_id,
                'transaction_type' => ADDED_DISPUTE_MONEY,
                'debit' => '0',
                'credit' => $amount,
                'amount' => $amount,
                'balance' => (string)$claimedWallet->current_balance,
                'from_user_id' => Helpers::get_admin_id(),
                'to_user_id' => $dispute_claimed_user_id,
                'note' => 'Refund received due to dispute',
                'decision_code' => 'DISPUTE_RESOLVED',
                'zone_code' => $claimedWallet->zone_code ?? 'SOUTH',
            ]);

            // خصم من المتنازَع عليه
            $disputedWallet = $this->guard()->debit($disputed_user_id, $amount, 'dispute_deduction');

            $this->recordTransaction([
                'user_id' => $disputed_user_id,
                'transaction_id' => $this->newTransactionId(),
                'ref_trans_id' => $ref_transaction_id,
                'transaction_type' => DEDUCTED_DISPUTE_MONEY,
                'debit' => $amount,
                'credit' => '0',
                'amount' => $amount,
                'balance' => (string)$disputedWallet->current_balance,
                'from_user_id' => $disputed_user_id,
                'to_user_id' => Helpers::get_admin_id(),
                'note' => 'Amount reversed due to dispute',
                'decision_code' => 'DISPUTE_RESOLVED',
                'zone_code' => $disputedWallet->zone_code ?? 'SOUTH',
            ]);

            $this->audit()->record([
                'actor_type' => 'admin',
                'subject_type' => 'transaction',
                'subject_id' => $ref_transaction_id,
                'action' => 'DISPUTE_RESOLVED',
                'decision_code' => 'DISPUTE_RESOLVED',
                'transaction_id' => $ref_transaction_id,
                'severity' => 'notice',
                'context' => [
                    'claimed_user_id' => $dispute_claimed_user_id,
                    'disputed_user_id' => $disputed_user_id,
                    'amount' => $amount,
                ],
            ]);
        });
    }
}
