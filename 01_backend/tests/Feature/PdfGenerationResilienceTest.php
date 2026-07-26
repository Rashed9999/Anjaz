<?php

namespace Tests\Feature;

use App\Support\ArabicPdf;
use Tests\TestCase;

/**
 * AMIAL-PDF-DURABLE-001 — توليد الـ PDF لا يتوقّف على ترتيب خطوات النشر.
 *
 * لا توجد «خدمة PDF» تنقطع: mPDF مكتبة تعمل داخل الخادم. سبب الانقطاع
 * المتكرّر كان مجلّداً مؤقّتاً يختفي مع كل نشرة، لأن volume مثبَّت على
 * storage/app يحجب ما أنشأته الصورة تحته ولا يستقبل مجلدات تُضاف لاحقاً.
 *
 * فكان كل إصلاح يُوضع في Dockerfile يُحجب عند التشغيل — ولهذا كان يعود.
 */
class PdfGenerationResilienceTest extends TestCase
{
    private const HTML = '<html dir="rtl"><body><h2>أميال باي</h2><p>إيصال 12 34 5678</p></body></html>';

    public function test_it_produces_a_real_pdf(): void
    {
        $bytes = ArabicPdf::render(self::HTML);

        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    public function test_the_temp_directory_is_created_when_missing(): void
    {
        $dir = storage_path('app/mpdf');

        // نحاكي حال الخادم بعد نشرة جديدة: المجلّد غير موجود.
        if (is_dir($dir)) {
            $this->deleteTree($dir);
        }
        $this->assertDirectoryDoesNotExist($dir);

        $this->assertSame($dir, ArabicPdf::tempDir());
        $this->assertDirectoryExists($dir);
    }

    /**
     * الحالة التي كانت تُسقط الإيصال: المجلّد موجود ولا يُكتب فيه.
     *
     * الإيصال يجب أن يخرج رغم ذلك — عبر المجلّد المؤقّت — لأن عميلاً ينتظر
     * إيصال تحويله لا يعنيه خلل في ملكية مجلّد.
     */
    public function test_an_unusable_directory_falls_back_instead_of_failing(): void
    {
        // تُحاكى الاستحالة بجعل الأب ملفّاً عادياً: mkdir يفشل تحته حتى
        // للجذر. أدقّ من chmod الذي لا يقيّد الجذر، فيُتخطّى الاختبار في
        // بيئات كثيرة — وهذا أهمّ مسار في الملفّ فلا يصحّ تخطّيه.
        $base = sys_get_temp_dir() . '/amial-pdf-test-' . uniqid();
        mkdir($base, 0775, true);
        file_put_contents("{$base}/app", 'ليس مجلّداً');

        $original = app()->storagePath();
        app()->useStoragePath($base);

        try {
            $used = ArabicPdf::tempDir();

            $this->assertNotSame("{$base}/app/mpdf", $used);
            $this->assertDirectoryIsWritable($used);

            // والأهمّ: الإيصال نفسه يخرج رغم الخلل.
            $this->assertStringStartsWith('%PDF', ArabicPdf::render(self::HTML));
        } finally {
            app()->useStoragePath($original);
            @unlink("{$base}/app");
            @rmdir($base);
        }
    }

    public function test_the_doctor_command_reports_a_healthy_setup(): void
    {
        $this->artisan('amial:pdf-doctor')->assertExitCode(0);
    }

    private function deleteTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = "{$dir}/{$entry}";
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
