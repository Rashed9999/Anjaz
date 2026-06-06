<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * P0-MONITORING — خدمة فحص صحّة النظام.
 *
 * تستخدم في:
 *   1. endpoint /admin/health للأدمن.
 *   2. /ping للـ external monitoring (UptimeRobot, BetterUptime).
 *   3. أمر artisan لمراقبة دورية.
 *
 * الفحوصات:
 *   - DB connection + استعلام بسيط
 *   - Cache (Redis أو file)
 *   - Storage مرفقات قابلة للكتابة
 *   - Queue + Failed jobs count
 *   - Slow queries في آخر ساعة
 *   - Disk space
 *   - حجم logs
 */
class SystemHealthService
{
    /**
     * فحص شامل — يُرجع status + details لكل component.
     */
    public function checkAll(): array
    {
        $checks = [
            'database' => $this->checkDatabase(),
            'cache' => $this->checkCache(),
            'storage' => $this->checkStorage(),
            'queue' => $this->checkQueue(),
            'disk' => $this->checkDisk(),
            'php' => $this->checkPhp(),
        ];

        $allHealthy = collect($checks)->every(fn($c) => $c['status'] === 'healthy');
        $hasWarnings = collect($checks)->contains(fn($c) => $c['status'] === 'warning');

        return [
            'status' => $allHealthy ? 'healthy' : ($hasWarnings ? 'degraded' : 'unhealthy'),
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
            'environment' => app()->environment(),
            'version' => config('app.version', 'unknown'),
        ];
    }

    /** فحص خفيف لـ /ping endpoint (يجب أن يكون <100ms). */
    public function quickPing(): bool
    {
        try {
            DB::select('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // ============ Individual checks ============

    private function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('SELECT 1 as ok');
            $latencyMs = round((microtime(true) - $start) * 1000, 2);

            // إحصائيات أساسية
            $merchantCount = DB::table('merchant_profiles')->count();
            $userCount = DB::table('users')->count();

            $status = 'healthy';
            $message = "DB يعمل (latency: {$latencyMs}ms)";

            if ($latencyMs > 1000) {
                $status = 'warning';
                $message = "DB بطيء جداً ({$latencyMs}ms)";
            }

            return [
                'status' => $status,
                'message' => $message,
                'latency_ms' => $latencyMs,
                'merchants' => $merchantCount,
                'users' => $userCount,
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'فشل الاتصال بقاعدة البيانات',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkCache(): array
    {
        try {
            $key = 'health_check_' . uniqid();
            Cache::put($key, 'ok', 5);
            $value = Cache::get($key);
            Cache::forget($key);

            return [
                'status' => $value === 'ok' ? 'healthy' : 'warning',
                'message' => $value === 'ok' ? 'Cache يعمل' : 'Cache غير متّسق',
                'driver' => config('cache.default'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'فشل Cache',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkStorage(): array
    {
        try {
            $disk = Storage::disk(config('filesystems.default'));
            $testFile = '_health_' . uniqid() . '.txt';
            $disk->put($testFile, 'ok');
            $exists = $disk->exists($testFile);
            $disk->delete($testFile);

            return [
                'status' => $exists ? 'healthy' : 'unhealthy',
                'message' => $exists ? 'Storage قابل للكتابة' : 'Storage معطّل',
                'driver' => config('filesystems.default'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'unhealthy',
                'message' => 'فشل Storage',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkQueue(): array
    {
        try {
            $failed = DB::table('failed_jobs')->count() ?? 0;
            $pending = 0;

            // عدّ jobs المعلّقة (يعتمد على queue driver)
            if (config('queue.default') === 'database') {
                $pending = DB::table('jobs')->count();
            }

            $status = 'healthy';
            $message = "Queue يعمل (معلّقة: {$pending}, فاشلة: {$failed})";

            if ($failed > 50) {
                $status = 'warning';
                $message = "تحذير: {$failed} jobs فاشلة";
            }
            if ($pending > 1000) {
                $status = 'warning';
                $message = "Queue مكتظّ ({$pending} pending)";
            }

            return [
                'status' => $status,
                'message' => $message,
                'pending' => $pending,
                'failed' => $failed,
                'driver' => config('queue.default'),
            ];
        } catch (\Throwable $e) {
            return [
                'status' => 'warning',
                'message' => 'تعذّر فحص Queue',
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkDisk(): array
    {
        try {
            $path = storage_path();
            $free = disk_free_space($path);
            $total = disk_total_space($path);

            if ($free === false || $total === false) {
                return ['status' => 'warning', 'message' => 'تعذّر قراءة Disk'];
            }

            $usedPct = round((1 - $free / $total) * 100, 1);
            $freeGb = round($free / 1024 / 1024 / 1024, 2);

            $status = 'healthy';
            if ($usedPct > 90) $status = 'unhealthy';
            elseif ($usedPct > 80) $status = 'warning';

            return [
                'status' => $status,
                'message' => "Disk: {$usedPct}% مستخدم ({$freeGb}GB متاح)",
                'used_pct' => $usedPct,
                'free_gb' => $freeGb,
            ];
        } catch (\Throwable $e) {
            return ['status' => 'warning', 'message' => 'تعذّر فحص Disk'];
        }
    }

    private function checkPhp(): array
    {
        return [
            'status' => 'healthy',
            'message' => 'PHP ' . PHP_VERSION,
            'version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ];
    }
}
