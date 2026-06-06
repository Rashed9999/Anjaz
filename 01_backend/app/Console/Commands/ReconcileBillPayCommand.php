<?php

namespace App\Console\Commands;

use App\Jobs\ReconcilePendingBillOrdersJob;
use App\Services\BillPayService;
use Illuminate\Console\Command;

/**
 * AMIAL-BILL-PAY-001 (v0.9-C complete)
 *
 * `php artisan amial:bill-pay:reconcile` — تشغيل manual.
 *
 * مفيد لـ:
 *   - تشخيص orders pending يدوياً
 *   - تشغيل بعد downtime للمزود
 *   - integration tests
 */
class ReconcileBillPayCommand extends Command
{
    protected $signature = 'amial:bill-pay:reconcile {--sync : تشغيل المزامن بدل dispatch لـ queue}';

    protected $description = 'فحص الـ bill orders pending_provider_confirmation وتسويتها';

    public function handle(BillPayService $service): int
    {
        if ($this->option('sync')) {
            $this->info('Running synchronously...');
            (new ReconcilePendingBillOrdersJob())->handle($service);
            $this->info('Done.');
            return self::SUCCESS;
        }

        ReconcilePendingBillOrdersJob::dispatch();
        $this->info('Job dispatched to queue.');
        return self::SUCCESS;
    }
}
