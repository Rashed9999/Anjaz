<?php

namespace App\Jobs;

use App\Services\Muwathaq\MuwathaqTierUpgradeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverMuwathaqTierUpgradeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [10, 60, 300, 900, 1800];

    public function __construct(public readonly int $requestId)
    {
        $this->onQueue('muwathaq');
    }

    public function handle(MuwathaqTierUpgradeService $service): void
    {
        $service->deliver($this->requestId);
    }
}
