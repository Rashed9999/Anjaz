<?php

namespace App\Saher\Console;

use App\Saher\Collectors\DataTruthCollector;
use App\Saher\Findings\FindingStore;
use Illuminate\Console\Command;

/**
 * SAHER-DATA-003 — `php artisan saher:scan-data`
 *
 * **وجولةٌ لم تقرأ دالّةً واحدةً تسقط** — لا تُقرأ «لا اكتشافات».
 * فمجلَّدُ `Services` قد يكون غيرَ موجودٍ أو غيرَ مقروء، والصفرُ حينها
 * يعني «لم أنظر» لا «نظرتُ فلم أجد». (القاعدة السابعة.)
 */
class ScanDataTruthCommand extends Command
{
    protected $signature = 'saher:scan-data {--trigger=manual}';

    protected $description = 'ساهر — صدقُ البيانات: قيمٌ افتراضيّةٌ بلا مخرج، ودوالُّ خدماتٍ بلا مُنادٍ';

    public function handle(FindingStore $store, DataTruthCollector $collector): int
    {
        $source = DataTruthCollector::SOURCE;
        $runId = $store->beginRun($source, (string) $this->option('trigger'));

        try {
            $result = $collector->collect();
        } catch (\Throwable $e) {
            $store->failRun($runId, $source, $e->getMessage());
            $this->error('سقط جامعُ صدق البيانات: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($result['assets_seen'] === 0) {
            $store->failRun($runId, $source,
                'لم يُقرأ أصلٌ واحد — لا خدماتٍ ولا أعمدةَ ذاتَ قيمٍ افتراضيّة');

            $this->error('جولةٌ عمياء: صفرُ أصول. لم تُسجَّل نتيجة.');

            return self::FAILURE;
        }

        $c = $store->commitRun($runId, $source, $result['findings'], $result['assets_seen']);

        $this->info(sprintf(
            'ساهر · صدقُ البيانات — فُحص %d أصلاً · جديد %d · عاد %d · قائم %d · أُغلق %d',
            $result['assets_seen'], $c['opened'], $c['reopened'], $c['updated'], $c['resolved'],
        ));

        return self::SUCCESS;
    }
}
