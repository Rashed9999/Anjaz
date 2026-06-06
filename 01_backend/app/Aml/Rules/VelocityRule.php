<?php

namespace App\Aml\Rules;

use App\Aml\RuleEvaluationResult;
use App\Aml\TransactionContext;
use App\Models\Aml\AmlRule;
use App\Models\Aml\AmlRuleEvaluation;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AML-001 (v1.4)
 *
 * VelocityRule — يكتشف "N معاملة في M دقيقة".
 *
 * Parameters:
 *   - max_count: عدد المعاملات الأقصى
 *   - window_minutes: النافذة الزمنية بالدقائق
 *   - count_types: (optional) أي أنواع تُحسب (CSV) — default: كل أنواع المعاملات
 *
 * مثال:
 *   max_count: 5
 *   window_minutes: 1
 *   → 5 معاملات في دقيقة = flag
 *
 * استخدام عملي: كشف automated bots / مهاجم يحاول إفراغ حساب بسرعة.
 */
class VelocityRule implements AmlRuleInterface
{
    public function getType(): string { return 'velocity'; }

    public function evaluate(TransactionContext $context, AmlRule $config): RuleEvaluationResult
    {
        $maxCount = (int)($config->parameters['max_count'] ?? 0);
        $windowMinutes = (int)($config->parameters['window_minutes'] ?? 0);

        if ($maxCount <= 0 || $windowMinutes <= 0) {
            return RuleEvaluationResult::noMatch(['note' => 'rule disabled']);
        }

        // عد المعاملات المالية للـ user في النافذة
        // نستخدم aml_rule_evaluations كـ proxy لكل المعاملات (لأن كل واحدة تُقيَّم هنا)
        // مع distinct على transaction_ulid
        $since = $context->timestamp->copy()->subMinutes($windowMinutes);

        $count = DB::table('aml_rule_evaluations')
            ->where('actor_user_id', $context->actorUserId)
            ->where('created_at', '>=', $since)
            ->distinct('transaction_ulid')
            ->count('transaction_ulid');

        // أضف الـ transaction الحالية (لم تُسجَّل بعد)
        $countWithCurrent = $count + 1;

        if ($countWithCurrent > $maxCount) {
            return RuleEvaluationResult::match(
                riskScore: (float)$config->risk_score_contribution,
                action: $config->action_on_match,
                context: [
                    'recent_count' => $count,
                    'with_current' => $countWithCurrent,
                    'max_allowed' => $maxCount,
                    'window_minutes' => $windowMinutes,
                ],
                reason: "Velocity: {$countWithCurrent} transactions in last {$windowMinutes}min (max: {$maxCount})",
            );
        }

        return RuleEvaluationResult::noMatch([
            'recent_count' => $count,
            'with_current' => $countWithCurrent,
            'threshold' => $maxCount,
        ]);
    }
}
