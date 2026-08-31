<?php

namespace App\Services;

use App\Models\PendingTransfer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * AMIAL-TRANSFER-COOLDOWN-001 (v2.7)
 *
 * PendingTransferService — تحويل بنافذة إلغاء + تأكيد PIN.
 *
 * **التدفق:**
 *   1. initiate(): يتحقق PIN → يخصم من المرسل (حجز) → ينشئ pending (holding)
 *   2. خلال النافذة (60 ثانية): المرسل يستطيع cancel() → استرداد كامل
 *   3. بعد النافذة: job يستدعي release() → المال يصل المستلم
 *
 * **الأمان:**
 *   - المال محجوز فعلاً (مخصوم من المرسل) فلا double-spend
 *   - لا يصل المستلم إلا بعد النافذة → الإلغاء آمن 100%
 *   - PIN مطلوب لكل تحويل (حماية إضافية)
 *   - الإلغاء فقط للمرسل وفقط ضمن النافذة
 */
class PendingTransferService
{
    use \App\Traits\PostsToLedger;
    use \App\Traits\EnforcesFinancialPolicy;

    private const DEFAULT_COOLDOWN_SECONDS = 60;

    public function __construct(
        private readonly FinancialGuardService $guard,
        private readonly TransactionPinService $pinService,
        private readonly AuditService $audit,
        private readonly ReceiptService $receipts,
    ) {}

    /**
     * بدء تحويل: تأكيد PIN + حجز المبلغ + إنشاء pending.
     *
     * @throws RuntimeException
     */
    public function initiate(
        User $sender,
        User $recipient,
        string $amount,
        string $pin,
        string $fee = '0',
        ?string $note = null,
        ?int $cooldownSeconds = null,
    ): PendingTransfer {
        $amount = MoneyService::normalize($amount);
        $fee = MoneyService::normalize($fee);
        $total = MoneyService::add($amount, $fee);
        // AMIAL-SPEED: النافذة قابلة للضبط من البيئة (AMIAL_TRANSFER_COOLDOWN)
        $cooldown = $cooldownSeconds
            ?? (int) config('app.amial_transfer_cooldown', self::DEFAULT_COOLDOWN_SECONDS);
        $cooldown = max(5, $cooldown);

        // 1) فحوصات أساسية
        if ($sender->id === $recipient->id) {
            throw new RuntimeException('لا يمكنك التحويل لنفسك');
        }
        if (bccomp($amount, '0', 4) <= 0) {
            throw new RuntimeException('المبلغ يجب أن يكون موجباً');
        }

        // 2) تحويل المحافظ حركة دفتر داخليّة. ZonePolicyService هو مصدر
        // السياسة: كل منطقة معلومة مسموحة هنا، بينما SOUTH خاص بالنقد الذي
        // يعبر حدّ التشغيل. استخدام enforceFinancialPolicy هنا كان يعيد
        // الحارس القديم ويمنع مستلمين موثّقين في الشمال بلا مبرر.
        $senderPolicy = app(ZonePolicyService::class)->authorize($sender, 'send_money');
        $recipientPolicy = app(ZonePolicyService::class)->authorize($recipient, 'send_money');
        if (!$senderPolicy['allowed']) {
            throw new RuntimeException($this->ledgerPolicyMessage($senderPolicy, 'بدء التحويل'));
        }
        if (!$recipientPolicy['allowed']) {
            throw new RuntimeException($this->ledgerPolicyMessage($recipientPolicy, 'التحويل إلى هذا الحساب'));
        }
        if ((int) ($recipient->is_kyc_verified ?? 0) !== 1) {
            throw new RuntimeException('لا يمكن التحويل إلى حساب لم يُعتمد بعد');
        }
        $this->enforceSanction($sender);
        $this->enforceSanction($recipient);
        $this->enforceKycTier($sender, 'send_money', $amount);

        // 3) تأكيد PIN (مطلوب لكل تحويل — AMIAL-PIN-ALL-001)
        if (!$this->pinService->verify($sender, $pin)) {
            throw new RuntimeException('رمز PIN غير صحيح أو الحساب مقفل مؤقتاً');
        }

        // 4) حجز المبلغ (خصم فعلي من المرسل) + إنشاء pending
        return DB::transaction(function () use ($sender, $recipient, $amount, $fee, $total, $note, $cooldown) {
            $transferUlid = (string) Str::ulid();

            // خصم المرسل (المال محجوز الآن، لا يملكه أحد)
            $this->guard->debit($sender->id, $total, "pending_transfer_hold:{$transferUlid}");

            $pending = PendingTransfer::create([
                'transfer_ulid' => $transferUlid,
                'sender_user_id' => $sender->id,
                'recipient_user_id' => $recipient->id,
                'amount' => $amount,
                'fee' => $fee,
                'total_debited' => $total,
                'note' => $note ? mb_substr($note, 0, 500) : null,
                'status' => 'holding',
                'releasable_at' => now()->addSeconds($cooldown),
                'hold_transaction_id' => $transferUlid,
                'idempotency_key' => "pending_{$transferUlid}",
                'zone_code' => 'SOUTH',
            ]);

            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $sender->id,
                'subject_type' => 'pending_transfer',
                'subject_id' => $transferUlid,
                'action' => 'TRANSFER_INITIATED',
                'decision_code' => 'HOLDING',
                'severity' => 'info',
                'context' => ['recipient' => $recipient->id, 'amount' => $amount, 'cooldown' => $cooldown],
            ]);

            // AMIAL-SPEED: مهمة مؤجّلة تسلّم في لحظة انتهاء النافذة بالضبط —
            // كان الاعتماد على المجدول الدوري فقط (كل 60 ثانية) فيتأخر التسليم
            // حتى دقيقتين. المجدول يبقى شبكة أمان لو تعطل الطابور.
            DB::afterCommit(function () use ($cooldown) {
                \App\Jobs\ReleasePendingTransfersJob::dispatch()
                    ->delay(now()->addSeconds($cooldown + 1));
            });

            return $pending;
        });
    }

    /**
     * AMIAL-SPEED: تسليم كسول — إن استُعلم عن تحويل انتهت نافذته وما زال
     * معلّقاً (الطابور متأخر مثلاً)، نسلّمه فوراً في نفس الطلب حتى يرى
     * المستخدم «مكتمل» لحظة انتهاء العدّاد.
     */
    public function releaseIfDue(PendingTransfer $pending): PendingTransfer
    {
        if ($pending->status === 'holding' && !$pending->releasable_at->isFuture()) {
            try {
                return $this->release($pending);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Lazy release failed', [
                    'ulid' => $pending->transfer_ulid,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }
        return $pending->fresh();
    }

    /**
     * إلغاء التحويل خلال النافذة → استرداد كامل للمرسل.
     *
     * @throws RuntimeException
     */
    public function cancel(PendingTransfer $pending, User $sender, string $reason = 'إلغاء بطلب المرسل'): PendingTransfer
    {
        // فقط المرسل يستطيع الإلغاء
        if ($pending->sender_user_id !== $sender->id) {
            throw new RuntimeException('غير مصرح لك بإلغاء هذا التحويل');
        }

        return DB::transaction(function () use ($pending, $reason) {
            $locked = PendingTransfer::where('id', $pending->id)->lockForUpdate()->first();

            if ($locked->status !== 'holding') {
                throw new RuntimeException('لا يمكن الإلغاء — التحويل لم يعد معلّقاً');
            }
            if (!$locked->releasable_at->isFuture()) {
                throw new RuntimeException('انتهت نافذة الإلغاء — التحويل قيد التسليم');
            }

            // استرداد المبلغ الكامل للمرسل
            $this->guard->credit($locked->sender_user_id, (string)$locked->total_debited,
                "pending_transfer_refund:{$locked->transfer_ulid}");

            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => mb_substr($reason, 0, 300),
            ]);

            $this->audit->record([
                'actor_type' => 'user',
                'actor_user_id' => $locked->sender_user_id,
                'subject_type' => 'pending_transfer',
                'subject_id' => $locked->transfer_ulid,
                'action' => 'TRANSFER_CANCELLED',
                'decision_code' => 'CANCELLED',
                'severity' => 'info',
                'context' => ['reason' => $reason, 'refunded' => (string)$locked->total_debited],
            ]);

            return $locked->fresh();
        });
    }

    /**
     * تسليم التحويل للمستلم (بعد انتهاء النافذة). يُستدعى من Job.
     *
     * @throws RuntimeException
     */
    public function release(PendingTransfer $pending): PendingTransfer
    {
        $result = DB::transaction(function () use ($pending) {
            $locked = PendingTransfer::where('id', $pending->id)->lockForUpdate()->first();

            if ($locked->status !== 'holding') {
                // سبق إلغاؤه أو تسليمه — لا شيء نفعله
                return ['transfer' => $locked->fresh(), 'delivered' => false];
            }
            if ($locked->releasable_at->isFuture()) {
                throw new RuntimeException('النافذة لم تنتهِ بعد');
            }

            // تأكد أن المستلم ما زال مؤهلاً (قد يكون حُظر خلال النافذة)
            $recipient = User::find($locked->recipient_user_id);
            if (!$recipient || (int) ($recipient->is_kyc_verified ?? 0) !== 1
                || in_array(($recipient->zone_code ?? 'UNKNOWN'), ['', 'UNKNOWN'], true)
                || ($recipient->sanction_status ?? 'clear') === 'blocked') {
                // المستلم لم يعد مؤهلاً → استرداد للمرسل
                $this->guard->credit($locked->sender_user_id, (string)$locked->total_debited,
                    "pending_transfer_failed:{$locked->transfer_ulid}");
                $locked->update([
                    'status' => 'failed',
                    'cancellation_reason' => 'المستلم لم يعد مؤهلاً لاستقبال التحويل',
                ]);
                return ['transfer' => $locked->fresh(), 'delivered' => false];
            }

            // تسليم المبلغ للمستلم (الرسوم تذهب للمنصة، تبقى محجوزة)
            $releaseTxId = (string) Str::ulid();
            $receiverWallet = $this->guard->credit($locked->recipient_user_id, (string)$locked->amount,
                "pending_transfer_release:{$locked->transfer_ulid}");

            // AMIAL-FIX(HISTORY): كان التسليم يحرّك المال بلا صفوف في جدول
            // transactions — فلا يظهر التحويل في سجل العمليات ولا التقارير.
            // نكتب نفس صفوف 6cash (مرسِل send_money / مستلِم received_money /
            // رسوم admin_charge) حتى تتغذى كل الشاشات القائمة عليها.
            $senderWallet = \App\Models\EMoney::where('user_id', $locked->sender_user_id)->first();
            \App\Models\Transaction::create([
                'user_id' => $locked->sender_user_id,
                'transaction_id' => $releaseTxId,
                'ref_trans_id' => null,
                'transaction_type' => SEND_MONEY,
                'debit' => (string) $locked->amount,
                'credit' => '0',
                'charge' => (string) $locked->fee,
                'amount' => (string) $locked->amount,
                'balance' => (string) ($senderWallet->current_balance ?? '0'),
                'from_user_id' => $locked->sender_user_id,
                'to_user_id' => $locked->recipient_user_id,
                'note' => null,
                'decision_code' => 'TX_OK',
                'zone_code' => $senderWallet->zone_code ?? 'SOUTH',
            ]);
            \App\Models\Transaction::create([
                'user_id' => $locked->recipient_user_id,
                'transaction_id' => (string) Str::ulid(),
                'ref_trans_id' => $releaseTxId,
                'transaction_type' => RECEIVED_MONEY,
                'debit' => '0',
                'credit' => (string) $locked->amount,
                'charge' => '0',
                'amount' => (string) $locked->amount,
                'balance' => (string) $receiverWallet->current_balance,
                'from_user_id' => $locked->sender_user_id,
                'to_user_id' => $locked->recipient_user_id,
                'note' => $locked->note,
                'decision_code' => 'TX_OK',
                'zone_code' => $receiverWallet->zone_code ?? 'SOUTH',
            ]);
            if (bccomp((string) $locked->fee, '0', 4) > 0) {
                $adminId = \App\CentralLogics\Helpers::get_admin_id();
                $adminWallet = $this->guard->creditAdminCharge($adminId, (string) $locked->fee);
                \App\Models\Transaction::create([
                    'user_id' => $adminId,
                    'transaction_id' => (string) Str::ulid(),
                    'ref_trans_id' => $releaseTxId,
                    'transaction_type' => ADMIN_CHARGE,
                    'debit' => '0',
                    'credit' => (string) $locked->fee,
                    'charge' => '0',
                    'amount' => (string) $locked->fee,
                    'balance' => (string) $adminWallet->charge_earned,
                    'from_user_id' => $locked->sender_user_id,
                    'to_user_id' => $adminId,
                    'note' => null,
                    'decision_code' => 'TX_OK',
                    'zone_code' => 'SOUTH',
                ]);
            }

            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
                'release_transaction_id' => $releaseTxId,
            ]);

            // AMIAL-LEDGER-BLOCKING-003: القيد داخل المعاملة لا بعدها.
            $this->ledgerTransferWithFee(
                fromUserId: $locked->sender_user_id,
                toUserId: $locked->recipient_user_id,
                grossAmount: (string)$locked->total_debited,
                feeAmount: (string)$locked->fee,
                sourceType: 'send_money',
                sourceId: $locked->transfer_ulid,
                description: 'تحويل (بعد نافذة الإلغاء)',
            );

            return ['transfer' => $locked->fresh(), 'delivered' => true];
        });

        // post-commit: الإيصال وحده
        if ($result['delivered']) {
            $t = $result['transfer'];

            // AMIAL-FIX(RECEIPTS): إصدار إيصالَي التحويل (مدين للمرسل/دائن
            // للمستلم) — كانا مفقودين فلا يظهر التحويل في «الإيصالات» ولا PDF.
            try {
                $this->receipts->issueDualForTransfer([
                    'from_user_id' => $t->sender_user_id,
                    'to_user_id' => $t->recipient_user_id,
                    'reference_transaction_id' => $t->release_transaction_id,
                    'receipt_type' => 'send_money',
                    'amount' => (string) $t->amount,
                    'fee' => (string) $t->fee,
                    'zone_code' => 'SOUTH',
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Pending transfer receipt failed (non-fatal)', [
                    'ulid' => $t->transfer_ulid,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        return $result['transfer'];
    }

    /**
     * معالجة كل التحويلات الجاهزة للتسليم (يُستدعى من scheduled job).
     */
    public function releaseAllDue(): int
    {
        $due = PendingTransfer::releasable()->limit(200)->get();
        $count = 0;
        foreach ($due as $pending) {
            try {
                $this->release($pending);
                $count++;
            } catch (\Throwable $e) {
                \Log::error('Pending transfer release failed', [
                    'transfer' => $pending->transfer_ulid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $count;
    }

    /** رسالة عملية لا تسرّب سبب سياسة إنجليزي داخلي إلى واجهة العميل. */
    private function ledgerPolicyMessage(array $policy, string $operation): string
    {
        if (($policy['decision_code'] ?? null) === 'ACCOUNT_ZONE_UNKNOWN') {
            return 'لا يمكن ' . $operation
                . ' لأن منطقة الحساب التشغيلية غير محددة. أكمل اعتماد الهوية وحدد محافظة السكن.';
        }

        return 'لا يمكن ' . $operation . ' وفق سياسة الحساب الحالية.';
    }
}
