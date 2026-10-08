<?php

namespace App\Console\Commands;

use App\Models\EMoney;
use App\Models\Ledger\LedgerJournalEntry;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-DEMO-MONEY-001 — **سحبُ المال الذي سُكّ للعرض، بقيدٍ عكسيّ لا بمسحِ عمود.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * `DemoAccountPolicy::seededWalletBalance()` يوقف السَّكَّ من الآن. **وما
 * سُكَّ قبله باقٍ على الخادم** — ستّةُ تجّارٍ × ١٠٠٬٠٠٠، ووكيلٌ ٥٠٠٬٠٠٠،
 * وعميلان ٦٠٬٠٠٠. وهذا الأمرُ يسحبه.
 *
 * **وأربعةُ حدودٍ تحكمه، وكلٌّ منها ثمنُ خطأٍ ممكن:**
 *
 *   ① **قيدٌ عكسيٌّ لا `UPDATE` على `current_balance`.** مسحُ العمود
 *      يُنتج انحرافاً بين المحفظة والدفتر — أي **العطلَ الذي تشكو منه
 *      المصالحةُ كلَّ ليلة** مكانَ العطل الأصليّ. والتصحيحُ المحاسبيُّ
 *      قيدٌ مضادّ (`amial-double-entry`)، والأثرُ يبقى مقروءاً.
 *
 *   ② **والنطاقُ يُسأل من الدفتر لا من قائمةٍ مكتوبة.** كلُّ محفظةٍ لها
 *      قيدُ `opening_balance` **هي بالتعريف** محفظةٌ وُلدت مموَّلة —
 *      والتاجرُ الحقيقيُّ يولد بصفر (`FinancialGuardService`) فلا قيدَ
 *      له. فقائمةُ هواتفَ مكتوبةٌ تشيخ، والدفترُ لا يشيخ.
 *
 *   ③ **ولا يُسحب ما لم يبقَ.** إن نزل الرصيدُ تحت المبلغ المسكوك فقد
 *      أُنفق بعضُه في التجربة، وسحبُ الكاملِ يُنزل محفظةً تحت الصفر —
 *      **ومالُ تاجرٍ ربحه من بيعةٍ حقيقيّة يذهب معه**. فتُتخطّى ويُقال
 *      لماذا. (القاعدة السابعة: الغيابُ يُقال ولا يُقرأ صفراً.)
 *
 *   ④ **ولا يجري بالصمت.** بلا `--confirm` يُعرَض ما سيقع ولا يقع.
 *      وهو **لا يُستدعى من `entrypoint.sh`**: أمرٌ يُحرّك مالاً في كلّ
 *      إقلاعٍ هو العطلُ نفسُه بثوبٍ معكوس.
 *
 * يظهر في : سجلُّ الأمر وحدَه — **ولا شاشةَ له عمداً**. وأثرُه يُقرأ في
 * لوحة الإدارة ← مركز الدفتر (قيدٌ `opening_balance_reversal`) وفي
 * «مطابقة المحافظ» (الفرقُ يبقى صفراً قبله وبعده).
 *
 * @see \App\Support\DemoAccountPolicy
 * @see \Tests\Feature\DemoMoneyGuardTest
 */
class RevokeDemoMoney extends Command
{
    protected $signature = 'amial:revoke-demo-money
        {--confirm : نفِّذ فعلاً — بدونها عرضٌ فقط}
        {--user=* : اقتصر على معرّفات مستخدمين بعينهم}';

    protected $description = 'سحبُ الأرصدة الافتتاحيّة التي سُكّت لحسابات العرض، بقيدٍ عكسيّ';

    public function handle(LedgerService $ledger): int
    {
        $q = LedgerJournalEntry::where('source_type', 'opening_balance')
            ->where('status', 'posted')
            ->where('is_reversal', false)
            ->orderBy('id');

        if ($ids = array_filter(array_map('intval', (array) $this->option('user')))) {
            $q->whereIn('source_id', $ids);
        }

        $entries = $q->get();

        if ($entries->isEmpty()) {
            $this->info('لا أرصدة افتتاحيّة مسكوكة — لا شيء يُسحَب.');

            return self::SUCCESS;
        }

        $dry = ! $this->option('confirm');
        $done = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            $userId = (int) $entry->source_id;
            $amount = (string) $entry->total_amount;
            $user = User::find($userId);
            $name = $user ? trim(($user->f_name ?? '').' '.($user->l_name ?? '')) : '؟';
            $balance = (string) (EMoney::where('user_id', $userId)->value('current_balance') ?? '0');

            // ③ لا يُسحب ما لم يبقَ
            if (bccomp($balance, $amount, 4) < 0) {
                $this->warn(sprintf(
                    '⏭  #%d %s — الرصيدُ %s دون المسكوك %s: أُنفق بعضُه، فلا يُسحَب (يُسوّى يدوياً بقضيّة مصالحة).',
                    $userId, $name, $balance, $amount));
                $skipped++;

                continue;
            }

            if ($dry) {
                $this->line(sprintf('· #%d %s — سيُسحَب %s (الرصيدُ %s ← %s)',
                    $userId, $name, $amount, $balance, bcsub($balance, $amount, 4)));
                $done++;

                continue;
            }

            // ① القيدُ العكسيُّ والمحفظةُ في معاملةٍ واحدة — فلا تتحرّك
            // واحدةٌ دون أختها، وهو عينُ ما يُنتج الانحراف.
            DB::transaction(function () use ($ledger, $entry, $userId, $amount) {
                $wallet = EMoney::where('user_id', $userId)->lockForUpdate()->first();

                if (! $wallet) {
                    throw new \RuntimeException("محفظةُ المستخدم {$userId} غير موجودة");
                }

                if (bccomp((string) $wallet->current_balance, $amount, 4) < 0) {
                    throw new \RuntimeException("رصيدُ {$userId} نزل تحت المسكوك بين الفحص والقفل");
                }

                $ledger->reverse($entry->id, 'سحبُ رصيدٍ افتتاحيٍّ سُكّ لحساب عرض (AMIAL-DEMO-MONEY-001)');

                $wallet->current_balance = bcsub((string) $wallet->current_balance, $amount, 4);
                $wallet->version = (int) $wallet->version + 1;
                $wallet->save();
            });

            $this->info(sprintf('✓ #%d %s — سُحب %s', $userId, $name, $amount));
            $done++;
        }

        $this->newLine();
        $this->line($dry
            ? "عرضٌ فقط: {$done} محفظةً ستُسحَب، {$skipped} تُتخطّى. أعِد الأمرَ بـ--confirm للتنفيذ."
            : "تمّ: {$done} محفظةً سُحبت، {$skipped} تُخطّيت.");

        return self::SUCCESS;
    }
}
