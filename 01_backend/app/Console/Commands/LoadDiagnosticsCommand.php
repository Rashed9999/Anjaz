<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * AMIAL-LOAD — تشخيص حيّ أثناء اختبار التحمّل.
 *
 *   php artisan amial:load-diagnostics          (لقطة واحدة)
 *   php artisan amial:load-diagnostics --watch   (كل ثانيتين)
 *
 * يكشف نقاط الانهيار الستّ: الأقفال، المعاملات الطويلة، طول الطوابير، الرسوم غير المسوّاة.
 */
class LoadDiagnosticsCommand extends Command
{
    protected $signature = 'amial:load-diagnostics {--watch}';
    protected $description = 'تشخيص حيّ للأقفال/الطوابير/المعاملات أثناء اختبار التحمّل';

    public function handle(): int
    {
        do {
            $this->snapshot();
            if ($this->option('watch')) sleep(2);
        } while ($this->option('watch'));
        return self::SUCCESS;
    }

    private function snapshot(): void
    {
        $this->line("\n===== " . now()->toTimeString() . " =====");

        // 1) المعاملات الطويلة (>2 ثانية) — Long transactions
        try {
            $longTrx = DB::select("SELECT COUNT(*) c FROM information_schema.innodb_trx WHERE trx_started < NOW() - INTERVAL 2 SECOND")[0]->c ?? 0;
            $this->row('معاملات طويلة (>2ث)', $longTrx, $longTrx > 0);
        } catch (\Throwable $e) { $this->row('معاملات طويلة', 'تعذّر (صلاحيات؟)', false); }

        // 2) انتظار الأقفال — DB locking
        try {
            $lockWaits = DB::select("SELECT COUNT(*) c FROM performance_schema.data_lock_waits")[0]->c ?? 0;
            $this->row('انتظار أقفال', $lockWaits, $lockWaits > 5);
        } catch (\Throwable $e) { $this->row('انتظار أقفال', 'تعذّر', false); }

        // 3) الاتصالات النشطة — Connection pool
        try {
            $threads = DB::select("SHOW STATUS LIKE 'Threads_connected'")[0]->Value ?? '?';
            $max = DB::select("SHOW VARIABLES LIKE 'max_connections'")[0]->Value ?? '?';
            $this->row('اتصالات MySQL', "{$threads} / {$max}", (int)$threads > (int)$max * 0.8);
        } catch (\Throwable $e) {}

        // 4) طول طابور Redis — Queue lag
        try {
            $qlen = Redis::connection()->llen('queues:default');
            $this->row('طابور default', $qlen, $qlen > 1000);
        } catch (\Throwable $e) { $this->row('طابور Redis', 'تعذّر الاتصال', true); }

        // 5) الرسوم غير المسوّاة — Ledger backlog
        try {
            $pending = DB::table('platform_fee_entries')->where('reconciled', false)->count();
            $this->row('رسوم غير مسوّاة', $pending, $pending > 10000);
        } catch (\Throwable $e) {}

        // 6) آخر deadlock
        try {
            $status = DB::select('SHOW ENGINE INNODB STATUS')[0]->Status ?? '';
            $hasDeadlock = str_contains($status, 'LATEST DETECTED DEADLOCK');
            $this->row('deadlock مُكتشف', $hasDeadlock ? 'نعم — راجع INNODB STATUS' : 'لا', $hasDeadlock);
        } catch (\Throwable $e) {}
    }

    private function row(string $label, $value, bool $warn): void
    {
        $tag = $warn ? '<fg=red>⚠</>' : '<fg=green>✓</>';
        $this->line(sprintf('%s %-22s : %s', $tag, $label, $value));
    }
}
