<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * AMIAL-HEALTH-001 (v1.0-C)
 *
 * Health check endpoints — للـ load balancer و monitoring tools.
 *
 * **التصميم بنمط Kubernetes:**
 *   - /health/liveness  : هل التطبيق يعمل؟ (basic - لا يفحص dependencies)
 *   - /health/readiness : هل جاهز لاستقبال traffic؟ (يفحص DB, Redis, Storage)
 *
 * **استخدام:**
 *   - Load balancer health check → /health/liveness
 *   - Kubernetes readiness probe  → /health/readiness
 *   - Cron/uptime monitor (Pingdom, UptimeRobot) → /health/readiness
 */
class HealthCheckController
{
    /**
     * GET /health/liveness
     *
     * يعيد 200 طالما PHP يستجيب. لا فحوصات dependencies.
     * مهم: لا يجب أن يفشل بسبب DB down (الـ pod ما زال "alive").
     */
    public function liveness(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'alive',
            'timestamp' => now()->toIso8601String(),
            'version' => config('app.version', '1.0.0'),
        ], 200);
    }

    /**
     * GET /health/readiness
     *
     * يفحص أن كل dependencies تعمل. إذا فشل أي منها → 503.
     * load balancer يجب أن يوقف الـ traffic عن هذا الـ pod.
     */
    public function readiness(): JsonResponse
    {
        $checks = [];
        $allHealthy = true;

        // 1. Database
        $checks['database'] = $this->checkDatabase();
        if (!$checks['database']['healthy']) $allHealthy = false;

        // 2. Redis (queue + cache)
        $checks['redis'] = $this->checkRedis();
        if (!$checks['redis']['healthy']) $allHealthy = false;

        // 3. Storage writable
        $checks['storage'] = $this->checkStorage();
        if (!$checks['storage']['healthy']) $allHealthy = false;

        // 4. Queue size (warning if too high)
        $checks['queue'] = $this->checkQueueDepth();
        // queue health لا يفشل readiness — مجرد معلومات

        $status = $allHealthy ? 200 : 503;

        return new JsonResponse([
            'status' => $allHealthy ? 'ready' : 'not_ready',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $status);
    }

    private function checkDatabase(): array
    {
        $start = microtime(true);
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');
            return [
                'healthy' => true,
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'error' => 'connection_failed',
                'message' => mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    private function checkRedis(): array
    {
        $start = microtime(true);
        try {
            $result = Redis::ping();
            return [
                'healthy' => $result === 'PONG' || $result === true || $result === '+PONG',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'error' => 'redis_failed',
                'message' => mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    private function checkStorage(): array
    {
        try {
            $testPath = storage_path('app/.health_check');
            file_put_contents($testPath, (string)time());
            $read = file_get_contents($testPath);
            @unlink($testPath);
            return [
                'healthy' => !empty($read),
                'writable' => true,
            ];
        } catch (\Throwable $e) {
            return [
                'healthy' => false,
                'writable' => false,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    private function checkQueueDepth(): array
    {
        try {
            $size = \Queue::size('default');
            $receiptsSize = \Queue::size('receipts');
            $total = (int)$size + (int)$receiptsSize;

            // تحذير لو > 5000
            $status = $total < 1000 ? 'normal' : ($total < 5000 ? 'busy' : 'overloaded');

            return [
                'healthy' => true,
                'depth' => [
                    'default' => $size,
                    'receipts' => $receiptsSize,
                    'total' => $total,
                ],
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            return ['healthy' => true, 'error' => 'queue_size_unavailable'];
        }
    }
}
