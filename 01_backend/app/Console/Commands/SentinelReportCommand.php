<?php

namespace App\Console\Commands;

use App\Models\SentinelEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-SENTINEL-001 — تقرير الحارس المخفي.
 *
 * يعرض ملخّص نشاط مشبوه خلال فترة (أعلى IPs، أكثر التوقيعات، الأحداث الحرجة).
 *
 * أمثلة:
 *   php artisan amial:sentinel-report
 *   php artisan amial:sentinel-report --hours=72 --top=20
 */
class SentinelReportCommand extends Command
{
    protected $signature = 'amial:sentinel-report {--hours=24} {--top=10}';

    protected $description = 'عرض ملخّص أحداث الحارس المخفي (النشاط المشبوه)';

    public function handle(): int
    {
        $hours = (int) $this->option('hours');
        $top = (int) $this->option('top');
        $since = now()->subHours($hours);

        $base = SentinelEvent::where('created_at', '>=', $since);

        $total = (clone $base)->count();
        $critical = (clone $base)->where('severity', 'critical')->count();

        $this->info("تقرير الحارس — آخر {$hours} ساعة");
        $this->line("إجمالي الأحداث: {$total}  |  حرجة: {$critical}");

        if ($total === 0) {
            $this->line('لا نشاط مشبوه. ✅');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('أعلى عناوين IP:');
        $ips = (clone $base)
            ->select('ip_address', DB::raw('COUNT(*) as hits'), DB::raw('MAX(threat_score) as max_score'))
            ->groupBy('ip_address')
            ->orderByDesc('hits')
            ->limit($top)
            ->get();
        $this->table(['IP', 'Hits', 'Max score'], $ips->map(fn ($r) => [
            $r->ip_address ?? '-', $r->hits, $r->max_score,
        ])->all());

        $this->newLine();
        $this->line('أكثر المسارات المستهدَفة:');
        $paths = (clone $base)
            ->select('path', DB::raw('COUNT(*) as hits'))
            ->groupBy('path')
            ->orderByDesc('hits')
            ->limit($top)
            ->get();
        $this->table(['Path', 'Hits'], $paths->map(fn ($r) => [$r->path ?? '-', $r->hits])->all());

        return self::SUCCESS;
    }
}
