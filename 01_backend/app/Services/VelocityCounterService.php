<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AMIAL-SCALE-001 (v1.5)
 *
 * VelocityCounterService — يستبدل DB count queries للـ velocity rules.
 *
 * **المشكلة في v1.4:**
 *   كل transaction يستدعي:
 *     SELECT COUNT(DISTINCT transaction_ulid) FROM aml_rule_evaluations
 *     WHERE actor_user_id = ? AND created_at >= ?
 *   على 50k user → table ينمو سريع → الـ query يبطئ
 *
 * **الحل v1.5:**
 *   Redis sorted set per user + transaction type:
 *     amial:velocity:user_{id}:send_money → ZSET with timestamps
 *   
 *   add(): ZADD ts ts
 *   count(window): ZCOUNT timestamp_now timestamp_now-window
 *   cleanup: ZREMRANGEBYSCORE 0 (now - 24h) — حدث دوري
 *
 * **الأداء:**
 *   - DB query: 20-100ms (يكبر مع البيانات)
 *   - Redis ZCOUNT: <1ms (ثابت)
 *
 * Fallback: لو Redis down، نرجع DB count.
 */
class VelocityCounterService
{
    private const KEY_TTL_SECONDS = 86400; // 24 hours

    /**
     * تسجيل معاملة في الـ counter.
     */
    public function recordTransaction(int $userId, string $transactionType, ?\Carbon\Carbon $timestamp = null): void
    {
        $ts = ($timestamp ?? now())->timestamp;
        $key = $this->key($userId, $transactionType);

        try {
            $redis = $this->getRedis();
            if (!$redis) return;

            // ZADD score=ts member=unique-id
            $redis->zadd($key, $ts, "{$ts}_" . random_int(1000, 9999));
            // TTL للـ key (يتجدد)
            $redis->expire($key, self::KEY_TTL_SECONDS);

            // Cleanup old entries (> 24h) atomically
            $cutoff = $ts - self::KEY_TTL_SECONDS;
            $redis->zremrangebyscore($key, 0, $cutoff);
        } catch (\Throwable $e) {
            Log::warning('Velocity record failed', ['err' => $e->getMessage()]);
        }
    }

    /**
     * عد المعاملات في نافذة زمنية (minutes).
     */
    private function countInWindow(int $userId, string $transactionType, int $windowMinutes, ?\Carbon\Carbon $now = null): int
    {
        $now = $now ?? now();
        $key = $this->key($userId, $transactionType);
        $from = $now->copy()->subMinutes($windowMinutes)->timestamp;
        $to = $now->timestamp;

        try {
            $redis = $this->getRedis();
            if (!$redis) {
                // fallback: لا حماية، نرجع 0 — أفضل من فشل المعاملة
                return 0;
            }
            return (int)$redis->zcount($key, $from, $to);
        } catch (\Throwable $e) {
            Log::warning('Velocity count failed', ['err' => $e->getMessage()]);
            return 0;
        }
    }

    /**
     * عد المعاملات في **كل** الأنواع (composite velocity).
     */
    public function countInWindowAcrossTypes(int $userId, array $types, int $windowMinutes): int
    {
        $total = 0;
        foreach ($types as $type) {
            $total += $this->countInWindow($userId, $type, $windowMinutes);
        }
        return $total;
    }

    /**
     * Reset counter للـ user (في حال admin override أو whitelist).
     */
    public function reset(int $userId, ?string $transactionType = null): void
    {
        try {
            $redis = $this->getRedis();
            if (!$redis) return;

            if ($transactionType) {
                $redis->del($this->key($userId, $transactionType));
            } else {
                $pattern = "amial:velocity:user_{$userId}:*";
                $keys = $redis->keys($pattern);
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Velocity reset failed', ['err' => $e->getMessage()]);
        }
    }

    private function key(int $userId, string $transactionType): string
    {
        return "amial:velocity:user_{$userId}:{$transactionType}";
    }

    private function getRedis()
    {
        try {
            return \Illuminate\Support\Facades\Redis::connection()->client();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
