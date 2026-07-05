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

// AMIAL-AUDIT-FIX-001: AmlDecision نُقل إلى ملفه الخاص App\Aml\AmlDecision
// (كان معرَّفاً هنا مخالفاً لـ PSR-4، فلا يُحمَّل تلقائياً — أحد أسباب تعطّل المحرّك).
