<?php

namespace App\Jobs;

use App\Models\BillPaymentOrder;
use App\Models\BillProviderWebhookEvent;
use App\Services\BillPayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Re-checks one order after a verified provider callback. */
class ReconcileBillPaymentOrderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 90;

    public function __construct(
        public readonly int $orderId,
        public readonly ?int $webhookEventId = null,
    ) {}

    public function handle(BillPayService $service): void
    {
        $order = BillPaymentOrder::find($this->orderId);
        if (!$order) {
            return;
        }

        try {
            $service->reconcilePendingOrder($order);

            if ($this->webhookEventId) {
                $event = BillProviderWebhookEvent::find($this->webhookEventId);
                if ($event) {
                    $event->update([
                        'processed_at' => now(),
                        'processing_result' => $order->fresh()->isSuccessful()
                            ? BillProviderWebhookEvent::RESULT_PROCESSED
                            : BillProviderWebhookEvent::RESULT_QUEUED,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            Log::error('Single bill reconciliation failed', [
                'order_id' => $this->orderId,
                'webhook_event_id' => $this->webhookEventId,
                'exception' => get_class($e),
            ]);
            if ($this->webhookEventId) {
                BillProviderWebhookEvent::whereKey($this->webhookEventId)->update([
                    'processing_result' => BillProviderWebhookEvent::RESULT_FAILED,
                    'processing_error' => mb_substr($e->getMessage(), 0, 500),
                ]);
            }
        }
    }
}
