<?php

namespace App\Jobs;

use App\Services\Muwathaq\MuwathaqBridgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** يسلم طلباً واحداً إلى بوابة موثّق؛ مفتاح الحدث يجعل الإعادة آمنة. */
class DeliverMuwathaqApplicationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [10, 60, 300, 900, 1800];

    public function __construct(public readonly int $submissionId)
    {
        $this->onQueue('muwathaq');
    }

    public function handle(MuwathaqBridgeService $bridge): void
    {
        $bridge->deliver($this->submissionId);
    }
}
