<?php

namespace App\Observers;

use App\Models\BillPaymentOrder;
use App\Services\CustomerTurnoverService;

/** سداد الخدمات يحجز الحد عند الطلب، ويحرره عند الفشل. */
class BillPaymentTurnoverObserver
{
    private function key(BillPaymentOrder $order): string
    {
        return 'bill-pay:' . (string) $order->order_ulid;
    }

    public function created(BillPaymentOrder $order): void
    {
        app(CustomerTurnoverService::class)->reserve(
            user: (int) $order->user_id,
            amount: (string) $order->amount,
            direction: 'out',
            sourceKey: $this->key($order),
            transactionType: 'bill_pay',
            occurredAt: $order->created_at ?? now(),
        );
    }

    public function updated(BillPaymentOrder $order): void
    {
        if (!$order->wasChanged('status')) return;

        $turnover = app(CustomerTurnoverService::class);
        if ($order->status === 'success') {
            $turnover->finalize($this->key($order));
        } elseif ($order->status === 'failed') {
            $turnover->release($this->key($order), 'bill payment failed/refunded');
        }
    }
}
