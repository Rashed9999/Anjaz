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
        $cooldown = $cooldownSeconds ?? self::DEFAULT_COOLDOWN_SECONDS;

        // 1) فحوصات أساسية
        if ($sender->id === $recipient->id) {
            throw new RuntimeException('لا يمكنك التحويل لنفسك');
        }
        if (bccomp($amount, '0', 4) <= 0) {
            throw new RuntimeException('المبلغ يجب أن يكون موجباً');
        }

        // 2) فحص السياسة المالية (zone + sanction + KYC)
        $this->enforceFinancialPolicy($sender, 'send_money', $amount);
        $this->enforceZone($recipient);

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

            return $pending;
        });
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
            if (!$recipient || ($recipient->zone_code ?? 'UNKNOWN') !== 'SOUTH'
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
            $this->guard->credit($locked->recipient_user_id, (string)$locked->amount,
                "pending_transfer_release:{$locked->transfer_ulid}");

            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
                'release_transaction_id' => $releaseTxId,
            ]);

            return ['transfer' => $locked->fresh(), 'delivered' => true];
        });

        // post-commit: ledger + receipt
        if ($result['delivered']) {
            $t = $result['transfer'];
            $this->safeLedgerPost(fn() => $this->ledgerTransferWithFee(
                fromUserId: $t->sender_user_id,
                toUserId: $t->recipient_user_id,
                grossAmount: (string)$t->total_debited,
                feeAmount: (string)$t->fee,
                sourceType: 'send_money',
                sourceId: $t->transfer_ulid,
                description: 'تحويل (بعد نافذة الإلغاء)',
            ));
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
}
