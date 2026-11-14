<?php

namespace App\Jobs;

use App\Services\Muwathaq\WomenTierThreeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverWomenTier3SyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [10, 60, 300, 900, 1800];

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('muwathaq');
    }

    public function handle(WomenTierThreeService $service): void
    {
        $service->deliver($this->eventId);
    }
}
