<?php

namespace App\Services;

use App\Models\SplitBill;
use App\Models\SplitBillParticipant;
use App\Models\User;
use App\Traits\TransactionTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * AMIAL-SPLIT-BILL-001 — تقسيم الفاتورة (الوثيقة §15).
 *
 * التاجر/POS ينشئ فاتورة بمبلغ + مشاركين مسجّلين، تُقسَّم تلقائياً (فرق التقريب
 * عبر MoneyService::distribute)، كل مشارك يدفع حصته عبر مسار دفع التاجر،
 * والرسم من المحرّك. لا تكرار رقم، لا تعديل بعد أول دفع.
 */
class SplitBillService
{
    use TransactionTrait;

    /**
     * إنشاء فاتورة مقسّمة.
     *
     * @param array<int,string> $participantPhones أرقام عملاء مسجّلين (v1)
     */
    public function create(
        User $merchant,
        string $totalAmount,
        array $participantPhones,
        string $channel = 'qr',
        ?int $posUserId = null,
        ?string $note = null,
    ): SplitBill {
        $total = MoneyService::normalize($totalAmount);
        if (!MoneyService::isPositive($total)) {
            throw new InvalidArgumentException('Total amount must be positive');
        }

        // تنظيف الأرقام ومنع التكرار
        $phones = array_values(array_unique(array_map('trim', $participantPhones)));
        if (count($phones) !== count($participantPhones)) {
            throw new InvalidArgumentException('لا يمكن تكرار رقم داخل نفس الفاتورة');
        }
        $count = count($phones);
        if ($count < 2) {
            throw new InvalidArgumentException('تقسيم الفاتورة يحتاج مشاركَين على الأقل');
        }

        // حل العملاء المسجّلين (v1: مسجّلون فقط)
        $users = [];
        foreach ($phones as $phone) {
            $u = User::whereIn('phone', \App\Support\Phone::variants((string) $phone))->first();
            if (!$u) {
                throw new InvalidArgumentException("الرقم غير مسجّل: {$phone}");
            }
            if ($u->id === $merchant->id) {
                throw new InvalidArgumentException('لا يمكن أن يكون التاجر مشاركاً في فاتورته');
            }
            $users[] = $u;
        }

        // تقسيم بدقة مع امتصاص فرق التقريب
        $shares = MoneyService::distribute($total, $count);

        return DB::transaction(function () use ($merchant, $total, $count, $channel, $posUserId, $note, $users, $shares, $phones) {
            $bill = SplitBill::create([
                'split_ulid' => (string) Str::ulid(),
                'merchant_user_id' => $merchant->id,
                'pos_user_id' => $posUserId,
                'total_amount' => $total,
                'participant_count' => $count,
                'channel' => $channel === 'pos' ? 'pos' : 'qr',
                'status' => 'open',
                'zone_code' => $merchant->zone_code ?? 'SOUTH',
                'note' => $note,
            ]);

            foreach ($users as $i => $u) {
                SplitBillParticipant::create([
                    'split_bill_id' => $bill->id,
                    'customer_user_id' => $u->id,
                    'customer_phone' => $phones[$i],
                    'share_amount' => $shares[$i],
                    'status' => 'pending',
                ]);
            }

            return $bill->load('participants');
        });
    }

    /**
     * دفع حصة مشارك. يُعيد ['transaction_id', 'participant', 'bill'].
     */
    public function payShare(int $participantId, User $payingUser, ?string $idempotencyKey = null): array
    {
        return DB::transaction(function () use ($participantId, $payingUser, $idempotencyKey) {
            /** @var SplitBillParticipant $participant */
            $participant = SplitBillParticipant::lockForUpdate()->findOrFail($participantId);
            /** @var SplitBill $bill */
            $bill = SplitBill::lockForUpdate()->findOrFail($participant->split_bill_id);

            if ($bill->status === 'cancelled') {
                throw new RuntimeException('الفاتورة ملغاة');
            }
            if ($participant->status === 'paid') {
                throw new RuntimeException('تم دفع هذه الحصة مسبقاً');
            }
            if ($participant->customer_user_id !== $payingUser->id) {
                throw new RuntimeException('هذه الحصة ليست لك');
            }

            // الدفع عبر مسار دفع التاجر (الرسم من المحرّك، snapshot، إيصال، ledger)
            $txId = $this->merchant_payment_transaction(
                customer_user_id: $payingUser->id,
                merchant_user_id: $bill->merchant_user_id,
                amount: (string) $participant->share_amount,
                channel: $bill->channel,
                pos_user_id: $bill->pos_user_id,
                note: 'تقسيم فاتورة',
                idempotencyKey: $idempotencyKey,
                split_bill_id: $bill->id,
                split_participant_id: $participant->id,
            );

            $participant->update([
                'status' => 'paid',
                'paid_transaction_id' => $txId,
                'paid_at' => now(),
            ]);

            // تحديث حالة الفاتورة
            $remaining = $bill->participants()->where('status', 'pending')->count();
            $bill->update(['status' => $remaining === 0 ? 'completed' : 'partially_paid']);

            return [
                'transaction_id' => $txId,
                'participant' => $participant->fresh(),
                'bill' => $bill->fresh()->load('participants'),
            ];
        });
    }
}
