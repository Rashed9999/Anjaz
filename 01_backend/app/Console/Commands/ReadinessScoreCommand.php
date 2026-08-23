<?php

namespace App\Console\Commands;

use App\Services\OpsAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * AMIAL-READINESS-METER-001 — **الجاهزيّةُ رقمٌ يُحسَب لا حكمٌ يُصدَر.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا وُجد هذا الأمر:**
 *
 * سُئل مراراً «هل نحن جاهزون لألفي مستخدم؟»، وكان الجوابُ في كلّ مرّةٍ
 * فقرةً أكتبها أنا. **وحكمٌ يعتمد على من يكتبه لا يُراجَع ولا يُتابَع**:
 * لا يعرف صاحبُ المشروع متى تغيّر، ولا يعرف أنّه صار عشرةً إلّا بأن
 * يسأل من جديد.
 *
 * فصار مقياساً: كلُّ شرطٍ **يُقاس من مصدره**، والرقمُ حاصلُ القياس.
 * ويصير عشرةً حين يصير كذلك — لا حين يقتنع أحد.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وما لا يُقاس من هنا يُقال «غيرُ معروف» ولا يُحسَب نجاحاً.**
 *
 * ثلاثةٌ لا تُعرف إلّا من الخادم المنشور: أنّ النشرةَ جرت، وأنّ فحصَ
 * الصحّة مضبوطٌ في Coolify، وسقفُ php-fpm الحقيقيّ. فتُعرَض بعلامة `؟`
 * ولا تُعَدّ في البسط — **والصفرُ يُقرأ «فحصنا فلم نجد»، والغيابُ يُقال
 * بسببه.** (القاعدةُ السابعة.)
 *
 * ومع `--base=https://…` يُسأل الخادمُ نفسُه فتُقاس الأولى والثانية.
 *
 * ══════════════════════════════════════════════════════════════════════
 * يظهر في : الطرفيّة — `php artisan amial:readiness`. وفي لوحة الإدارة
 * والتطبيق: لا. **وهو أمرٌ لصاحب المشروع يقرؤه قبل أن يفتح التسجيل.**
 */
class ReadinessScoreCommand extends Command
{
    protected $signature = 'amial:readiness {--base= : عنوانُ الخادم المنشور، فتُقاس شروطُه أيضاً}';

    protected $description = 'جاهزيّةُ التجربة لألفي مستخدم — رقمٌ محسوبٌ من المصادر لا حكمٌ مكتوب';

    /** الحدُّ المقدَّر: ٢–٥٪ من ألفين نشِطون ⇒ ٤٠–١٠٠ متزامناً ⇒ ~٥٠ طلباً/ث. */
    private const MIN_RPS = 50;

    /** دونَه لا تُخدَم ذروةُ ألفي مستخدم — والافتراضُ خمسة. */
    private const MIN_FPM_CHILDREN = 16;

    public function handle(): int
    {
        $base = rtrim((string) $this->option('base'), '/');

        $checks = array_merge(
            $this->codeChecks(),
            $this->configChecks(),
            $this->deployChecks($base),
        );

        $this->render($checks);

        $counted = array_filter($checks, fn ($c) => $c['state'] !== 'unknown');
        $passed = array_filter($counted, fn ($c) => $c['state'] === 'pass');

        $score = $counted === []
            ? 0
            : (int) round(10 * count($passed) / count($counted));

        $unknown = count($checks) - count($counted);

        $this->newLine();
        $this->line(sprintf('  <options=bold>الجاهزيّة: %d / 10</>  (%d من %d شرطاً مقيساً)',
            $score, count($passed), count($counted)));

        if ($unknown > 0) {
            $this->line(sprintf(
                '  <fg=yellow>و%d شرطاً «غيرُ معروف» — لا يُقاس من هنا، ولا يُحسَب نجاحاً.</>',
                $unknown));
            $this->line('  <fg=gray>شغّله بـ--base=https://amialpay.com ليُسأل الخادمُ نفسُه.</>');
        }

        if ($score < 10 || $unknown > 0) {
            $this->newLine();
            $this->line('  الخطوات: <options=bold>bash scripts/wizard-production-blockers.sh</>');
        }

        return $score === 10 && $unknown === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ══════════════════════════════════════════════════════════════════

    /** @return list<array{name:string,state:string,detail:string}> */
    private function codeChecks(): array
    {
        $out = [];

        // **البوّابةُ تُقرأ من إيصال ساهر لا من ذاكرتي.** إيصالٌ مفتاحُه
        // شجرةُ المحتوى، فلا يُقرأ إيصالُ الأمس على شيفرة اليوم.
        $sha = trim((string) shell_exec('git -C ' . escapeshellarg(base_path()) . ' rev-parse HEAD^{tree} 2>/dev/null'));
        $receipt = $sha === '' ? null : DB::table('saher_gate_receipts')
            ->where('tree_sha', 'like', substr($sha, 0, 12) . '%')
            ->orderByDesc('id')->first();

        $out[] = [
            'name' => 'البوّابةُ خضراءُ على هذه الشجرة',
            'state' => $receipt === null ? 'unknown' : ($receipt->verdict === 'PASS' ? 'pass' : 'fail'),
            'detail' => $receipt === null
                ? 'لا إيصالَ لهذه الشجرة — شغّل bash scripts/verify.sh'
                : 'إيصالُ ' . $receipt->verdict . ' للشجرة ' . substr($sha, 0, 12),
        ];

        // **سقفُ التزامنٍ يُقرأ من الصورة المنشورة لا من المفحوصة.**
        // (Coolify يبني `Dockerfile` لا `Dockerfile.prod`.)
        $df = @file_get_contents(base_path('Dockerfile')) ?: '';
        $df = preg_replace('/^\s*#.*$/m', '', $df) ?? '';
        $children = preg_match('/pm\.max_children\s*=\s*(\d+)/', $df, $m) ? (int) $m[1] : 0;

        $out[] = [
            'name' => 'سقفُ التزامن في الصورة المنشورة',
            'state' => $children >= self::MIN_FPM_CHILDREN ? 'pass' : 'fail',
            'detail' => $children === 0
                ? 'غيرُ مضبوط — والافتراضُ خمسة'
                : $children . ' عاملاً (الحدّ ' . self::MIN_FPM_CHILDREN . ')',
        ];

        // **والذاكرةُ حاملُ الأقفال والعدّادات** — وملفَّاتُها تُمحى مع كلّ نشرة.
        $store = config('cache.default');

        $out[] = [
            'name' => 'الذاكرةُ ليست على ملفَّات الحاوية',
            'state' => $store === 'file' ? 'fail' : 'pass',
            'detail' => 'المخزن: ' . $store,
        ];

        return $out;
    }

    /** @return list<array{name:string,state:string,detail:string}> */
    private function configChecks(): array
    {
        $out = [];

        $out[] = [
            'name' => 'التنقيحُ مغلق',
            'state' => config('app.debug') ? 'fail' : 'pass',
            'detail' => 'APP_DEBUG=' . (config('app.debug') ? 'true' : 'false'),
        ];

        $out[] = [
            'name' => 'قناةُ إنذارٍ خارجيّةٌ مضبوطة',
            'state' => OpsAlertService::hasExternalChannel() ? 'pass' : 'fail',
            'detail' => OpsAlertService::hasExternalChannel()
                ? 'مضبوطة — وأثبِت وصولَها بـphp artisan amial:alert-test'
                : 'AMIAL_RECON_ALERT_TO غيرُ مضبوط — المصالحةُ تجد الفرقَ ٠٢:٠٠ ولا يُوقَظ أحد',
        ];

        $remote = (string) env('AMIAL_BACKUP_REMOTE', '');

        $out[] = [
            'name' => 'نسخةٌ احتياطيّةٌ خارج الخادم',
            'state' => $remote !== '' ? 'pass' : 'fail',
            // **وسطرُ الفشل يسمّي ما يُضبَط.** «غيرُ مضبوط» وحدَها تُرسل
            // قارئَها يبحث عن اسم المتغيّر في الشيفرة.
            'detail' => $remote !== ''
                ? 'AMIAL_BACKUP_REMOTE مضبوط'
                : 'AMIAL_BACKUP_REMOTE غيرُ مضبوط — النسخُ كلُّها على الخادم الذي قد يسقط',
        ];

        // **ورمزٌ افتراضيٌّ على حسابٍ جذرٍ يفتح اللوحةَ كلَّها.**
        // ولا يُطبَع الرمزُ ولا تُقاس قوّتُه — يُسأل: أهو المعروف؟
        $weak = 0;

        try {
            foreach (DB::table('users')->whereIn('type', [1, 4])
                ->whereNotNull('password')->limit(50)->get(['password']) as $u) {
                foreach (['1234', '0000', '1111'] as $guess) {
                    if (Hash::check($guess, $u->password)) { $weak++; break; }
                }
            }
        } catch (\Throwable) {
            $weak = -1;
        }

        $out[] = [
            'name' => 'لا حسابَ إدارةٍ برمزٍ افتراضيّ',
            'state' => $weak < 0 ? 'unknown' : ($weak === 0 ? 'pass' : 'fail'),
            'detail' => $weak < 0
                ? 'تعذّرت القراءة'
                : ($weak === 0 ? 'لا رمزَ معروفاً' : $weak . ' حساباً برمزٍ معروف'),
        ];

        return $out;
    }

    /** @return list<array{name:string,state:string,detail:string}> */
    private function deployChecks(string $base): array
    {
        // **ثلاثةٌ لا تُعرف إلّا من الخادم المنشور.** وبلا `--base` تُقال
        // «غيرُ معروف» ولا تُحسَب — فصفرٌ هنا يُقرأ «فحصنا فلم نجد»،
        // والحقيقةُ أنّنا لم نفحص.
        if ($base === '') {
            return [
                ['name' => 'النشرةُ الحيّةُ تحمل هذه الشيفرة', 'state' => 'unknown',
                 'detail' => 'لا يُقاس من هنا — والنشرُ يدويّ (Source: Manual)'],
                ['name' => 'فحصُ الصحّة يردّ جاهزيّةً حقيقيّة', 'state' => 'unknown',
                 'detail' => 'لا يُقاس من هنا'],
                ['name' => 'الطاقةُ على الخادم الحقيقيّ', 'state' => 'unknown',
                 'detail' => 'شغّل BASE=… php scripts/http-load.php 24 60'],
            ];
        }

        $probe = $this->fetch($base . '/health/readiness');

        return [
            [
                'name' => 'النشرةُ الحيّةُ تستجيب',
                'state' => $probe['code'] > 0 ? 'pass' : 'fail',
                'detail' => $probe['code'] > 0 ? 'ردّ ' . $probe['code'] : 'لا استجابة',
            ],
            [
                'name' => 'فحصُ الصحّة يردّ جاهزيّةً حقيقيّة',
                'state' => $probe['code'] === 200 ? 'pass' : 'fail',
                'detail' => $probe['code'] === 200
                    ? 'قاعدةٌ وتخزينٌ وطابور'
                    : 'ردّ ' . $probe['code'] . ' — النشرةُ تُعلَن ناجحةً والتطبيقُ ليس جاهزاً',
            ],
            [
                'name' => 'الطاقةُ على الخادم الحقيقيّ',
                'state' => 'unknown',
                'detail' => 'تُقاس بجولةٍ: BASE=' . $base . ' php scripts/http-load.php 24 60'
                    . ' (الحدّ ~' . self::MIN_RPS . ' طلب/ث)',
            ],
        ];
    }

    /** @return array{code:int} */
    private function fetch(string $url): array
    {
        $c = curl_init($url);
        curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
        curl_exec($c);
        $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);

        return ['code' => $code];
    }

    /** @param list<array{name:string,state:string,detail:string}> $checks */
    private function render(array $checks): void
    {
        $this->newLine();
        $this->line('  <options=bold>جاهزيّةُ أميال باي لتجربة ألفي مستخدم</>');
        $this->line('  <fg=gray>كلُّ سطرٍ مقيسٌ من مصدره — لا رأيَ هنا.</>');
        $this->newLine();

        foreach ($checks as $c) {
            [$mark, $colour] = match ($c['state']) {
                'pass' => ['✓', 'green'],
                'fail' => ['✗', 'red'],
                default => ['؟', 'yellow'],
            };

            $this->line(sprintf('  <fg=%s>%s</> %-42s <fg=gray>%s</>',
                $colour, $mark, $c['name'], $c['detail']));
        }
    }
}
