<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * AMIAL-RATELIMIT-001 (v1.0-D)
 *
 * PerUserRateLimit — حد لكل user (لا لكل IP).
 *
 * **لماذا per-user وليس per-IP؟**
 *   - الـ user قد يستخدم mobile network مع NAT (آلاف يشاركون IP)
 *   - الـ attacker قد يدور IPs عبر VPN
 *   - حد user.id أكثر دقة لحماية الـ user وحماية الـ backend معاً
 *
 * **استخدام:**
 *   Route::post('/send-money', [...])
 *       ->middleware('amial.rate-limit:send_money,10,1');     // 10/min
 *
 *   Route::post('/login', [...])
 *       ->middleware('amial.rate-limit:login,5,1,true');      // 5/min by IP (لا user_id)
 *
 * **Parameters:**
 *   action_name : اسم العملية (للـ key)
 *   max_attempts: الحد الأقصى
 *   per_minutes : النافذة بالدقائق
 *   use_ip      : true → IP-based (لـ login/signup), false → user-based
 */
class PerUserRateLimit
{
    public function handle(
        Request $request,
        Closure $next,
        string $action = 'default',
        int $maxAttempts = 60,
        int $perMinutes = 1,
        bool|string $useIp = false,
    ): Response {
        // معاملات الـ middleware نصّية؛ "false" نصاً = true في PHP — نحلّلها صحيحاً
        $useIp = filter_var($useIp, FILTER_VALIDATE_BOOLEAN);
        $key = $this->resolveKey($request, $action, $useIp);
        $decaySeconds = $perMinutes * 60;

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = RateLimiter::availableIn($key);

            return new JsonResponse([
                'success' => false,
                'code' => 'RATE_LIMITED',
                'message' => "تجاوزت الحد المسموح. حاول بعد {$retryAfter} ثانية.",
                'errors' => (object)[],
                'meta' => [
                    'action' => $action,
                    'max_attempts' => $maxAttempts,
                    'window_seconds' => $decaySeconds,
                    'retry_after_seconds' => $retryAfter,
                ],
            ], 429, [
                'Retry-After' => (string)$retryAfter,
                'X-RateLimit-Limit' => (string)$maxAttempts,
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string)(time() + $retryAfter),
            ]);
        }

        RateLimiter::hit($key, $decaySeconds);

        $response = $next($request);

        // أضف rate limit headers للـ response
        $remaining = max(0, $maxAttempts - RateLimiter::attempts($key));
        if (method_exists($response, 'headers')) {
            $response->headers->set('X-RateLimit-Limit', (string)$maxAttempts);
            $response->headers->set('X-RateLimit-Remaining', (string)$remaining);
        }

        return $response;
    }

    private function resolveKey(Request $request, string $action, bool $useIp): string
    {
        $userId = \Illuminate\Support\Facades\Auth::id() ?? optional($request->user())->id ?? optional($request->user('api'))->id ?? optional(auth('api')->user())->id;
        if ($useIp || !$userId) {
            return "amial_rl:{$action}:ip:" . $request->ip();
        }
        return "amial_rl:{$action}:user:" . $userId;
    }
}
