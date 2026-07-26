<?php

namespace App\Console\Commands;

use App\Support\ArabicPdf;
use Illuminate\Console\Command;

/**
 * AMIAL-PDF-DURABLE-001 — تشخيص توليد الـ PDF.
 *
 * توليد الـ PDF لا يعتمد على أي خدمة خارجية: mPDF مكتبة PHP تعمل داخل
 * الخادم نفسه. فحين «ينقطع»، السبب دائماً محلّي — مجلّد غائب، أو ملكية
 * خاطئة، أو ذاكرة، أو امتداد PHP ناقص.
 *
 * المشكلة أن الفشل يظهر للمستخدم كخطأ عامّ، فيُصرف الوقت في التخمين.
 * هذا الأمر يجيب في ثانيتين:
 *
 *   php artisan amial:pdf-doctor
 */
class AmialPdfDoctor extends Command
{
    protected $signature = 'amial:pdf-doctor {--keep : يحفظ ملفّ الاختبار بدل حذفه}';
    protected $description = 'يفحص جاهزية توليد ملفات PDF ويشخّص سبب الفشل';

    public function handle(): int
    {
        $this->info('فحص توليد الـ PDF — أميال باي');
        $this->newLine();

        $problems = [];

        // ── 1) امتدادات PHP التي يحتاجها mPDF ──
        foreach (['mbstring', 'gd'] as $ext) {
            if (extension_loaded($ext)) {
                $this->line("  ✓ امتداد {$ext} محمّل");
            } else {
                $this->error("  ✗ امتداد {$ext} غير محمّل — mPDF لا يعمل بدونه");
                $problems[] = "ثبّت php-{$ext} ثم أعد تشغيل php-fpm";
            }
        }

        // ── 2) المجلّد المؤقّت ──
        $expected = storage_path('app/mpdf');
        if (is_dir($expected)) {
            $this->line("  ✓ المجلّد موجود: {$expected}");
        } else {
            $this->error("  ✗ المجلّد غير موجود: {$expected}");
            $problems[] = 'المجلّد يُنشأ في entrypoint — إن غاب فالنشرة لم تُعِد التشغيل بعد التحديث';
        }

        if (is_dir($expected) && !is_writable($expected)) {
            $owner = function_exists('posix_getpwuid')
                ? (posix_getpwuid(fileowner($expected))['name'] ?? '?') : '?';
            $this->error("  ✗ غير قابل للكتابة (المالك: {$owner})");
            $problems[] = 'chown -R www-data:www-data storage && chmod -R 775 storage';
        } elseif (is_dir($expected)) {
            $this->line('  ✓ قابل للكتابة');
        }

        // ── 3) الذاكرة ──
        $limit = ini_get('memory_limit');
        $this->line("  • حدّ الذاكرة الحالي: {$limit}");

        // ── 4) الاختبار الحقيقي: نولّد ملفاً فعلاً ──
        // كل ما سبق مؤشّرات. هذا وحده هو الجواب.
        $this->newLine();
        $this->info('توليد ملفّ اختباري بنصّ عربي…');

        try {
            $started = microtime(true);
            $bytes = ArabicPdf::render(
                '<html dir="rtl"><body style="font-family:sans-serif">'
                . '<h2>أميال باي — فحص التوليد</h2>'
                . '<p>هذا ملفّ اختباري. الأرقام: ١٢٣٤٥٦٧٨٩ / 123456789</p>'
                . '</body></html>'
            );
            $ms = round((microtime(true) - $started) * 1000);

            if (strncmp($bytes, '%PDF', 4) !== 0) {
                $this->error('  ✗ الناتج ليس ملفّ PDF صالحاً');
                $problems[] = 'ناتج غير متوقّع من mPDF — راجع storage/logs';
            } else {
                $kb = round(strlen($bytes) / 1024, 1);
                $this->line("  ✓ نجح التوليد — {$kb} كيلوبايت في {$ms} مللي ثانية");

                if ($ms > 4000) {
                    $this->warn('  ⚠ بطيء: غالباً ذاكرة الخطوط تُبنى من جديد في كل مرّة');
                    $problems[] = 'تأكّد أن storage/app/mpdf دائم لا مؤقّت';
                }

                if ($this->option('keep')) {
                    $path = storage_path('app/pdf-doctor.pdf');
                    file_put_contents($path, $bytes);
                    $this->line("  • حُفظ في: {$path}");
                }
            }
        } catch (\Throwable $e) {
            $this->error('  ✗ فشل التوليد: ' . $e->getMessage());
            $problems[] = 'السبب أعلاه حرفياً — ابدأ منه';
        }

        // ── الخلاصة ──
        $this->newLine();
        if (empty($problems)) {
            $this->info('✅ توليد الـ PDF سليم.');
            return self::SUCCESS;
        }

        $this->error('❌ مشكلات تحتاج إصلاحاً:');
        foreach ($problems as $i => $p) {
            $this->line('   ' . ($i + 1) . ') ' . $p);
        }

        return self::FAILURE;
    }
}
