<?php

namespace App\Console\Commands;

use App\Services\SystemHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-OBSERVABILITY-001 — **نبضٌ يُسجَّل حتّى حين لا يسألُه أحد.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **`SystemHealthService` كانت مبنيّةً قبل هذا**، ومعها `/api/v1/amial/ping`
 * لراصدٍ خارجيّ و`/admin/health` للإدارة. **والناقصُ كان الذاكرة**: كلُّ
 * فحصٍ يُجاب به سائلٌ ثمّ يُنسى.
 *
 * فتُقرأ الحالةُ من `checkAll()` نفسِها — **مصدرٌ واحدٌ لا ثانٍ** — وتُكتب
 * كلَّ خمس دقائق. فيصير للتوفّر تاريخٌ يُقرأ: كم مرّةً سقط، ومتى، وأيُّ
 * قطعةٍ منه.
 *
 * وبلا هذا لا يُعرف إلّا الحاضر: تفتح اللوحةَ فتراه سليماً، ولا تعرف أنّه
 * سقط ثلاث مرّاتٍ ليلاً.
 */
class RecordHealthCheck extends Command
{
    protected $signature = 'amial:health-check';

    protected $description = 'يفحص قطعَ النظام ويكتب نبضَها في السجلّ';

    /** لغةُ الخدمة الأصليّة ⇒ لغةُ الجدول. */
    private const STATE = [
        'healthy' => 'up',
        'warning' => 'degraded',
        'unhealthy' => 'down',
    ];

    public function handle(SystemHealthService $health): int
    {
        $result = $health->checkAll();
        $now = now();

        foreach ($result['checks'] as $component => $c) {
            DB::table('system_health_checks')->insert([
                'component' => $component,
                'state' => self::STATE[$c['status']] ?? 'degraded',
                'latency_ms' => isset($c['latency_ms']) ? (int) round($c['latency_ms']) : null,
                'detail' => mb_substr((string) ($c['message'] ?? ''), 0, 500),
                'checked_at' => $now,
            ]);
        }

        // **يُقلَّم السجلُّ** — أربعةَ عشرَ يوماً تكفي لقراءة نمط، وأكثرُ
        // منها يُنمّي جدولاً بلا قارئ.
        DB::table('system_health_checks')
            ->where('checked_at', '<', $now->copy()->subDays(14))
            ->delete();

        // ══════════════════════════════════════════════════════════════
        // AMIAL-PROD-READINESS-001 — **القطعةُ الساقطةُ تُنذِر، لا تُكتب فقط.**
        //
        // كان هذا الأمرُ يكتب `down` في `system_health_checks` ثمّ ينتهي.
        // فقاعدةٌ تسقط الثالثةَ فجراً تُسجَّل في جدولٍ لا يقرؤه أحد، ولا
        // يُعرف الانقطاعُ إلّا حين يفتح مشرفٌ الصفحةَ صباحاً — أو حين
        // يشتكي تاجر.
        //
        // **والإنذارُ من `OpsAlertService`** ليكون في المسار نفسِه الذي
        // تسلكه المصالحة: أثرٌ في مركز الأعطال أوّلاً، ثمّ القناةُ
        // الخارجيّةُ إن وُجدت.
        //
        // **وبصمةٌ لكلّ قطعةٍ على حدة** — فقاعدةٌ ساقطةٌ وقرصٌ ممتلئٌ
        // حادثتان تُغلقان مستقلّتين، لا سطرٌ واحدٌ يُقفل فيُخفي الأخرى.
        // ══════════════════════════════════════════════════════════════
        $down = array_keys(array_filter(
            $result['checks'],
            static fn (array $c): bool => ($c['status'] ?? '') === 'unhealthy',
        ));

        foreach ($down as $component) {
            app(\App\Services\OpsAlertService::class)->raise(
                "health.down.{$component}",
                "قطعةٌ ساقطة: {$component}",
                "⛔ أميال باي — {$component} ساقطةٌ الآن ("
                . now()->format('Y-m-d H:i') . ")\n"
                . mb_substr((string) ($result['checks'][$component]['message'] ?? ''), 0, 300),
            );
        }

        $this->line("الحالة: {$result['status']}");

        if ($down !== []) {
            $this->error('ساقط: ' . implode(' · ', $down));
        }

        return $result['status'] === 'unhealthy' ? self::FAILURE : self::SUCCESS;
    }
}
