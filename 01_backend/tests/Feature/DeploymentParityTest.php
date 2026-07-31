<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * AMIAL-PDF-DURABLE-001 — تكافؤ ملفّات النشر.
 *
 * في المستودع نسختان من كل ملفّ نشر: Dockerfile و Dockerfile.prod،
 * docker/entrypoint.sh و docker/entrypoint.prod.sh. والنشر الحالي يستعمل
 * النسخة العادية لا نسخة الإنتاج.
 *
 * وقعتُ في الفخّ حرفياً: أصلحتُ مجلّدات mPDF في نسخة الإنتاج وحدها،
 * وأعلنتُ الإصلاح، وهو لا يصل الخادم أصلاً. الخلل لا يظهر في أي اختبار
 * لأن الاختبارات تعمل على القرص لا داخل الحاوية — ولا يظهر في النشر لأن
 * الفشل يقع لاحقاً عند أوّل إيصال.
 *
 * هذا الاختبار يُثبت أن المجلّدات الحرجة مذكورة في النسختين معاً.
 */
class DeploymentParityTest extends TestCase
{
    /** مجلّدات لا يعمل النظام بدونها ولا تُنشأ تلقائياً. */
    private const REQUIRED_DIRS = ['mpdf', 'private', 'receipts', 'documents', 'signatures'];

    public static function deploymentFiles(): array
    {
        return [
            'Dockerfile' => ['Dockerfile'],
            'Dockerfile.prod' => ['Dockerfile.prod'],
            'entrypoint.sh' => ['docker/entrypoint.sh'],
            'entrypoint.prod.sh' => ['docker/entrypoint.prod.sh'],
        ];
    }

    /**
     * @dataProvider deploymentFiles
     */
    public function test_it_creates_every_required_storage_directory(string $file): void
    {
        $path = base_path($file);
        $this->assertFileExists($path);

        $contents = file_get_contents($path);
        $missing = [];

        foreach (self::REQUIRED_DIRS as $dir) {
            // تُقبل الصيغتان: المسار كاملاً، أو داخل قوسي bash {a,b,c}.
            if (!str_contains($contents, "storage/app/{$dir}")
                && !preg_match('/storage\/app\/\{[^}]*\b' . preg_quote($dir, '/') . '\b[^}]*\}/', $contents)) {
                $missing[] = $dir;
            }
        }

        $this->assertSame([], $missing,
            "{$file} لا يُنشئ: " . implode('، ', $missing)
            . "\nإصلاح ملفّ نشر دون توأمه يعني إصلاحاً لا يصل الخادم.");
    }

    public static function dockerfiles(): array
    {
        return [
            'Dockerfile' => ['Dockerfile'],
            'Dockerfile.prod' => ['Dockerfile.prod'],
        ];
    }

    /**
     * AMIAL-BUILD-001 — امتداد zip لا يُبنى ضمن `-j$(nproc)`.
     *
     * هدفا zip — php_zip.lo و zip_stream.lo — يتسابقان على إنشاء المجلّد
     * ‎.libs، فيفشل أحدهما بـ «mkdir: can't create directory '.libs': File
     * exists» وتسقط النشرة كلّها.
     *
     * وهو عطل متقطّع يعتمد على عدد الأنوية وتوقيتها: نجحت نشرات كثيرة
     * بالصدفة ثم فشلت واحدة بلا أي تغيير في الشيفرة. وهذا أسوأ ما فيه —
     * الفشل العشوائي يُلقى عادةً على آخر تعديل، والسبب في ملفّ لم يُمَسّ.
     *
     * @dataProvider dockerfiles
     */
    public function test_zip_is_not_built_in_parallel(string $file): void
    {
        $contents = file_get_contents(base_path($file));
        $this->assertNotEmpty($contents);

        // سطراً سطراً لا بتعبير نمطيّ متعدّد الأسطر: المحاولة الأولى كتبتُها
        // تعبيراً «ذكيّاً» فالتقط السطر الأوّل وحده وتجاهل بقيّة الأمر — فمرّ
        // الاختبار وهو لا يفحص شيئاً. حارسٌ يمرّ على فراغ أسوأ من لا حارس،
        // لأنه يشتري طمأنينةً كاذبة.
        $offenders = [];
        $inParallelInstall = false;

        foreach (explode("\n", $contents) as $number => $line) {
            $code = trim(preg_replace('/#.*$/', '', $line));

            if (!$inParallelInstall) {
                if (str_contains($code, 'docker-php-ext-install') && str_contains($code, '-j')) {
                    $inParallelInstall = true;
                } else {
                    continue;
                }
            }

            if (preg_match('/(?<![\w.-])zip(?![\w.-])/', $code)) {
                $offenders[] = 'سطر ' . ($number + 1) . ': ' . trim($line);
            }

            // الأمر ينتهي عند أوّل سطر لا ينتهي بشرطة مائلة عكسية.
            if (!str_ends_with($code, '\\')) {
                $inParallelInstall = false;
            }
        }

        $this->assertSame([], $offenders,
            "{$file}: امتداد zip ما زال ضمن التثبيت المتوازي — "
            . "ابنِه وحده بـ `docker-php-ext-install zip`.\n  "
            . implode("\n  ", $offenders));
    }

    /**
     * AMIAL-PDF-QUEUE-001 — كل طابور تُرسَل إليه مهمّة يجب أن يستمع إليه عامل.
     *
     * وُجد من تسجيل شاشة: تنزيل الإيصال يفشل بـ
     * «Connection closed while receiving data». والسبب سلسلة صامتة —
     * ReceiptService يُرسل التوليد إلى طابور `receipts`، وعامل الإنتاج كان
     * يستمع إلى `default,notifications` وحدهما. فلا يُولَّد إيصال مسبقاً
     * قطّ، ويصير كل تنزيل تصييراً كاملاً داخل الطلب، فيُقطع على شبكة جوّال.
     *
     * ولا شيء يشتكي: المهمّة تُخزَّن في الجدول وتنتظر إلى الأبد. لا خطأ،
     * ولا سجلّ، ولا مهمّة فاشلة — طابور ينمو وحده في صمت.
     *
     * والملفّان تباعدا كعادتهما: النسخة العادية صحيحة والإنتاجية ناقصة،
     * وهو الصنف الذي أنشئ هذا الملفّ لأجله.
     */
    public function test_every_dispatched_queue_has_a_worker_listening_to_it(): void
    {
        $dispatched = [];
        foreach ($this->phpFilesIn(base_path('app')) as $file) {
            if (preg_match_all("/onQueue\\(['\"]([a-z_\\-]+)['\"]\\)/", file_get_contents($file), $m)) {
                foreach ($m[1] as $q) $dispatched[$q] = basename($file);
            }
        }

        $this->assertNotEmpty($dispatched, 'لم يُعثر على أي onQueue — تغيّرت الصياغة والفحص صار أعمى');

        foreach (['docker/supervisord.conf', 'docker/supervisord.prod.conf'] as $conf) {
            $text = file_get_contents(base_path($conf));
            $this->assertStringContainsString('queue:work', $text, "$conf بلا عامل طابور");

            foreach ($dispatched as $queue => $source) {
                $this->assertMatchesRegularExpression(
                    '/--queue=[a-z_,\-]*\b' . preg_quote($queue, '/') . '\b/',
                    $text,
                    // الأقواس ضرورية: أسماء متغيّرات PHP تقبل البايتات العالية،
                    // فـ«$queue» يُقرأ اسماً واحداً يشمل الشولة العربية بعده.
                    "$conf لا يستمع إلى طابور «{$queue}» الذي يُرسل إليه $source — "
                        . 'المهام ستنتظر إلى الأبد بلا خطأ ولا سجلّ'
                );
            }
        }
    }

    /** يمرّ على ملفّات php تحت مجلّد. */
    private function phpFilesIn(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') $out[] = $f->getPathname();
        }
        return $out;
    }

    public function test_the_ledger_backfill_runs_on_every_production_boot(): void
    {
        // AMIAL-LEDGER-OPENING-002 — أخطر ما يمكن أن يسقط من ملفّ النشر.
        //
        // الترحيل يُدخل المحافظ القائمة في دفتر الأستاذ. وبدونه يرفض الدفترُ
        // أوّل خصمٍ من أي محفظة قديمة، فتتوقّف التحويلات كلّها بعد النشر —
        // بلا انهيار ولا رسالة تشرح، فقط «الرصيد لا يكفي» على أرصدةٍ كافية.
        //
        // وقد سُلّم أوّل مرّة أمراً يدوياً في رسالةٍ إلى المشغّل، وهو ما يُنسى
        // مرّة واحدة فتكفي. فصار في ملفّ الإقلاع، وهذا الفحص يمنع سقوطه منه.
        $script = file_get_contents(base_path('docker/entrypoint.prod.sh'));

        $this->assertStringContainsString('amial:ledger-backfill', $script,
            'سقط ترحيل الدفتر من ملفّ الإقلاع. وبدونه يرفض الدفترُ كل تحويل '
            . 'من محفظةٍ قائمة بعد أوّل نشرة — بلا رسالة تشرح السبب.');

        // وبعد الهجرات لا قبلها: الجداول يجب أن تكون قائمة أوّلاً.
        $migratePos = strpos($script, 'artisan migrate');
        $backfillPos = strpos($script, 'amial:ledger-backfill');

        $this->assertNotFalse($migratePos, 'لا هجرات في ملفّ الإقلاع');
        $this->assertLessThan($backfillPos, $migratePos,
            'الترحيل يسبق الهجرات — فيعمل على جداول لم تُنشأ بعد');
    }
}
