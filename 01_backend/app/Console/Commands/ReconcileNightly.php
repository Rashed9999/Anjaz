<?php

namespace App\Console\Commands;

use App\Services\OpsAlertService;
use App\Services\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-RECON-NIGHTLY-001 — تُجرى المصالحة، وتُكتب، ويُنذَر عند الفرق.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **ثلاثةُ أفعالٍ لا واحد، وترتيبُها مقصود:**
 *
 *   ١) تُجرى   — فتُقاس الأرقام
 *   ٢) تُكتب   — **قبل الإنذار**، فصفٌّ محفوظٌ يبقى ولو سقط الإرسال
 *   ٣) يُنذَر  — وسقوطُه لا يُسقط المصالحة
 *
 * ولو أُنذر قبل الكتابة لضاع الأثر عند انقطاع الشبكة: تعرف أنّ شيئاً
 * وقع ولا تجد له صفّاً.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وتُكتب حتّى حين لا فرق.**
 *
 * لأنّ صمتَ الإنذار لا يعني السلامة — قد يعني أنّ المهمّة توقّفت. وصفُّ
 * `clean` هو الفرق بين «فُحص فسلِم» و«لم يُفحص».
 */
class ReconcileNightly extends Command
{
    protected $signature = 'amial:reconcile-nightly {--quiet-alerts : يُجرى ويُكتب بلا إنذار}';

    protected $description = 'المصالحة الليليّة: المحافظ والدفتر وخزائن النقد';

    public function handle(ReconciliationService $svc): int
    {
        try {
            $r = $svc->run();
        } catch (\Throwable $e) {
            // **الفشلُ يُكتب `failed` لا يُترك فراغاً.** وليلةٌ بلا صفٍّ
            // تُقرأ لاحقاً «لم تقع»، وهذه وقعت وسقطت.
            $this->record(['status' => 'failed', 'duration_ms' => 0], $e->getMessage());
            $this->error('✗ سقطت المصالحة: ' . $e->getMessage());
            Log::error('reconcile-nightly failed', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->record($r);
        $this->report($r);

        if ($r['status'] === 'diverged' && !$this->option('quiet-alerts')) {
            $this->alert_($r);
        }

        return self::SUCCESS;
    }

    // ══════════════════════════════════════════════════════════════

    private function record(array $r, ?string $notes = null): void
    {
        try {
            DB::table('reconciliation_runs')->insert([
                'ran_at' => now(),
                'status' => $r['status'],
                'wallets_checked'    => $r['wallets']['checked'] ?? 0,
                'wallets_diverged'   => $r['wallets']['diverged'] ?? 0,
                'wallets_gap'        => $r['wallets']['gap'] ?? 0,
                'unbalanced_entries' => $r['ledger']['unbalanced'] ?? 0,
                'ledger_net'         => $r['ledger']['net'] ?? 0,
                'tills_checked'      => $r['tills']['checked'] ?? 0,
                'tills_diverged'     => $r['tills']['diverged'] ?? 0,
                'tills_gap'          => $r['tills']['gap'] ?? 0,
                'blind_spots'        => json_encode($r['blind_spots'] ?? [], JSON_UNESCAPED_UNICODE),
                'duration_ms'        => $r['duration_ms'] ?? 0,
                'notes'              => $notes,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('reconcile-nightly: تعذّر حفظ الصفّ', ['error' => $e->getMessage()]);
        }
    }

    private function report(array $r): void
    {
        $ok = $r['status'] === 'clean';

        $this->line('');
        $this->line($ok ? '✅ المصالحة الليليّة — لا فرق' : '⚠️ المصالحة الليليّة — وُجد فرق');
        $this->line(str_repeat('─', 52));

        $this->line(sprintf('  محافظ   : فُحص %d · اختلف %d · الفرق %s',
            $r['wallets']['checked'], $r['wallets']['diverged'], $r['wallets']['gap']));

        $this->line(sprintf('  الدفتر  : صافي %s · قيودٌ غير متوازنة %d',
            $r['ledger']['net'], $r['ledger']['unbalanced']));

        $this->line(sprintf('  الخزائن : فُحص %d · اختلف %d · الفرق %s',
            $r['tills']['checked'], $r['tills']['diverged'], $r['tills']['gap']));

        // **العمى يُعلَن في كلّ تقرير** — لا في التقرير المُنذِر وحده.
        // فتقريرٌ نظيفٌ بلا ذكرِ حدوده يُقرأ إحاطةً كاملة، وليس كذلك.
        if ($r['blind_spots'] !== []) {
            $this->line('');
            $this->line('  🔍 خارج التغطية (دَينٌ معلوم — لا ضياعُ مال):');
            foreach ($r['blind_spots'] as $b) {
                $this->line("     • {$b['service']} — {$b['why']}");
            }
        }

        $this->line(str_repeat('─', 52));
        $this->line("  استغرقت {$r['duration_ms']}ms");
    }

    /**
     * إنذارٌ عبر واتساب.
     *
     * **ولا يُرسَل رقمُ عميلٍ ولا مبلغُ محفظةٍ بعينها** — الإنذارُ يقول
     * «هناك فرق، افتح اللوحة»، والتفصيلُ خلف الجلسة والصلاحيّة.
     */
    private function alert_(array $r): void
    {
        $msg = "⚠️ أميال باي — المصالحة الليليّة\n"
            . "وُجد فرقٌ في " . now()->format('Y-m-d H:i') . "\n\n"
            . "• محافظ مختلفة: {$r['wallets']['diverged']} (الفرق {$r['wallets']['gap']})\n"
            . "• صافي الدفتر: {$r['ledger']['net']}\n"
            . "• خزائن مختلفة: {$r['tills']['diverged']} (الفرق {$r['tills']['gap']})\n\n"
            . "افتح لوحة الإدارة ← مركز الدفتر.";

        // ══════════════════════════════════════════════════════════════
        // AMIAL-PROD-READINESS-001 — **كان هنا فرعٌ صامت.**
        //
        // كان: إن كانت قائمةُ الأرقام فارغةً ⇒ `$this->warn(...)` و`return`.
        // وقِيس أنّها **فارغةٌ فعلاً** (`AMIAL_RECON_ALERT_TO=` في
        // `.env.example` بلا قيمة). فاختلالُ ثابتٍ ماليٍّ كان يُكتشَف
        // الساعةَ الثانية ويُطبَع في ملفٍّ ولا يوقظ أحداً.
        //
        // فصار الإنذارُ عبر `OpsAlertService`: **الأثرُ في مركز الأعطال
        // أوّلاً ودائماً**، ثمّ القناةُ الخارجيّةُ إن وُجدت. فغيابُ الرقم
        // يُضعف الإنذارَ ولا يُلغيه.
        // ══════════════════════════════════════════════════════════════
        $sent = app(OpsAlertService::class)->raise(
            'recon.nightly_diverged',
            'المصالحةُ الليليّةُ وجدت فرقاً',
            $msg,
        );

        if (! $sent) {
            $this->warn('⚠️ وُجد فرقٌ — سُجّل في مركز الأعطال، ولا قناةَ خارجيّةً مضبوطة');
            $this->warn('   اضبط AMIAL_RECON_ALERT_TO ليصل الإنذارُ ليلاً.');
        }
    }
}
