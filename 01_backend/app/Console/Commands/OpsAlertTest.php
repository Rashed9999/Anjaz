<?php

namespace App\Console\Commands;

use App\Services\OpsAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-PROD-READINESS-001/005 — قناة إنذار لم تُجرّب ليست قناة.
 *
 * هذا الأمر يرفع إنذاراً حقيقياً عبر المسار الحقيقي، ثم يقول ما وقع
 * فعلاً. والرسالة نفسها تستخدم القالب التفصيلي: الخطورة، المكوّن، الوقت،
 * المرجع، التفاصيل والإجراء المقترح، حتى يثبت الاختبار شكل الإنذار أيضاً.
 */
class OpsAlertTest extends Command
{
    protected $signature = 'amial:alert-test {--keep : يُبقي صفَّ الاختبار في مركز الأعطال}';

    protected $description = 'يُجري إنذاراً تجريبيّاً ويقول أَخرَجَ من الخادم أم لا';

    private const KEY = 'ops.alert_selftest';

    public function handle(OpsAlertService $alerts): int
    {
        $configured = OpsAlertService::hasExternalChannel();
        $whatsappCount = count(array_filter((array) config('amial.reconciliation.alert_numbers', [])));
        $emailCount = count(array_filter((array) config('amial.reconciliation.alert_emails', [])));

        $this->line('');
        $this->line('  فحص قناة الإنذار');
        $this->line(str_repeat('─', 52));
        $this->line('  بريد الإنذار          : ' . ($emailCount > 0 ? $emailCount . ' مضبوط' : '٠'));
        $this->line('  أرقام واتساب           : ' . ($whatsappCount > 0 ? $whatsappCount . ' مضبوط' : '٠'));

        $sent = $alerts->raise(
            self::KEY,
            'فحص قناة الإنذار',
            "🔔 أميال باي — رسالة فحص\n"
            . 'أُرسلت في ' . now()->format('Y-m-d H:i') . "\n\n"
            . 'إذا وصلتك هذه الرسالة فقناة الإنذار الخارجية تعمل فعلياً. '
            . 'الإنذارات الحقيقية ستتضمن المكوّن المتأثر، مستوى الخطورة، '
            . 'مرجع الحادثة، التفاصيل التشغيلية الآمنة والإجراء المقترح.',
        );

        // الأثر يُقاس لا يُفترض.
        $traced = DB::table('system_errors')
            ->where('fingerprint', hash('sha256', 'ops|' . self::KEY))
            ->exists();

        $this->line('  الأثر في مركز الأعطال : ' . ($traced ? '✓ كُتب' : '✗ لم يُكتب'));
        $this->line('  خرجت من الخادم        : ' . ($sent ? '✓ نعم' : '✗ لا'));
        $this->line(str_repeat('─', 52));

        if (! $this->option('keep')) {
            DB::table('system_errors')
                ->where('fingerprint', hash('sha256', 'ops|' . self::KEY))
                ->delete();
        }

        if (! $traced) {
            $this->error('✗ حتى الأثر الداخلي لم يُكتب — راجع جدول system_errors.');
            return self::FAILURE;
        }

        if (! $configured) {
            $this->warn('⚠ لا توجد قناة خارجية مضبوطة.');
            $this->warn('  اضبط AMIAL_ALERT_EMAIL للبريد عبر Resend أو AMIAL_RECON_ALERT_TO لواتساب.');
            return self::FAILURE;
        }

        if (! $sent) {
            $this->warn('⚠ الأثر محفوظ لكن كل القنوات الخارجية فشلت في الإرسال.');
            $this->warn('  راجع Resend/مزود واتساب وسجل Laravel ثم أعد الاختبار.');
            return self::FAILURE;
        }

        $this->info('✓ خرج الإنذار من الخادم. افتح البريد/الهاتف وتأكد من وصوله ومحتواه.');

        return self::SUCCESS;
    }
}
