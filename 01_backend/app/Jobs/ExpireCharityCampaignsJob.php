<?php

namespace App\Jobs;

use App\Models\CharityCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** ينهي الحملات المنتهية كي لا تبقى «نشطة» في التقارير أو التطبيق. */
class ExpireCharityCampaignsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        CharityCampaign::query()
            ->where('status', 'active')
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<=', now())
            ->update(['status' => 'completed']);
    }
}
