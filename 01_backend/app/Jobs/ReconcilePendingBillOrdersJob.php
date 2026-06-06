<?php

namespace App\Jobs;

use App\Models\BillPaymentOrder;
use App\Services\BillPayService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-BILL-PAY-001 (v0.9-C complete)
 *
 * ReconcilePendingBillOrdersJob — يفحص الـ orders pending_provider_confirmation
 * ويستدعي المزود للتحقق من حالتها الحقيقية.
 *
 * **التشغيل المُوصى به:** كل دقيقة عبر scheduler.
 * في `routes/console.php`:
 *
 *   Schedule::job(new ReconcilePendingBillOrdersJob)->everyMinute()
 *           ->withoutOverlapping();
 *
 * **الحدود:**
 *   - يفحص آخر 50 order pending
 *   - فقط للـ orders > 30 ثانية (إعطاء فرصة للـ initial response)
 *   - فقط للـ orders < 24 ساعة (الأقدم: تُعلَّم timeout يدوياً من admin)
 */
class ReconcilePendingBillOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // لا retry للـ job نفسه (الـ scheduler يعيد تشغيله)
    public int $timeout = 300; // 5 minutes max

    public function handle(BillPayService $service): void
    {
        $pendingOrders = BillPaymentOrder::where('status', 'pending_provider_confirmation')
            ->where('created_at', '<', now()->subSeconds(30))
            ->where('created_at', '>', now()->subDay())
            ->orderBy('id')
            ->limit(50)
            ->get();

        if ($pendingOrders->isEmpty()) {
            return;
        }

        Log::info("ReconcilePendingBillOrders: processing {$pendingOrders->count()} orders");

        $resolved = 0;
        $failed = 0;
        $stillPending = 0;

        foreach ($pendingOrders as $order) {
            try {
                $before = $order->status;
                $service->reconcilePendingOrder($order);
                $order->refresh();

                if ($order->status === 'success') $resolved++;
                elseif ($order->status === 'failed') $failed++;
                else $stillPending++;
            } catch (\Throwable $e) {
                Log::error("Reconcile error for order #{$order->id}", [
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        Log::info('ReconcilePendingBillOrders summary', [
            'resolved' => $resolved,
            'failed' => $failed,
            'still_pending' => $stillPending,
            'total_checked' => $pendingOrders->count(),
        ]);
    }
}
