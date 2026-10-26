<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Merchant\MerchantWorkspaceOutbox;
use Illuminate\Console\Command;

final class SyncMerchantWorkspace extends Command
{
    protected $signature = 'merchant:workspace-sync {--limit=100 : Maximum delivery batch}';
    protected $description = 'Record approved merchant access snapshots and deliver the ordered commercial outbox';

    public function handle(MerchantWorkspaceOutbox $outbox): int
    {
        User::where('type', MERCHANT_TYPE)->chunkById(100, function ($owners) use ($outbox): void {
            foreach ($owners as $owner) $outbox->recordFor($owner);
        });
        $count = $outbox->deliverPending((int) $this->option('limit'));
        $this->info('Delivered merchant workspace events: '.$count);
        if ($outbox->failedDeliveries() > 0) {
            $this->error('Merchant workspace delivery failures: '.$outbox->failedDeliveries());
            return self::FAILURE;
        }
        return self::SUCCESS;
    }
}
