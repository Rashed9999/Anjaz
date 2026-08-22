<?php

namespace App\Saher\Console;

use App\Saher\Collectors\GateCoverageCollector;
use App\Saher\Findings\FindingStore;
use Illuminate\Console\Command;

/**
 * SAHER-GATE-005 — `php artisan saher:scan-gate`
 *
 * **وجولةٌ لم تقرأ التزاماً واحداً تسقط** — لا تُقرأ «لا اكتشافات».
 * (وهو الفرقُ الذي أرسل هذا المشروعَ ثلاثَ مرّاتٍ خلف عطلٍ لا وجودَ له،
 * ومرّةً أعلن تعافياً على قاعدةٍ غيرِ موجودة.)
 */
class ScanGateCommand extends Command
{
    protected $signature = 'saher:scan-gate {--trigger=manual}';

    protected $description = 'ساهر — تغطية البوّابة: التزاماتٌ لا إيصالَ فحصٍ لمحتواها';

    public function handle(FindingStore $store, GateCoverageCollector $collector): int
    {
        $source = GateCoverageCollector::SOURCE;
        $runId = $store->beginRun($source, (string) $this->option('trigger'));

        try {
            $result = $collector->collect();
        } catch (\Throwable $e) {
            $store->failRun($runId, $source, $e->getMessage());
            $this->error('سقط جامعُ البوّابة: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($result['assets_seen'] === 0) {
            $store->failRun($runId, $source,
                'لم يُقرأ التزامٌ واحد — لا مستودعَ أو تعذّرت قراءتُه');

            $this->error('جولةٌ عمياء: صفرُ التزامات. لم تُسجَّل نتيجة.');

            return self::FAILURE;
        }

        $c = $store->commitRun($runId, $source, $result['findings'], $result['assets_seen']);

        $this->info(sprintf(
            'ساهر · البوّابة — فُحص %d التزاماً · جديد %d · عاد %d · قائم %d · أُغلق %d',
            $result['assets_seen'], $c['opened'], $c['reopened'], $c['updated'], $c['resolved'],
        ));

        return self::SUCCESS;
    }
}
