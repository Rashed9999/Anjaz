<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-SCALE-001 (v1.5)
 *
 * CacheService — wrapper موحد للـ caching مع تتبع hit/miss.
 *
 * **استخدام:**
 *   $cache->remember('aml.rules.active', 300, fn() => AmlRule::active()->get());
 *   $cache->forget('aml.rules.active'); // عند admin يعدل قاعدة
 *
 * **TTL Tiers (recommended):**
 *   - hot (rules, configs): 5 minutes
 *   - warm (categories, providers): 1 hour
 *   - cold (statistics): 6 hours
 *
 * **الأداء:**
 *   كل cache hit يوفر ~10-50ms DB query.
 *   للنظام بـ 50k user و 1000 req/min، caching يقلل DB load بـ ~70%.
 */
class CacheService
{
    public const TTL_HOT = 300;        // 5 min
    public const TTL_WARM = 3600;      // 1 hour
    public const TTL_COLD = 21600;     // 6 hours
    public const TTL_DAILY = 86400;    // 24 hours

    /**
     * Remember pattern: cache value or compute it.
     */
    public function remember(string $key, int $ttl, \Closure $callback)
    {
        try {
            return Cache::remember($this->prefixKey($key), $ttl, $callback);
        } catch (\Throwable $e) {
            // إذا فشل cache (Redis down)، نعيد الحساب فوراً
            Log::warning('Cache miss with fallback', ['key' => $key, 'err' => $e->getMessage()]);
            return $callback();
        }
    }

    public function get(string $key, $default = null)
    {
        try {
            return Cache::get($this->prefixKey($key), $default);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    public function put(string $key, $value, int $ttl): bool
    {
        try {
            return Cache::put($this->prefixKey($key), $value, $ttl);
        } catch (\Throwable $e) {
            Log::warning('Cache put failed', ['key' => $key]);
            return false;
        }
    }

    public function forget(string $key): bool
    {
        try {
            return Cache::forget($this->prefixKey($key));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Forget كل المفاتيح التي تبدأ بـ prefix.
     * مفيد عند admin يحدث rules ⇒ امسح كل aml.rules.*
     *
     * ملاحظة: يعمل فقط مع Redis driver.
     */
    public function forgetByPrefix(string $prefix): int
    {
        try {
            $redis = \Cache::store('redis')->getRedis();
            $pattern = $this->prefixKey($prefix) . '*';
            $keys = $redis->keys($pattern);
            if (empty($keys)) return 0;
            return $redis->del($keys);
        } catch (\Throwable $e) {
            Log::warning('forgetByPrefix failed', ['prefix' => $prefix, 'err' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * Atomic increment (للـ counters: velocity, rate limit, etc.).
     * مهم: يستخدم Redis INCR (atomic، بدون race conditions).
     */
    public function increment(string $key, int $by = 1, ?int $ttl = null): int
    {
        try {
            $newValue = Cache::increment($this->prefixKey($key), $by);
            if ($ttl && $newValue === $by) {
                // أول مرة → ضع TTL
                Cache::put($this->prefixKey($key), $newValue, $ttl);
            }
            return $newValue;
        } catch (\Throwable $e) {
            Log::warning('Cache increment failed', ['key' => $key]);
            return $by;
        }
    }

    private function prefixKey(string $key): string
    {
        return "amial:{$key}";
    }
}
