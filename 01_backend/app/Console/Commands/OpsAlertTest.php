<?php

namespace App\Console\Commands;

use App\Services\OpsAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-PROD-READINESS-001 — **قناةُ إنذارٍ لم تُجرَّب ليست قناة.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * ضبطُ `AMIAL_RECON_ALERT_TO` **لا يُثبت وصولَ الرسالة**: قد يكون الرقمُ
 * بصيغةٍ خاطئة، أو خارجَ نافذة واتساب الأربع والعشرين ساعة، أو رمزُ
 * الواجهة منتهياً. وكلُّها تسقط **صامتةً** — يُلتقَط الاستثناءُ في
 * `OpsAlertService` ويُكتب في سجلٍّ لا يقرؤه أحد.
 *
 * **وأسوأُ ما في ذلك أنّ الضبطَ نفسَه يُطمئن**: تُرى القيمةُ في اللوحة
 * فيُظنّ الإنذارُ عاملاً، والليلةَ التي يقع فيها الفرقُ لا يصل شيء.
 * وهي قاعدةُ المشروع: **حارسٌ يكذب أسوأ من غيابه.**
 *
 * فهذا الأمرُ يُجري إنذاراً حقيقيّاً عبر المسار الحقيقيّ، ثمّ يقول
 * **ما وقع فعلاً** — لا ما يُفترض أن يقع.
 *
 * ══════════════════════════════════════════════════════════════════════
 * يظهر في : لوحة الإدارة ← الامتثال والمخاطر ← 💓 صحّة النظام
 *           (تذكره لافتةُ «لا قناةَ إنذارٍ مضبوطة» بنصّه)
 * ويُوصل إليه من : سطر الأوامر على الخادم — `php artisan amial:alert-test`
 */
class OpsAlertTest extends Command
{
    protected $signature = 'amial:alert-test {--keep : يُبقي صفَّ الاختبار في مركز الأعطال}';

    protected $description = 'يُجري إنذاراً تجريبيّاً ويقول أَخرَجَ من الخادم أم لا';

    private const KEY = 'ops.alert_selftest';

    public function handle(OpsAlertService $alerts): int
    {
        $configured = OpsAlertService::hasExternalChannel();

        $this->line('');
        $this->line('  فحصُ قناة الإنذار');
        $this->line(str_repeat('─', 52));
        $this->line('  الأرقامُ المضبوطة : ' . ($configured
            ? count((array) config('amial.reconciliation.alert_numbers'))
            : '٠ — AMIAL_RECON_ALERT_TO فارغ'));

        $sent = $alerts->raise(
            self::KEY,
            'فحصُ قناة الإنذار',
            "🔔 أميال باي — رسالةُ فحص\n"
            . 'أُرسلت في ' . now()->format('Y-m-d H:i') . "\n\n"
            . 'إن وصلتك هذه، فقناةُ الإنذار تعمل — وستصلك فروقُ المصالحة '
            . 'الليليّة وسقوطُ القطع على الرقم نفسِه.',
        );

        // **الأثرُ يُقاس لا يُفترَض** — فالخدمةُ تبتلع أخطاءَ نفسها عمداً،
        // وسطرٌ لم يُكتب يعني أنّ جدولَ الأعطال نفسَه معطوب.
        $traced = DB::table('system_errors')
            ->where('fingerprint', hash('sha256', 'ops|' . self::KEY))
            ->exists();

        $this->line('  الأثرُ في مركز الأعطال : ' . ($traced ? '✓ كُتب' : '✗ لم يُكتب'));
        $this->line('  خرجت من الخادم        : ' . ($sent ? '✓ نعم' : '✗ لا'));
        $this->line(str_repeat('─', 52));

        if (! $this->option('keep')) {
            DB::table('system_errors')
                ->where('fingerprint', hash('sha256', 'ops|' . self::KEY))
                ->delete();
        }

        if (! $traced) {
            $this->error('✗ حتّى الأثرُ الداخليُّ لم يُكتب — راجِع جدول system_errors.');

            return self::FAILURE;
        }

        if (! $sent) {
            $this->warn('⚠ الأثرُ محفوظٌ ولا إشعارَ يخرج.');
            $this->warn('  اضبط AMIAL_RECON_ALERT_TO=<رقم واتساب> ثمّ أعِد هذا الأمر.');
            $this->warn('  وحتّى ذلك، لا يُعرف فرقُ المصالحة إلّا بفتح اللوحة.');

            return self::FAILURE;
        }

        $this->info('✓ أُرسلت. **افتح الهاتفَ وتأكّد** — الإرسالُ بلا خطأٍ ليس وصولاً.');

        return self::SUCCESS;
    }
}
