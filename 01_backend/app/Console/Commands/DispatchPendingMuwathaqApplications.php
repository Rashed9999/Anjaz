<?php

namespace App\Console\Commands;

use App\Services\Muwathaq\MuwathaqBridgeService;
use Illuminate\Console\Command;

class DispatchPendingMuwathaqApplications extends Command
{
    protected $signature = 'muwathaq:dispatch-pending {--limit=100 : أقصى عدد طلبات في الجولة}';
    protected $description = 'يعيد إرسال طلبات فتح الحساب المعلقة إلى بوابة موثّق';

    public function handle(MuwathaqBridgeService $bridge): int
    {
        if (!$bridge->isConfigured()) {
            $this->warn('بوابة موثّق أو سر الإرسال غير مضبوطين؛ لم يُرسل شيء.');
            return self::SUCCESS;
        }

        $count = $bridge->dispatchPending((int) $this->option('limit'));
        $this->info("تمت جدولة {$count} طلب/طلبات إلى موثّق.");

        return self::SUCCESS;
    }
}
