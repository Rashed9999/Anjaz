<?php

namespace App\Console\Commands;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Vertical\VerticalBootstrapService;
use Illuminate\Console\Command;

/**
 * AMIAL-VERTICAL-BOOTSTRAP-001 — **شفاءُ الحسابات القائمة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * إصلاحُ المنبع يحمي ما يُنشأ بعده — **ولا يشفي ما أُنشئ قبله**. وكلُّ
 * حسابِ محطّةٍ أو صيدليّةٍ أو جملةٍ أُنشئ من اللوحة قبل هذا الإصلاح ما
 * زال بلا سجلّ قطاعه، وصاحبُه يقرأ «لا توجد محطة مرتبطة بهذا الحساب».
 *
 * ويُشغَّل في `entrypoint.prod.sh` بعد الهجرات — آمنُ التكرار: يمرّ على
 * ما هو سليمٌ بلا أن يمسّه.
 *
 * ولا يُنشئ شيئاً لمن لا يحتاج: التجزئةُ والبيعُ السريع والمطعم تعمل
 * على جداولَ عامّة.
 */
class AmialHealVerticals extends Command
{
    protected $signature = 'amial:heal-verticals {--dry-run : يعرض ولا يكتب}';

    protected $description = 'ينشئ سجلّ القطاع لكلّ تاجرٍ نشاطُه يحتاجه ولا سجلَّ له';

    public function handle(VerticalBootstrapService $bootstrap): int
    {
        $dry = (bool) $this->option('dry-run');

        // ══════════════════════════════════════════════════════════════
        //  AMIAL-MERCHANT-USABLE-001 — **كلُّ تاجرٍ لا ثلاثةُ أنشطةٍ فقط.**
        //
        //  كان المسحُ مقصوراً على الوقود والصيدليّة والجملة — وهي وحدَها
        //  تحتاج **سجلَّ قطاع**. ثمّ صارت التهيئةُ تزرع **أدوارَ
        //  الموظّفين** أيضاً، وتلك يحتاجها كلُّ تاجر.
        //
        //  فتاجرُ التجزئة — وهو الأكثر — كان يبقى بلا أدوارٍ يُسندها،
        //  ولا شيءَ في النشرة يُصلحه. **إصلاحٌ يمرّ فوق من يحتاجه.**
        $profiles = MerchantProfile::whereNotNull('business_type')
            ->where('business_type', '!=', '')
            ->get(['user_id', 'business_type']);

        if ($profiles->isEmpty()) {
            $this->info('لا تجّار بأنشطةٍ محدَّدة.');

            return self::SUCCESS;
        }

        $healed = 0;
        $already = 0;
        $skipped = 0;

        foreach ($profiles as $p) {
            $merchant = User::find($p->user_id);

            if (! $merchant) {
                // **حسابٌ محذوفٌ وملفُّه باقٍ** — يُقال ولا يُسكت عنه.
                $this->warn("  ملفُّ تاجرٍ بلا حساب: user_id={$p->user_id}");
                $skipped++;
                continue;
            }

            if ($dry) {
                $this->line("  [تجربة] {$p->business_type} ← user_id={$p->user_id}");
                continue;
            }

            $out = $bootstrap->ensureFor($merchant);

            if ($out === null) {
                $skipped++;
                continue;
            }

            if ($out['created']) {
                $healed++;
                $this->info("  ✓ بُني {$out['vertical']} للحساب {$merchant->id}");
            } else {
                $already++;
            }
        }

        // **والأرقامُ تُقال كلُّها** — «شُفي ٠» مع «سليمٌ ١٢» جوابٌ مختلفٌ
        // عن «شُفي ٠» وحده. (القاعدة السابعة.)
        $this->newLine();
        $this->info("شُفي: {$healed} · سليمٌ أصلاً: {$already} · مُخطّى: {$skipped}");

        return self::SUCCESS;
    }
}
