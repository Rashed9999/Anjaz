<?php

namespace App\Aml;

/**
 * AMIAL-AML-001 (v1.4)
 *
 * RuleEvaluationResult — نتيجة تقييم قاعدة واحدة.
 */
class RuleEvaluationResult
{
    public function __construct(
        public readonly bool $matched,
        public readonly float $contributedRiskScore = 0,
        public readonly string $action = 'allow', // allow | flag | hold | block
        public readonly array $context = [],
        public readonly ?string $reason = null,
    ) {}

    public static function noMatch(?array $context = null): self
    {
        return new self(matched: false, context: $context ?? []);
    }

    public static function match(
        float $riskScore,
        string $action,
        array $context = [],
        ?string $reason = null,
    ): self {
        return new self(
            matched: true,
            contributedRiskScore: $riskScore,
            action: $action,
            context: $context,
            reason: $reason,
        );
    }
}

/**
 * Final decision = aggregation of all rule results
 */
class AmlDecision
{
    public function __construct(
        public readonly string $finalAction, // allow | flag | hold | block
        public readonly float $totalRiskScore,
        public readonly array $triggeredRules, // [{code, score, action, context}, ...]
        public readonly ?string $reasonSummary = null,
    ) {}

    public function isAllowed(): bool { return $this->finalAction === 'allow'; }
    public function isFlagged(): bool { return $this->finalAction === 'flag'; }
    public function isHeld(): bool { return $this->finalAction === 'hold'; }
    public function isBlocked(): bool { return $this->finalAction === 'block'; }

    public function shouldExecuteTransaction(): bool
    {
        // allow و flag → ينفذ. hold و block → لا.
        return in_array($this->finalAction, ['allow', 'flag'], true);
    }
}
