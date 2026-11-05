<?php

namespace App\Services;

use App\Models\MerchantPayoutRequest;
use App\Models\MerchantProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** دورة مستحقات التاجر: طلب ← اعتماد مع تعليمات ← تسليم نقد ← تأكيد استلام. */
class MerchantPayoutService
{
    public function __construct(
        private readonly FinancialGuardService $guard,
        private readonly LedgerService $ledger,
        private readonly AuditService $audit,
        private readonly CashHandoverService $handovers,
    ) {}

    public function request(User $merchant, string $amount, ?string $note = null): MerchantPayoutRequest
    {
        $this->assertMerchantOwner($merchant);
        $amount = MoneyService::normalize($amount);
        if (! MoneyService::isPositive($amount)) {
            throw new RuntimeException('مبلغ السحب يجب أن يكون موجباً');
        }

        return DB::transaction(function () use ($merchant, $amount, $note) {
            // الحجز أولاً: طلبٌ معتمد بلا غطاء لا يجب أن يُنشأ.
            $this->guard->hold($merchant->id, $amount, 'merchant_payout_hold');

            $payout = MerchantPayoutRequest::create([
                'payout_ulid' => (string) Str::ulid(),
                'merchant_user_id' => $merchant->id,
                'amount' => $amount,
                'status' => MerchantPayoutRequest::STATUS_PENDING,
                'request_note' => $note ? mb_substr(trim($note), 0, 500) : null,
            ]);

            $this->audit->record([
                'actor_type' => 'merchant', 'actor_user_id' => $merchant->id,
                'subject_type' => 'merchant_payout_request', 'subject_id' => $payout->payout_ulid,
                'action' => 'MERCHANT_PAYOUT_REQUESTED', 'decision_code' => 'PENDING',
                'severity' => 'info', 'context' => ['amount' => $amount],
            ]);

            return $payout;
        });
    }

    public function approve(MerchantPayoutRequest $payout, User $admin, string $instructions): MerchantPayoutRequest
    {
        if (mb_strlen(trim($instructions)) < 5) {
            throw new RuntimeException('تعليمات الاستلام مطلوبة بعد اعتماد الطلب');
        }

        return DB::transaction(function () use ($payout, $admin, $instructions) {
            $payout = MerchantPayoutRequest::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($payout->status !== MerchantPayoutRequest::STATUS_PENDING) {
                throw new RuntimeException('الطلب ليس بانتظار الاعتماد');
            }
            $payout->update([
                'status' => MerchantPayoutRequest::STATUS_APPROVED,
                'approved_by_id' => $admin->id, 'approved_at' => now(),
                'collection_instructions' => mb_substr(trim($instructions), 0, 1000),
            ]);
            $this->audit->record([
                'actor_type' => 'admin', 'actor_user_id' => $admin->id,
                'subject_type' => 'merchant_payout_request', 'subject_id' => $payout->payout_ulid,
                'action' => 'MERCHANT_PAYOUT_APPROVED', 'decision_code' => 'APPROVED',
                'severity' => 'info', 'context' => ['amount' => (string) $payout->amount],
            ]);
            return $payout->fresh();
        });
    }

    public function reject(MerchantPayoutRequest $payout, User $admin, string $reason): MerchantPayoutRequest
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw new RuntimeException('سبب الرفض مطلوب');
        }

        return DB::transaction(function () use ($payout, $admin, $reason) {
            $payout = MerchantPayoutRequest::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($payout->status !== MerchantPayoutRequest::STATUS_PENDING) {
                throw new RuntimeException('الطلب ليس بانتظار الاعتماد');
            }
            $this->guard->releaseHoldUpTo($payout->merchant_user_id, (string) $payout->amount,
                "merchant_payout_rejected:{$payout->payout_ulid}");
            $payout->update([
                'status' => MerchantPayoutRequest::STATUS_REJECTED,
                'rejected_by_id' => $admin->id, 'rejected_at' => now(),
                'rejection_reason' => mb_substr(trim($reason), 0, 500),
            ]);
            $this->audit->record([
                'actor_type' => 'admin', 'actor_user_id' => $admin->id,
                'subject_type' => 'merchant_payout_request', 'subject_id' => $payout->payout_ulid,
                'action' => 'MERCHANT_PAYOUT_REJECTED', 'decision_code' => 'REJECTED',
                'severity' => 'warning', 'reason' => $reason,
            ]);
            return $payout->fresh();
        });
    }

    public function markPaid(MerchantPayoutRequest $payout, User $admin, ?string $location = null): MerchantPayoutRequest
    {
        return DB::transaction(function () use ($payout, $admin, $location) {
            $payout = MerchantPayoutRequest::whereKey($payout->id)->lockForUpdate()->firstOrFail();
            if ($payout->status !== MerchantPayoutRequest::STATUS_APPROVED) {
                throw new RuntimeException('لا يُصرف إلا طلب معتمد');
            }

            $amount = (string) $payout->amount;
            $this->guard->captureHold($payout->merchant_user_id, $amount,
                "merchant_payout:{$payout->payout_ulid}");
            $merchantWallet = $this->ledger->getOrCreateUserWallet($payout->merchant_user_id);
            $reserve = $this->ledger->getOrCreateSystemAccount('CASH_RESERVE', 'asset', 'احتياطي النقد', 'debit');
            $this->ledger->post(
                sourceType: 'merchant_payout', sourceId: $payout->payout_ulid,
                description: 'صرف مستحقات التاجر نقداً',
                lines: [
                    ['account' => $merchantWallet->account_code, 'direction' => 'debit', 'amount' => $amount],
                    ['account' => $reserve->account_code, 'direction' => 'credit', 'amount' => $amount],
                ],
                idempotencyKey: "merchant_payout_{$payout->payout_ulid}",
                createdByUserId: $admin->id,
            );

            $handover = $this->handovers->open(
                direction: 'platform_to_merchant', amount: $amount, from: null,
                to: User::findOrFail($payout->merchant_user_id), deliveredBy: $admin,
                meta: ['settlement_ulid' => $payout->payout_ulid, 'location' => $location,
                    'reference' => $payout->payout_ulid, 'note' => $payout->collection_instructions],
            );
            $payout->update([
                'status' => MerchantPayoutRequest::STATUS_PAID,
                'paid_by_id' => $admin->id, 'paid_at' => now(),
                'handover_ulid' => $handover['handover_ulid'],
            ]);
            $this->audit->record([
                'actor_type' => 'admin', 'actor_user_id' => $admin->id,
                'subject_type' => 'merchant_payout_request', 'subject_id' => $payout->payout_ulid,
                'action' => 'MERCHANT_PAYOUT_PAID', 'decision_code' => 'PAID',
                'severity' => 'info', 'context' => ['amount' => $amount, 'handover_ulid' => $handover['handover_ulid']],
            ]);
            return $payout->fresh();
        });
    }

    public function confirmHandover(MerchantPayoutRequest $payout, User $merchant, ?string $note = null): array
    {
        if ((int) $payout->merchant_user_id !== (int) $merchant->id || ! $payout->handover_ulid) {
            throw new RuntimeException('لا تملك تأكيد هذا التسليم');
        }
        return $this->handovers->confirm($payout->handover_ulid, $merchant, $note);
    }

    private function assertMerchantOwner(User $merchant): void
    {
        if (! MerchantProfile::where('user_id', $merchant->id)->exists()) {
            throw new RuntimeException('سحب المستحقات متاح لمالك المنشأة فقط');
        }
    }
}
