<?php

namespace App\Observers;

use App\Models\PendingTransfer;
use App\Services\CustomerTurnoverService;

/**
 * التحويل المؤجل يحجز أصل المبلغ منذ لحظة طلبه، لا عند انتهاء نافذة
 * الإلغاء فقط. الإلغاء أو فشل التسليم يحرر الحجز، والتسليم يثبته ويضيف
 * حركة واردة مستقلة للمستلم الفردي.
 */
class PendingTransferTurnoverObserver
{
    private function senderKey(PendingTransfer $transfer): string
    {
        return 'pending-transfer:sender:' . (string) $transfer->transfer_ulid;
    }

    private function recipientKey(PendingTransfer $transfer): string
    {
        return 'pending-transfer:recipient:' . (string) $transfer->transfer_ulid;
    }

    public function created(PendingTransfer $transfer): void
    {
        if ($transfer->status !== 'holding') {
            return;
        }

        app(CustomerTurnoverService::class)->reserve(
            user: (int) $transfer->sender_user_id,
            amount: (string) $transfer->amount,
            direction: 'out',
            sourceKey: $this->senderKey($transfer),
            transactionType: 'pending_transfer',
            occurredAt: $transfer->created_at ?? now(),
        );
    }

    public function updated(PendingTransfer $transfer): void
    {
        if (!$transfer->wasChanged('status')) {
            return;
        }

        $turnover = app(CustomerTurnoverService::class);
        if ($transfer->status === 'completed') {
            $turnover->finalize($this->senderKey($transfer));
            $turnover->recordPosted(
                user: (int) $transfer->recipient_user_id,
                amount: (string) $transfer->amount,
                direction: 'in',
                sourceKey: $this->recipientKey($transfer),
                transactionId: (string) ($transfer->release_transaction_id ?? ''),
                transactionType: 'send_money',
                occurredAt: $transfer->completed_at ?? now(),
            );
            return;
        }

        if (in_array($transfer->status, ['cancelled', 'failed'], true)) {
            $turnover->release($this->senderKey($transfer), 'pending transfer cancelled/failed');
        }
    }
}
