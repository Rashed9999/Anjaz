<?php

namespace App\Console\Commands;

use App\Services\Access\EntitlementService;
use App\Support\Access\CapabilityRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AMIAL-ENTITLEMENTS-009 — **مَن يتأثّر لو أُغلق الوضعُ الصامت؟**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا أمرٌ مستقلّ:**
 *
 * الوضعُ الصامتُ شرطُ خروجه «إثباتُ المتأثّرين». **وشرطٌ لا أداةَ لقياسه
 * لا يتحقّق أبداً** — فيبقى «مؤقّتاً» سنةً، وهو في الحقيقة تسعيرٌ لا
 * يُحصَّل.
 *
 * فهذا الأمرُ يقرأ ما سجّلته البوّابةُ فعلاً في مركز الأعطال، ويقول:
 * كم مرّةً مرّ طلبٌ كان سيُمنَع، ومتى آخرُها.
 *
 *   php artisan amial:shadow-report
 *   php artisan amial:shadow-report --json
 */
class ShadowModeReport extends Command
{
    protected $signature = 'amial:shadow-report {--json}';

    protected $description = 'المتأثّرون بالوضع الصامت — قدرةً قدرة';

    public function handle(EntitlementService $entitlements): int
    {
        $shadowed = (array) config('amial.entitlements.shadow', []);

        $rows = [];

        foreach (CapabilityRegistry::all() as $cap) {
            $a = $cap->toArray();
            $code = $a['code'];

            if (! in_array($code, $shadowed, true)) {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'name' => $a['name'] ?? $code,
                'min_plan' => $a['min_plan'] ?? null,
            ] + $this->tracesFor($code);
        }

        // **وأثرُ جلسات الأجهزة غيرُ المربوطة يُقرأ معها** — فهو وضعٌ
        // صامتٌ آخرُ بمفتاحٍ آخر، وفصلُ التقريرين يجعل أحدَهما يُنسى.
        $device = $this->tracesFor('pos_device_unbound_session', exact: true);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'shadowed' => $rows,
                'pos_device_unbound' => $device,
                'pos_device_enforced' => (bool) config('amial.pos_devices.enforce_session_binding'),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('  ══ الوضعُ الصامت ══');
        $this->newLine();

        if ($rows === []) {
            $this->line('  <fg=green>✓ لا قدرةَ في الوضع الصامت — كلُّ البوّابات تُنفِّذ.</>');
        } else {
            $this->line(sprintf('  %-26s %-14s %-8s %s', 'القدرة', 'أدنى باقة', 'مرّات', 'آخر مرور'));

            foreach ($rows as $r) {
                $this->line(sprintf('  %-26s %-14s %-8s %s',
                    $r['code'], $r['min_plan'] ?? '—', $r['hits'], $r['last'] ?? '—'));
            }

            $this->newLine();
            $this->line('  <fg=yellow>كلُّ مرورٍ أعلاه طلبٌ كان سيُمنَع لو أُغلق الصمت.</>');
            $this->line('  <fg=yellow>وصفرُ مرورٍ سبعةَ أيّامٍ ⇒ الإغلاقُ بلا متأثّرين.</>');
        }

        $this->newLine();
        $this->line('  ══ مقاعدُ الأجهزة ══');
        $this->newLine();
        $this->line(sprintf('  الإنفاذ            : %s',
            config('amial.pos_devices.enforce_session_binding') ? 'مُفعَّل' : '<fg=yellow>صامت</>'));
        $this->line(sprintf('  جلساتٌ بلا مقعد    : %s   (آخرها %s)',
            $device['hits'], $device['last'] ?? '—'));

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * **يُقرأ من `system_errors`** — حيث تكتب `OpsAlertService::note`.
     *
     * وغيابُ الجدول يُقال صراحةً ولا يُقرأ صفراً (القاعدة السابعة:
     * «غير معروف» ليس صفراً).
     *
     * @return array{hits:int|string,last:?string}
     */
    private function tracesFor(string $code, bool $exact = false): array
    {
        if (! Schema::hasTable('system_errors')) {
            return ['hits' => 'غيرُ معروف', 'last' => null];
        }

        // **المفتاحُ يُخزَّن في `exception`** — كما تكتبه
        // `OpsAlertService::note`. و`EntitlementService` يبصمه:
        //
        //     entitlement.shadow.<code>.<state>
        //
        // **ويُجمَع `occurrences` لا تُعدُّ الصفوف**: الأثرُ مضغوطٌ
        // ببصمةٍ واحدة، فعدُّ الصفوف يقول «مرّةً واحدة» عن ألف مرور.
        $needle = $exact ? $code : 'entitlement.shadow.'.$code.'.';

        $row = DB::table('system_errors')
            ->where('exception', 'like', $needle.'%')
            ->selectRaw('COALESCE(SUM(occurrences),0) AS c, MAX(last_seen_at) AS m')
            ->first();

        return [
            'hits' => (int) ($row->c ?? 0),
            'last' => $row->m ?? null,
        ];
    }
}
