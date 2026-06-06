<?php

namespace App\Console\Commands;

use App\Models\EMoney;
use App\Models\PlatformFeeEntry;
use App\Services\MoneyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-SCALE-FEES-001 — تسوية رسوم المنصّة إلى رصيد الأدمن.
 *
 * يجمع القيود غير المسوّاة لكل أدمن، ويضيفها إلى charge_earned بقفل واحد فقط،
 * ثم يعلّمها مسوّاة. يُجدوَل كل دقيقة. هذا ينقل التنافس من «كل عملية» إلى «دورة تسوية».
 */
class ReconcilePlatformFeesCommand extends Command
{
    protected $signature = 'amial:reconcile-fees {--limit=5000}';
    protected $description = 'تسوية قيود رسوم المنصّة إلى رصيد الأدمن (AMIAL-SCALE-FEES-001)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        // اجمع حسب الأدمن
        $totals = PlatformFeeEntry::where('reconciled', false)
            ->limit($limit)
            ->get()
            ->groupBy('admin_user_id');

        if ($totals->isEmpty()) {
            $this->info('لا توجد رسوم للتسوية.');
            return self::SUCCESS;
        }

        foreach ($totals as $adminId => $entries) {
            $sum = '0';
            $ids = [];
            foreach ($entries as $e) {
                $sum = MoneyService::add($sum, (string) $e->amount);
                $ids[] = $e->id;
            }

            DB::transaction(function () use ($adminId, $sum, $ids) {
                /** @var EMoney $wallet */
                $wallet = EMoney::where('user_id', $adminId)->lockForUpdate()->first();
                if (!$wallet) {
                    $wallet = EMoney::create([
                        'user_id' => $adminId, 'current_balance' => '0.0000',
                        'charge_earned' => '0.0000', 'pending_balance' => '0.0000',
                        'held_balance' => '0.0000', 'zone_code' => 'SOUTH', 'version' => 0,
                    ]);
                    $wallet = EMoney::where('user_id', $adminId)->lockForUpdate()->first();
                }
                $wallet->charge_earned = MoneyService::add((string) $wallet->charge_earned, $sum);
                $wallet->version = $wallet->version + 1;
                $wallet->save();

                PlatformFeeEntry::whereIn('id', $ids)
                    ->update(['reconciled' => true, 'reconciled_at' => now()]);
            });

            $this->info("أدمن {$adminId}: سُوّي " . MoneyService::display($sum) . " (" . count($ids) . " قيد).");
        }

        return self::SUCCESS;
    }
}
