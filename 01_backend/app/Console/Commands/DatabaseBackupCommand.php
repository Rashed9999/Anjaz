<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * P0-BACKUPS — أمر backup يدوي بدون اعتماد خارجي.
 *
 * يستخدم `mysqldump` المتوفّر افتراضياً على كل Linux/Cloudways.
 *
 * Usage:
 *   php artisan amial:backup
 *   php artisan amial:backup --no-cleanup   (احتفظ بكل backups القديمة)
 *
 * Output:
 *   /storage/app/backups/db-2026-06-05_08-00-00.sql.gz
 *
 * يحتفظ بـ:
 *   - آخر 7 backups يومية
 *   - آخر 4 backups أسبوعية (الجمعة)
 *   - آخر 6 backups شهرية (أوّل الشهر)
 *
 * يجب تشغيله من cron يومياً.
 */
class DatabaseBackupCommand extends Command
{
    protected $signature = 'amial:backup {--no-cleanup : لا تحذف القديمة}';
    protected $description = 'نسخة احتياطية مضغوطة لقاعدة البيانات';

    public function handle(): int
    {
        $startedAt = microtime(true);
        $this->info('🔵 بدء النسخ الاحتياطي...');

        // 1) تحضير المسار
        $backupDir = storage_path('app/backups');
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0775, true);
        }

        // 2) DB credentials
        $host = config('database.connections.mysql.host');
        $port = config('database.connections.mysql.port', 3306);
        $db   = config('database.connections.mysql.database');
        $user = config('database.connections.mysql.username');
        $pass = config('database.connections.mysql.password');

        if (!$db) {
            $this->error('❌ DATABASE اسم غير محدّد في config');
            return 1;
        }

        // 3) ملف الـ output
        $stamp = now()->format('Y-m-d_H-i-s');
        $outFile = "{$backupDir}/db-{$stamp}.sql.gz";

        // 4) build mysqldump command
        // ملاحظة: --single-transaction آمن للـ InnoDB ولا يقفل الجداول
        $cmd = sprintf(
            'mysqldump --host=%s --port=%s --user=%s --password=%s '
            . '--single-transaction --quick --skip-lock-tables '
            . '--default-character-set=utf8mb4 %s 2>/dev/null | gzip > %s',
            escapeshellarg($host),
            escapeshellarg((string)$port),
            escapeshellarg($user),
            escapeshellarg($pass),
            escapeshellarg($db),
            escapeshellarg($outFile),
        );

        // 5) Execute
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($outFile) || filesize($outFile) === 0) {
            $this->error('❌ فشلت عملية mysqldump (exit: ' . $exitCode . ')');
            \Log::error('[Backup] mysqldump failed', [
                'exit_code' => $exitCode,
                'output' => $output,
            ]);

            $this->alarm('backup.failed', 'فشلت النسخةُ الاحتياطيّة',
                "⛔ أميال باي — لم تُنتَج نسخةٌ احتياطيّةٌ الليلة (mysqldump exit {$exitCode}).\n"
                . 'كلُّ ساعةٍ تمرّ بلا نسخةٍ تُوسّع ما يضيع عند أوّل عطل.');

            return 1;
        }

        // ══════════════════════════════════════════════════════════════
        // AMIAL-PROD-READINESS-001 — **ملفٌّ موجودٌ ليس نسخةً صالحة.**
        //
        // كان الفحصُ «`exitCode == 0` وحجمٌ > صفر». و`mysqldump` المقطوعُ
        // في منتصفه — قرصٌ امتلأ، أو اتّصالٌ انقطع، أو `pipefail` غيرُ
        // مضبوطٍ خلف `| gzip` — **يترك ملفّاً غيرَ فارغٍ ورمزَ خروجٍ صفراً**.
        // فتُقرأ نسخةٌ ناقصةٌ سليمةً، ولا يُكتشَف ذلك إلّا ليلةَ الحاجة.
        //
        // فيُفحص شيئان: أنّ الضغطَ سليمٌ إلى آخر بايت (`gzip -t`)، وأنّ
        // `mysqldump` ختم عمله (`-- Dump completed`). والثاني وحدَه يكشف
        // القطعَ الذي يمرّ من الأوّل.
        //
        // **وبصمةٌ تُكتب بجانب النسخة** — كما يفعل `scripts/backup.sh` —
        // فـ`scripts/restore.sh` يرفض النسخةَ المفسودة ولا يُدخلها.
        // ══════════════════════════════════════════════════════════════
        $verdict = $this->verifyArchive($outFile);

        if ($verdict !== null) {
            $this->error("❌ النسخةُ غيرُ صالحة: {$verdict}");
            @unlink($outFile);

            $this->alarm('backup.corrupt', 'النسخةُ الاحتياطيّةُ غيرُ صالحة',
                "⛔ أميال باي — أُنتجت نسخةٌ ثمّ رُفضت: {$verdict}\n"
                . 'حُذفت لئلّا تُقرأ سليمةً ليلةَ الحاجة. لا نسخةَ لهذه الليلة.');

            return 1;
        }

        @file_put_contents($outFile . '.sha256',
            hash_file('sha256', $outFile) . '  ' . basename($outFile) . "\n");

        $sizeMb = round(filesize($outFile) / 1024 / 1024, 2);
        $duration = round(microtime(true) - $startedAt, 1);
        $this->info("✅ تمّ النسخ: {$outFile} ({$sizeMb} MB في {$duration}s)");
        \Log::info('[Backup] success', [
            'file' => basename($outFile),
            'size_mb' => $sizeMb,
            'duration_seconds' => $duration,
        ]);

        // ══════════════════════════════════════════════════════════════
        // AMIAL-BACKUP-OFFSITE-001 — **نسخةٌ بجانب القاعدة تموت معها.**
        //
        // النسخُ كلُّه على الخادم نفسِه. وقرصٌ يُفسَد أو خادمٌ يُحذَف أو
        // مزوّدٌ يُغلق حساباً — **يذهب الأصلُ والنسخةُ في لحظة**، والزمنُ
        // المقيسُ للاستعادة (٣ ثوانٍ) لا يعني شيئاً بلا ما يُستعاد منه.
        //
        // **ولا يُشحَن إلّا بعد التحقّق** — فرفعُ أرشيفٍ مقطوعٍ إلى مخزنٍ
        // بعيدٍ يُنتج طمأنينةً كاذبةً في مكانين بدل واحد.
        //
        // **وغيابُ الوجهة ليس فشلاً** — هو حالةٌ تُقال: من لم يضبط
        // `AMIAL_BACKUP_REMOTE` يبقى على نسخةٍ محلّيّةٍ صالحة، **ويُرفع
        // عطلٌ يقول ذلك** فلا يُقرأ الصمتُ أماناً. (القاعدة السابعة.)
        // ══════════════════════════════════════════════════════════════
        $this->shipOffsite($outFile);

        // 6) Cleanup retention policy
        if (!$this->option('no-cleanup')) {
            $deleted = $this->cleanup($backupDir);
            if ($deleted > 0) $this->info("🗑  حُذف {$deleted} backup قديم");
        }

        return 0;
    }

    /**
     * يفحص أرشيفَ النسخة. يعيد `null` إن كان سليماً، وإلّا سببَ الرفض.
     *
     * **والفحصان مختلفان ولا يُغني أحدُهما:** `gzip -t` يكشف الفسادَ في
     * البِنية، و«ختمُ mysqldump» يكشف **القطعَ النظيف** — تدفّقٌ توقّف
     * وأُغلق الضغطُ عليه سليماً، فيمرّ من الأوّل ويسقط من الثاني.
     */
    private function verifyArchive(string $file): ?string
    {
        exec('gzip -t ' . escapeshellarg($file) . ' 2>&1', $o, $rc);

        if ($rc !== 0) {
            return 'أرشيفٌ مضغوطٌ فاسد (gzip -t)';
        }

        // آخرُ ما يكتبه mysqldump. غيابُه = قُطع التدفّق قبل النهاية.
        exec('gunzip -c ' . escapeshellarg($file) . ' 2>/dev/null | tail -c 4096',
            $tailLines, $tailRc);

        $tail = implode("\n", $tailLines);

        if (! str_contains($tail, 'Dump completed')) {
            return 'ناقصة — لا خاتمةَ «Dump completed» (قُطع التفريغ)';
        }

        return null;
    }

    /**
     * إنذارٌ تشغيليّ — **الأثرُ في مركز الأعطال أوّلاً**، ثمّ القناةُ
     * الخارجيّةُ إن وُجدت.
     *
     * وكان فشلُ النسخة يُكتب في `Log::error` وحدَه: ليلةٌ بلا نسخةٍ لا
     * يعرفها أحدٌ حتّى تُطلَب النسخةُ ولا توجد.
     */
    private function alarm(string $key, string $title, string $detail): void
    {
        try {
            app(\App\Services\OpsAlertService::class)->raise($key, $title, $detail);
        } catch (\Throwable $ignored) {
            // المُنذِرُ لا يُسقط من استدعاه.
        }
    }

    /**
     * سياسة الاحتفاظ:
     *   - آخر 7 يومية
     *   - الجُمَع آخر 4 أسابيع (28 يوم)
     *   - أوائل الأشهر آخر 6 أشهر
     *   - أيّ شيء آخر يُحذف
     */
    private function cleanup(string $dir): int
    {
        $files = glob($dir . '/db-*.sql.gz');
        if (empty($files)) return 0;

        $now = now();
        $deleted = 0;
        foreach ($files as $file) {
            // استخرج التاريخ من اسم الملف: db-2026-06-05_08-00-00.sql.gz
            if (!preg_match('/db-(\d{4}-\d{2}-\d{2})/', basename($file), $m)) continue;
            try {
                $date = \Carbon\Carbon::createFromFormat('Y-m-d', $m[1]);
            } catch (\Throwable) { continue; }

            $daysAgo = $date->diffInDays($now);

            // احتفظ بآخر 7 يومية
            if ($daysAgo <= 7) continue;

            // احتفظ بالجمعة في آخر 28 يوم
            if ($daysAgo <= 28 && $date->isFriday()) continue;

            // احتفظ بأوّل يوم من الشهر آخر 6 أشهر
            if ($daysAgo <= 180 && $date->day === 1) continue;

            // وإلا احذف
            if (unlink($file)) $deleted++;
        }
        return $deleted;
    }

    /**
     * يشحن النسخةَ إلى مخزنٍ خارج الخادم — **بعد التحقّق لا قبله**.
     *
     * `AMIAL_BACKUP_REMOTE` وجهةُ `rclone` (مثل `s3:amial-backups/db`).
     * و`rclone` اختيرت لأنّها **تتكلّم كلَّ المخازن** — S3 وBackblaze
     * وGoogle Drive — فلا يُقيَّد صاحبُ المشروع بمزوّدٍ واحد، والخادمُ
     * في اليمن فالخيارُ الرخيصُ يتغيّر.
     *
     * **ولا يُسقَط النسخُ بفشل الشحن:** النسخةُ المحلّيّةُ سليمةٌ ومحقَّقة،
     * وفشلُ الرفع يُرفَع عطلاً ويُقال — ولا يُلغي ما نجح.
     */
    private function shipOffsite(string $file): void
    {
        $remote = trim((string) env('AMIAL_BACKUP_REMOTE', ''));

        if ($remote === '') {
            $this->warn('⚠️  لا وجهةَ خارجيّة — النسخةُ على الخادم وحدَه.');

            $this->raiseOps(
                'backup.offsite.unconfigured',
                'لا نسخةَ خارج الخادم',
                'كلُّ النسخ على الخادم نفسِه. اضبط `AMIAL_BACKUP_REMOTE` '
                . '(وجهةُ rclone) — وقرصٌ واحدٌ يُفقِد الأصلَ والنسخةَ معاً.',
            );

            return;
        }

        exec('command -v rclone 2>/dev/null', $probe, $hasRclone);

        if ($hasRclone !== 0) {
            $this->error('⛔ الوجهةُ مضبوطةٌ و`rclone` غيرُ مثبَّتة.');

            $this->raiseOps(
                'backup.offsite.tool-missing',
                'أداةُ الشحن غائبة',
                '`AMIAL_BACKUP_REMOTE` مضبوطةٌ ولا `rclone` في الصورة — '
                . '**فالوجهةُ تُوهم بنسخةٍ بعيدةٍ لا وجودَ لها.**',
            );

            return;
        }

        foreach ([$file, $file . '.sha256'] as $one) {
            if (! is_file($one)) {
                continue;
            }

            exec('rclone copy ' . escapeshellarg($one) . ' '
                . escapeshellarg($remote) . ' 2>&1', $out, $rc);

            if ($rc !== 0) {
                $this->error('⛔ فشل الشحن: ' . implode(' ', array_slice($out, -3)));

                $this->raiseOps(
                    'backup.offsite.failed',
                    'فشل شحنُ النسخة خارج الخادم',
                    'النسخةُ المحلّيّةُ سليمةٌ ومحقَّقة، **ولا نسخةَ بعيدةً '
                    . 'لهذه الليلة**: ' . implode(' ', array_slice($out, -3)),
                );

                return;
            }
        }

        $this->info('☁️  شُحنت النسخةُ إلى ' . $remote);
    }

    /** يرفع عطلاً ولا يُسقط الأمر — فالأثرُ أهمُّ من التبليغ. */
    private function raiseOps(string $key, string $title, string $detail): void
    {
        try {
            app(\App\Services\OpsAlertService::class)->raise($key, $title, $detail);
        } catch (\Throwable $e) {
            \Log::warning('[Backup] ops alert failed', ['err' => $e->getMessage()]);
        }
    }
}
