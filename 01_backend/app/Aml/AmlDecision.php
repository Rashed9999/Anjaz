<?php

namespace App\Aml;

/**
 * AMIAL-AML-001 — قرار الفحص النهائي لمعاملة.
 *
 * كان هذا الصنف مفقوداً رغم أن AmlScreeningService يعتمد عليه — ما جعل
 * المحرّك كلّه غير قابل للتشغيل (fatal لو استُدعي). أُنشئ ووُصِّل ضمن
 * تدقيق ما قبل الإنتاج (AMIAL-AUDIT-FIX-001).
 *
 * أولوية الإجراءات: block > hold > flag > allow.
 *   - allow: نفّذ المعاملة بلا أثر.
 *   - flag : نفّذ المعاملة لكن سجّلها للمراجعة اللاحقة (لا يوقف المال).
 *   - hold : أوقف المعاملة مؤقتاً بانتظار مراجعة يدوية.
 *   - block: ارفض المعاملة فوراً.
 */
class AmlDecision
{
    /**
     * @param string $finalAction allow|flag|hold|block
     * @param float $totalRiskScore مجموع نقاط الخطورة للقواعد الفعلية
     * @param array $triggeredRules القواعد التي أُطلقت [{code,name,score,action,reason,...}]
     * @param string $reasonSummary ملخّص نصّي
     */
    public function __construct(
        public readonly string $finalAction,
        public readonly float $totalRiskScore,
        public readonly array $triggeredRules,
        public readonly ?string $reasonSummary = null,
    ) {}

    public function isAllowed(): bool
    {
        return $this->finalAction === 'allow';
    }

    public function isFlagged(): bool
    {
        return $this->finalAction === 'flag';
    }

    public function isHeld(): bool
    {
        return $this->finalAction === 'hold';
    }

    public function isBlocked(): bool
    {
        return $this->finalAction === 'block';
    }

    /**
     * هل يُنفَّذ المال؟ نعم لـ allow/flag (flag يُسجَّل فقط)، لا لـ hold/block.
     */
    public function shouldExecuteTransaction(): bool
    {
        return $this->isAllowed() || $this->isFlagged();
    }

    public function asArray(): array
    {
        return [
            'final_action' => $this->finalAction,
            'total_risk_score' => $this->totalRiskScore,
            'triggered_rules' => $this->triggeredRules,
            'reason_summary' => $this->reasonSummary,
        ];
    }
}
