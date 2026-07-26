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
}
