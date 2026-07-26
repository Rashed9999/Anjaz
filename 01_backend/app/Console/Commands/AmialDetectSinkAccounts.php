<?php

namespace App\Console\Commands;

use App\Models\Aml\AmlAlert;
use App\Models\Transaction;
use App\Models\User;
use App\Support\YemenGovernorates;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * AMIAL-COVERAGE-001 — كشف «الحسابات المصرف المغلق».
 *
 * **النمط المرصود:**
 * حساب يستقبل باستمرار ولا يسحب ولا يدفع لتاجر أبداً. غالباً صاحبه انتقل
 * إلى منطقة بلا وكلاء ولا تجار، فصار رصيده محبوساً داخل الشبكة.
 *
 * **لماذا يُرصد ولا يُحجَب:**
 * الحالة نفسها مشروعة تماماً — لا مخالفة في أن يستقبل المرء مالاً ولا
 * يسحبه. لكنها تخلق حافزاً قوياً للتسوية خارج التطبيق: يستقبل هنا ويقبض
 * ورقاً هناك بسعر السوق، وهذه حوالة موازية لا نراها ولا نستطيع منعها
 * تقنياً — لكن نستطيع رؤية أثرها.
 *
 * فالمخرج تنبيه للمراجعة لا قيد على الحساب. الحجب هنا يعاقب سلوكاً
 * مشروعاً ويدفع الناس إلى قنوات أخفى.
 *
 * يُشغَّل يومياً: php artisan amial:detect-sink-accounts
 */
class AmialDetectSinkAccounts extends Command
{
    protected $signature = 'amial:detect-sink-accounts
                            {--days=30 : نافذة الرصد بالأيام}
                            {--min-received=50000 : أدنى مبلغ مستلَم يستحقّ النظر}
                            {--dry-run : اعرض بلا إنشاء تنبيهات}';

    protected $description = 'يرصد الحسابات التي تستقبل باستمرار ولا تسحب ولا تدفع';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $minReceived = (float) $this->option('min-received');
        $dryRun = (bool) $this->option('dry-run');
        $since = now()->subDays($days);

        // العمليات الداخلة (credit) مقابل الخارجة نقداً في النافذة نفسها.
        $incoming = Transaction::where('created_at', '>=', $since)
            ->where('credit', '>', 0)
            ->selectRaw('user_id, SUM(credit) AS total, COUNT(*) AS cnt')
            ->groupBy('user_id')
            ->havingRaw('SUM(credit) >= ?', [$minReceived])
            ->get();

        $cashOutTypes = ['cash_out', 'withdraw', 'pay_merchant', 'pos_payment', 'qr_payment', 'bill_payment'];
        $found = 0;

        foreach ($incoming as $row) {
            $hasCashExit = Transaction::where('user_id', $row->user_id)
                ->where('created_at', '>=', $since)
                ->whereIn('transaction_type', $cashOutTypes)
                ->exists();

            if ($hasCashExit) {
                continue;
            }

            $user = User::find($row->user_id);
            if (!$user || (int) $user->type !== CUSTOMER_TYPE) {
                continue;
            }

            $found++;
            $governorate = YemenGovernorates::name($user->residence_governorate) ?? 'غير محدّدة';
            $this->line(sprintf(
                '  #%d %s — استلم %s عبر %d عملية، بلا سحب أو دفع — %s',
                $user->id, $user->phone, number_format((float) $row->total), $row->cnt, $governorate
            ));

            if ($dryRun) {
                continue;
            }

            // تنبيه واحد لكل حساب في النافذة — لا نغرق المراجع بالتكرار.
            $exists = AmlAlert::where('alert_code', 'SINK_ACCOUNT')
                ->where('subject_type', 'user')
                ->where('subject_id', $user->id)
                ->where('status', 'open')
                ->exists();

            if ($exists) {
                continue;
            }

            AmlAlert::create([
                'alert_ulid' => (string) Str::ulid(),
                'alert_code' => 'SINK_ACCOUNT',
                'severity' => 'medium',
                'subject_type' => 'user',
                'subject_id' => $user->id,
                'title_ar' => 'حساب يستقبل ولا يُخرج',
                'message_ar' => sprintf(
                    'استلم %s ر.ي عبر %d عملية خلال %d يوماً بلا سحب نقدي أو دفع لتاجر. '
                    . 'المحافظة المسجّلة: %s. قد يكون انتقل إلى منطقة بلا تغطية — '
                    . 'يُراجَع احتمال تسوية خارج التطبيق.',
                    number_format((float) $row->total), $row->cnt, $days, $governorate
                ),
                'context' => [
                    'window_days' => $days,
                    'total_received' => (string) $row->total,
                    'incoming_count' => (int) $row->cnt,
                    'residence_governorate' => $user->residence_governorate,
                    'zone_code' => $user->zone_code,
                ],
                'status' => 'open',
            ]);
        }

        $this->info($found === 0
            ? 'لا حسابات مطابقة للنمط.'
            : "رُصد {$found} حساباً" . ($dryRun ? ' (تجربة — بلا تنبيهات)' : '.'));

        return self::SUCCESS;
    }
}
