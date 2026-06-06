<?php

namespace App\Jobs;

use App\Services\PendingTransferService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * AMIAL-TRANSFER-COOLDOWN-001 (v2.7)
 *
 * ReleasePendingTransfersJob — يُسلّم التحويلات التي انتهت نافذة إلغائها.
 *
 * يُجدوَل كل دقيقة (راجع routes/console.php أو bootstrap/app.php):
 *   $schedule->job(new ReleasePendingTransfersJob)->everyMinute();
 *
 * ملاحظة: النافذة الافتراضية 60 ثانية، لذا التأخير الأقصى للتسليم ~دقيقتين.
 * لتسليم أسرع، يمكن جدولته كل 30 ثانية أو استخدام delayed dispatch لكل تحويل.
 */
class ReleasePendingTransfersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PendingTransferService $service): void
    {
        $released = $service->releaseAllDue();
        if ($released > 0) {
            \Log::info("ReleasePendingTransfersJob: released {$released} transfers");
        }
    }
}
