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
    protected $signature = 'amial:pdf-doctor
                            {--keep : يحفظ ملفّ الاختبار بدل حذفه}
                            {--receipt= : يشغّل مسار إيصال حقيقي بالكامل ويطبع الاستثناء}';
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

        // ── إيصال حقيقي ──
        //
        // AMIAL-PDF-DOCTOR-002: الفحص العامّ أعلاه يثبت أن المكتبة تعمل
        // والمجلّدات تُكتب. ولا يثبت أن *هذا* الإيصال يُصيَّر: قالبه يقرأ
        // علاقات وحقولاً قد تكون فارغة، وخطّه العربي قد يبتلع الذاكرة.
        //
        // ولمّا كان الفشل في الإنتاج يُبتلع في Log::warning بلا أثر استدعاء،
        // فهذا الخيار هو السبيل الوحيد لرؤية الاستثناء كاملاً.
        if ($id = $this->option('receipt')) {
            $this->newLine();
            $this->info("تصيير الإيصال رقم {$id} عبر المسار الحقيقي…");

            $receipt = \App\Models\Receipt::find($id);
            if (!$receipt) {
                $this->error("  ✗ لا يوجد إيصال بهذا المعرّف");
                $problems[] = 'المعرّف خاطئ — جرّب: php artisan tinker ثم Receipt::latest()->first()->id';
            } else {
                $before = microtime(true);
                $memBefore = memory_get_usage(true);
                try {
                    (new \App\Jobs\GeneratePdfReceiptJob($receipt->id))->handle();
                    $receipt->refresh();
                    $path = $receipt->pdf_storage_path;
                    $ok = $path && \Illuminate\Support\Facades\Storage::disk('local')->exists($path);

                    $secs = round(microtime(true) - $before, 2);
                    $mb = round((memory_get_peak_usage(true) - $memBefore) / 1048576, 1);

                    if ($ok) {
                        $size = \Illuminate\Support\Facades\Storage::disk('local')->size($path);
                        $this->info("  ✓ نجح — {$path} ({$size} بايت) في {$secs}s، ذروة الذاكرة +{$mb}MB");
                        // البطء وحده يقطع الاتصال على شبكة جوّال حتى لو نجح.
                        if ($secs > 8) {
                            $problems[] = "التصيير يستغرق {$secs}s — بطيء بما يكفي لقطع الاتصال. "
                                . 'تأكّد أن عامل الطابور يستمع إلى receipts فيُولَّد مسبقاً.';
                        }
                    } else {
                        $this->error('  ✗ انتهت المهمّة بلا ملفّ محفوظ');
                        $problems[] = 'المهمّة لم ترمِ خطأً ولم تحفظ ملفّاً — راجع GeneratePdfReceiptJob';
                    }
                } catch (\Throwable $e) {
                    $this->error('  ✗ ' . get_class($e) . ': ' . $e->getMessage());
                    $this->line('     ' . $e->getFile() . ':' . $e->getLine());
                    $this->newLine();
                    $this->line('أثر الاستدعاء (أوّل ١٥ سطراً):');
                    foreach (array_slice(explode("\n", $e->getTraceAsString()), 0, 15) as $line) {
                        $this->line('  ' . $line);
                    }
                    $problems[] = 'الاستثناء أعلاه هو سبب فشل تنزيل الإيصال — ابدأ منه';
                }
            }
        }

        // ── عامل الطابور ──
        //
        // إن لم يستمع إلى receipts لم يُولَّد أي إيصال مسبقاً، فكل تنزيل
        // يُصيّر داخل الطلب — وهو ما يقطع الاتصال على شبكة جوّال.
        $pending = \Illuminate\Support\Facades\DB::table('jobs')
            ->where('queue', 'receipts')->count();
        $failed = \Illuminate\Support\Facades\DB::table('failed_jobs')
            ->where('payload', 'like', '%GeneratePdfReceiptJob%')->count();

        $this->newLine();
        $this->line("طابور receipts: {$pending} منتظرة، {$failed} فاشلة");
        if ($pending > 3) {
            $problems[] = "{$pending} مهمّة عالقة في طابور receipts — العامل لا يستمع إليه أو متوقّف";
        }
        if ($failed > 0) {
            $problems[] = "{$failed} مهمّة فاشلة — اقرأ سببها: php artisan queue:failed";
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
