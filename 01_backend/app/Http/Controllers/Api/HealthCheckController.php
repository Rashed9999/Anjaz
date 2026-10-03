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

        // 2. Redis (فقط لو كانت البيئة تعتمده فعلاً لـ cache/queue/session —
        //    AMIAL-DEVOPS-002: بيئة الديمو تستخدم 'database' driver عمداً
        //    (بلا Redis)، وهو إعداد صالح تماماً في Laravel؛ فرض فشل
        //    readiness هنا كان يُسقط الفحص دائماً بلا سبب حقيقي)
        $usesRedis = in_array('redis', [
            config('cache.default'), config('queue.default'), config('session.driver'),
        ], true);

        if ($usesRedis) {
            $checks['redis'] = $this->checkRedis();
            if (!$checks['redis']['healthy']) $allHealthy = false;
        } else {
            $checks['redis'] = ['healthy' => true, 'skipped' => true, 'reason' => 'not_configured'];
        }

        // 3. Storage writable
        $checks['storage'] = $this->checkStorage();
        if (!$checks['storage']['healthy']) $allHealthy = false;

        // 4. Queue size (warning if too high)
        $checks['queue'] = $this->checkQueueDepth();
        // ══════════════════════════════════════════════════════════════
        // **الطابورُ لا يُسقط `readiness`** — انظر `checkQueueDepth`:
        // إسقاطُ الحاوية على طابورٍ ممتلئٍ يقتل العاملَ الذي يُفرغه.
        //
        // **لكنّه لا يُسكَت أيضاً.** فحالةٌ ثالثةٌ `degraded`: الردُّ ٢٠٠
        // فلا تُقتَل حاويةٌ سليمة، والنصُّ يقول إنّ شيئاً ليس على ما
        // يرام فلا تُقرأ الصفحةُ خضراءَ وهي ليست كذلك.
        // ══════════════════════════════════════════════════════════════
        $degraded = ($checks['queue']['healthy'] ?? true) === false;

        $status = $allHealthy ? 200 : 503;

        return new JsonResponse([
            'status' => $allHealthy ? ($degraded ? 'degraded' : 'ready') : 'not_ready',
            'timestamp' => now()->toIso8601String(),
            'checks' => $checks,
        ], $status);
    }

    /**
     * GET /railway-health — **مسبارُ النشر.**
     *
     * ══════════════════════════════════════════════════════════════════
     * AMIAL-PROD-READINESS-001 — **«نجح النشر» كانت تعني «أقلع nginx».**
     *
     * كان هذا المسارُ سطراً في `docker/nginx.conf`:
     *
     *     location = /railway-health { return 200 "ok"; }
     *
     * يجيب **قبل PHP وقبل قاعدة البيانات** — فالقاعدةُ قد تكون ساقطةً،
     * والهجرةُ لم تبدأ، والتطبيقُ يردّ ٥٠٠ على كلّ طلب، **والفحصُ أخضر**.
     * ونشرةٌ مكسورةٌ تحلّ محلَّ سليمةٍ ولا شيءَ يمنعها.
     *
     * وفي المقابل كانت `/health/readiness` مبنيّةً وصحيحةً — تفحص القاعدةَ
     * والتخزينَ والطابورَ بالعمل — **ولا يسألها النشر**. وهو نمطُ العطل
     * الأكثر تكراراً في هذا المشروع: مبنيٌّ ولا يُوصَل إليه.
     *
     * ══════════════════════════════════════════════════════════════════
     * **ولمَ نافذةُ سماحٍ بدل الجاهزيّة الصرفة؟**
     *
     * لأنّ `entrypoint.sh` يبدأ nginx فوراً ويُجري الهجرة **في الخلفيّة**.
     * فمسبارٌ صارمٌ من الثانية الأولى يُسقط **النشرةَ السليمة** ما دامت
     * الهجرةُ تجري — وحارسٌ يمنع الصوابَ يُنزَع بعد يومين.
     *
     * فالتمييزُ بين **«لم يجهز بعد»** و**«لن يجهز»**:
     *   · داخل النافذة (١٨٠ ثانيةً افتراضاً) ⇒ ٢٠٠ ومعها `warming`
     *     والثواني المتبقّية — فيمرّ الإقلاع الطبيعيّ.
     *   · بعدها ⇒ جاهزيّةٌ صرفة، و٥٠٣ تُبقي النشرةَ القديمة.
     *
     * **ولا نافذةَ حين لا يُعرف وقتُ الإقلاع** (لا ملفّ) — فالافتراضُ
     * الصرامة، لا التساهل.
     */
    public function deployProbe(): JsonResponse
    {
        $ready = $this->readiness();

        if ($ready->getStatusCode() === 200) {
            return $ready;
        }

        $grace = (int) env('AMIAL_DEPLOY_GRACE_SECONDS', 180);
        $bootedAt = @file_get_contents('/tmp/amial-boot-epoch');
        $bootedAt = is_string($bootedAt) ? (int) trim($bootedAt) : 0;

        $elapsed = $bootedAt > 0 ? (time() - $bootedAt) : PHP_INT_MAX;

        if ($elapsed >= 0 && $elapsed < $grace) {
            /** @var array<string,mixed> $body */
            $body = json_decode((string) $ready->getContent(), true) ?: [];

            return new JsonResponse([
                'status' => 'warming',
                'timestamp' => now()->toIso8601String(),
                // **تُقال الثواني المتبقّية** — فمن يقرأ السجلَّ يعرف أنّ
                // المهلةَ تنفد، ولا يظنّ الأخضرَ صحّةً.
                'grace_seconds_left' => $grace - $elapsed,
                'checks' => $body['checks'] ?? [],
            ], 200);
        }

        return $ready;
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

    /**
     * ══════════════════════════════════════════════════════════════════
     * AMIAL-HEALTH-QUEUE-001 — **`healthy => true` كانت تُردّ دائماً.**
     *
     * كانت تُردّ على `overloaded`، وتُردّ حين **يتعذّر القياسُ أصلاً**.
     * فطابورٌ فيه خمسون ألفَ مهمّةٍ عالقةٍ يُقرأ «سليماً»، وصفحةُ الصحّة
     * خضراءُ بينما لا إيصالَ يصل أحداً. **وحارسٌ يكذب أسوأ من غيابه.**
     *
     * وهذا بعينه ما يقع في تجربةِ ألفَي مستخدم: الطابورُ أوّلُ ما يمتلئ
     * تحت الحمل، وهو آخرُ ما كان يُرى.
     *
     * ══════════════════════════════════════════════════════════════════
     * **وثلاثةُ قراراتٍ تُقرأ ولا تُخمَّن:**
     *
     * ① **الامتلاءُ لا يُسقط الحاوية.** `readiness` يقرؤه `HEALTHCHECK`
     *    في الصورة، وإسقاطُ الحاوية على طابورٍ ممتلئٍ يقتل العاملَ الذي
     *    يُفرغه — **فيُعالَج العطلُ بما يزيده**. فيبقى الردُّ ٢٠٠
     *    و`healthy` كاذبةً لا، بل تُقال الحقيقةُ في `status`.
     *
     * ② **و«تعذّر القياس» ليس «سليماً»** — بل `unknown` صريحة. (القاعدةُ
     *    السابعة: «غير معروف» ليس صفراً.) وقارئٌ يرى `unknown` يسأل،
     *    ويرى `healthy` فيطمئنّ.
     *
     * ③ **والإنذارُ يُرفَع من هنا** — فصفحةٌ لا يفتحها أحدٌ ليلاً ليست
     *    رصداً. ويُرفَع على الامتلاء وعلى تعذُّر القياس معاً.
     */
    private function checkQueueDepth(): array
    {
        try {
            $size = (int) \Queue::size('default');
            $receiptsSize = (int) \Queue::size('receipts');
            $total = $size + $receiptsSize;

            $status = $total < 1000 ? 'normal' : ($total < 5000 ? 'busy' : 'overloaded');

            if ($status === 'overloaded') {
                $this->noteOps(
                    'queue.overloaded',
                    'طابورُ المهامّ ممتلئ',
                    sprintf('العمق %d مهمّة (افتراضي %d · إيصالات %d) — '
                        . 'العاملُ متوقّفٌ أو لا يلحق. ولا إيصالَ ولا إشعارَ يصل '
                        . 'حتّى يُفرَّغ.', $total, $size, $receiptsSize),
                );
            }

            return [
                // **الامتلاءُ ليس سلامةً** — وإن لم يُسقط الحاوية.
                'healthy' => $status !== 'overloaded',
                'depth' => [
                    'default' => $size,
                    'receipts' => $receiptsSize,
                    'total' => $total,
                ],
                'status' => $status,
            ];
        } catch (\Throwable $e) {
            $this->noteOps(
                'queue.unmeasurable',
                'تعذّر قياسُ عمق الطابور',
                'فلا يُعرف أممتلئٌ هو أم فارغ: ' . $e->getMessage(),
            );

            return [
                'healthy' => false,
                'status' => 'unknown',
                'error' => 'queue_size_unavailable',
            ];
        }
    }

    /** أثرُ الإنذار — **ولا يُسقط فحصَ الصحّة إن سقط هو**. */
    private function noteOps(string $key, string $title, string $detail): void
    {
        try {
            app(\App\Services\OpsAlertService::class)->note($key, $title, $detail);
        } catch (\Throwable) {
            // صفحةُ الصحّة تبقى تجيب وإن تعذّر رفعُ الإنذار — وإلّا
            // صار عطلُ الرصد يُسقط ما يرصده.
        }
    }
}
