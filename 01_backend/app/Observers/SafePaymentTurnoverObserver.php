<?php

namespace App\Observers;

use App\Models\SafePayment;
use App\Services\CustomerTurnoverService;

/**
 * الدفع الآمن يحجز حد المشتري من لحظة حجز المال. الرفض/الإلغاء/الاسترداد
 * يحرره، والإفراج يثبته ويسجل أصل البيع للمستلم إن كان عميلاً فردياً.
 */
class SafePaymentTurnoverObserver
{
    private function buyerKey(SafePayment $payment): string
    {
        return 'safe-payment:buyer:' . (string) $payment->payment_ulid;
    }

    private function sellerKey(SafePayment $payment): string
    {
        return 'safe-payment:seller:' . (string) $payment->payment_ulid;
    }

    public function created(SafePayment $payment): void
    {
        app(CustomerTurnoverService::class)->reserve(
            user: (int) $payment->buyer_user_id,
            amount: (string) $payment->amount,
            direction: 'out',
            sourceKey: $this->buyerKey($payment),
            transactionType: 'safe_payment',
            occurredAt: $payment->created_at ?? now(),
        );
    }

    public function updated(SafePayment $payment): void
    {
        if (!$payment->wasChanged('status')) return;

        $turnover = app(CustomerTurnoverService::class);

        if (in_array($payment->status, [
            'seller_rejected', 'refunded_to_buyer', 'cancelled', 'expired',
        ], true)) {
            $turnover->release($this->buyerKey($payment), 'safe payment cancelled/refunded');
            return;
        }

        if ($payment->status === 'released_to_seller') {
            $turnover->finalizeWithAmount($this->buyerKey($payment), (string) $payment->amount);
            $turnover->recordPosted(
                user: (int) $payment->seller_user_id,
                amount: (string) $payment->amount,
                direction: 'in',
                sourceKey: $this->sellerKey($payment),
                transactionType: 'safe_payment',
                occurredAt: $payment->released_at ?? now(),
            );
            return;
        }

        if ($payment->status === 'partially_refunded') {
            $refunded = (string) ($payment->refunded_to_buyer_amount ?? '0');
            $settled = bcsub((string) $payment->amount, $refunded, 4);
            if (bccomp($settled, '0', 4) < 0) $settled = '0';

            $turnover->finalizeWithAmount($this->buyerKey($payment), $settled);
            if (bccomp($settled, '0', 4) > 0) {
                $turnover->recordPosted(
                    user: (int) $payment->seller_user_id,
                    amount: $settled,
                    direction: 'in',
                    sourceKey: $this->sellerKey($payment),
                    transactionType: 'safe_payment_partial',
                    occurredAt: $payment->admin_resolved_at ?? now(),
                );
            }
        }
    }
}
