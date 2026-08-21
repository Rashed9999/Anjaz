<?php

namespace App\Jobs;

use App\Models\SafePayment;
use App\Services\SafePaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * يعيد للمشتري الدفعات التي لم يقبلها البائع ضمن المهلة.
 *
 * كل عنصر يعاد قفله داخل الخدمة؛ لذلك يبقى آمناً إن وصل طلب قبول البائع
 * في اللحظة نفسها. الحدّ يمنع دورة واحدة متأخرة من احتكار العامل.
 */
class ExpireSafePaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;

    public function handle(SafePaymentService $service): void
    {
        $payments = SafePayment::expiredPendingAcceptance()
            ->orderBy('id')
            ->limit(100)
            ->get();

        $expired = 0;
        foreach ($payments as $payment) {
            try {
                $before = $payment->status;
                $service->expireUnresponsive($payment);
                if ($before === 'pending_seller_acceptance'
                    && $payment->fresh()?->status === 'expired') {
                    $expired++;
                }
            } catch (\Throwable $e) {
                Log::error('Safe payment expiry failed', [
                    'payment_ulid' => $payment->payment_ulid,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        if ($expired > 0) {
            Log::info("ExpireSafePaymentsJob: refunded {$expired} expired safe payments");
        }
    }
}
