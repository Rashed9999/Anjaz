<?php

namespace App\Console\Commands;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\MerchantFixedAssetService;
use Illuminate\Console\Command;

class PostMerchantAssetDepreciation extends Command
{
    protected $signature = 'amial:post-asset-depreciation {--through= : YYYY-MM; default previous month}';
    protected $description = 'إثبات إهلاك الأصول الثابتة للتجار بصورة idempotent';

    public function handle(MerchantFixedAssetService $assets): int
    {
        $through = $this->option('through')
            ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $this->option('through').'-01')
            : now()->subMonthNoOverflow()->endOfMonth();

        $count = 0;
        MerchantProfile::query()->orderBy('user_id')->chunkById(200, function ($profiles) use ($assets, $through, &$count) {
            foreach ($profiles as $profile) {
                $merchant = User::find($profile->user_id);
                if (! $merchant) continue;
                $result = $assets->postDepreciationThrough($merchant, $through);
                $count += (int) $result['entries_posted'];
            }
        }, 'user_id');

        $this->info("Posted {$count} asset depreciation entries through ".$through->format('Y-m'));

        return self::SUCCESS;
    }
}
