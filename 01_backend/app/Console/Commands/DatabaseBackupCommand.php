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
            return 1;
        }

        $sizeMb = round(filesize($outFile) / 1024 / 1024, 2);
        $duration = round(microtime(true) - $startedAt, 1);
        $this->info("✅ تمّ النسخ: {$outFile} ({$sizeMb} MB في {$duration}s)");
        \Log::info('[Backup] success', [
            'file' => basename($outFile),
            'size_mb' => $sizeMb,
            'duration_seconds' => $duration,
        ]);

        // 6) Cleanup retention policy
        if (!$this->option('no-cleanup')) {
            $deleted = $this->cleanup($backupDir);
            if ($deleted > 0) $this->info("🗑  حُذف {$deleted} backup قديم");
        }

        return 0;
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
}
