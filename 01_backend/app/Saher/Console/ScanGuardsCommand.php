<?php

namespace App\Saher\Console;

use App\Saher\Collectors\GuardCoverageCollector;
use App\Saher\Findings\FindingStore;
use Illuminate\Console\Command;

/**
 * SAHER-FOUNDATION-006 — `php artisan saher:scan-guards`
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والأمرُ يخرج بالرمز الصحيح.**
 *
 * سقوطُ جامعٍ يخرج بـ`1` لا بـ`0`. وهذا درسٌ دُفع ثمنُه في `run_dr.sh`:
 * كان يُخرج `VERDICT: PASS ✓` على قاعدةٍ غيرِ موجودة **ويخرج بالرمز صفر
 * مهما وقع** — فقرأه المجدوِلُ نجاحاً ثمانيةَ أيّام.
 *
 * **ووجودُ اكتشافاتٍ ليس سقوطاً.** ساهرٌ راصدٌ لا بوّابة: أمرٌ يسقط لأنّه
 * وجد عطلاً يُوقف كلَّ مجدوِلٍ يستدعيه، فيُطفَأ، فيعمى الرصدُ كلُّه.
 * والسقوطُ للجامع نفسِه وحدَه.
 */
class ScanGuardsCommand extends Command
{
    protected $signature = 'saher:scan-guards
                            {--trigger=manual : manual · scheduled · deploy}';

    protected $description = 'ساهر — جرد الحرّاس: مسارات الكتابة بلا صلاحيّة أو خلف صلاحيّة قراءة';

    public function handle(FindingStore $store, GuardCoverageCollector $collector): int
    {
        $source = GuardCoverageCollector::SOURCE;
        $runId = $store->beginRun($source, (string) $this->option('trigger'));

        try {
            $result = $collector->collect();
        } catch (\Throwable $e) {
            $store->failRun($runId, $source, $e->getMessage());

            $this->error('سقط جامعُ الحرّاس: ' . $e->getMessage());
            $this->line('  والاكتشافاتُ السابقةُ تبقى مفتوحة — '
                . 'جامعٌ عطبَ لا يُثبت أنّ العطلَ زال.');

            return self::FAILURE;
        }

        // **مُطابِقٌ عمي يخرج أخضرَ على صفر.** فلا يُقبل «لا اكتشافات» من
        // جولةٍ لم ترَ مساراً واحداً — تلك جولةٌ عمياء لا لوحةٌ نظيفة.
        if ($result['assets_seen'] === 0) {
            $store->failRun($runId, $source,
                'لم يُقرأ مسارُ كتابةٍ واحدٌ في اللوحة — المرشِّحُ لا يرى الموجِّه');

            $this->error('جولةٌ عمياء: صفرُ مسارات. لم تُسجَّل نتيجة.');

            return self::FAILURE;
        }

        $counts = $store->commitRun($runId, $source, $result['findings'], $result['assets_seen']);

        $this->info(sprintf(
            'ساهر · الحرّاس — فُحص %d مسارَ كتابة · جديد %d · عاد %d · قائم %d · أُغلق %d',
            $result['assets_seen'], $counts['opened'], $counts['reopened'],
            $counts['updated'], $counts['resolved'],
        ));

        return self::SUCCESS;
    }
}
