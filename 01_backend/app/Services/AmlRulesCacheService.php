<?php

namespace App\Services;

/**
 * AMIAL-SCALE-001 (v1.5)
 *
 * AmlRulesCacheService — caching للـ rules النشطة.
 *
 * **المشكلة في v1.4:**
 *   كل screening يستدعي AmlRule::active()->get() من DB → ~5-15ms × عدد الـ transactions.
 *
 * **الحل v1.5:**
 *   Cache rules لمدة 5 دقائق. عند admin يعدل قاعدة → cache يُمسح.
 *
 * **التحسين الصافي:**
 *   ~10ms × 1000 req/min × 5 rules = 50,000 ms = 50 ثانية CPU/دقيقة.
 *   مع caching: ~0.1ms × 1000 = 100ms/دقيقة.
 *   **توفير 99.8% من CPU للـ rules loading.**
 */
class AmlRulesCacheService
{
    public function __construct(
        private readonly CacheService $cache,
    ) {}

    /**
     * Get all active rules (cached).
     */
    private function getActiveRules()
    {
        return $this->cache->remember('aml.rules.active', CacheService::TTL_HOT, function () {
            return \App\Models\Aml\AmlRule::active()
                ->orderBy('priority')
                ->get();
        });
    }

    /**
     * Get rules applicable to transaction type.
     */
    public function getRulesForType(string $transactionType)
    {
        $cacheKey = "aml.rules.active.{$transactionType}";
        return $this->cache->remember($cacheKey, CacheService::TTL_HOT, function () use ($transactionType) {
            return $this->getActiveRules()->filter(
                fn($rule) => $rule->appliesToType($transactionType),
            );
        });
    }

    /**
     * Invalidate cache (call this when admin modifies rules).
     */
    public function invalidate(): void
    {
        $this->cache->forgetByPrefix('aml.rules.');
    }
}
