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
    private const REQUIRED_DIRS = ['mpdf', 'private', 'receipts', 'signatures'];

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
}
