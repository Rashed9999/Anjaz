<?php

namespace App\Jobs;

use App\Services\MerchantRiskService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * AMIAL-MERCHANT-RISK-001 (v2.10)
 *
 * AnalyzeMerchantRiskJob — تحليل مخاطر التاجر في الخلفية.
 *
 * **المبدأ الحاسم (من وثيقة رصد):**
 *   "فحص القواعد المعقّدة يجب أن يتم في الخلفية لضمان سرعة رد النظام."
 *
 * الفحص الأساسي (assertReceiveAllowed) يبقى متزامناً (سريع).
 * التحليل التراكمي المعقّد (analyzeReceived) يُؤجَّل هنا.
 */
class AnalyzeMerchantRiskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $merchantUserId,
        public readonly string $amount,
        public readonly int $fromCustomerId,
    ) {}

    public function handle(MerchantRiskService $service): void
    {
        try {
            $service->analyzeReceived($this->merchantUserId, $this->amount, $this->fromCustomerId);
        } catch (\Throwable $e) {
            \Log::error('Merchant risk analysis failed', [
                'merchant' => $this->merchantUserId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
