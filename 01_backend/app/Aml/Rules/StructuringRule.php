<?php

namespace App\Aml\Rules;

use App\Aml\RuleEvaluationResult;
use App\Aml\TransactionContext;
use App\Models\Aml\AmlRule;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AML-001 (v1.4)
 *
 * StructuringRule — الـ "smurfing" أو تقسيم مبالغ كبيرة لتجنب الحدود.
 *
 * **النمط:** بدلاً من تحويل 100,000 (يفعّل MaxSingleTx)، يحول
 * 9,900 × 10 مرة (كل واحدة تحت 10,000 لكن المجموع يفضح).
 *
 * Parameters:
 *   - threshold_amount: المبلغ الذي يبدو "متعمد التجنب" (مثلاً 9,000)
 *   - window_hours: نافذة الفحص (مثلاً 24)
 *   - min_count: عدد المعاملات الأدنى (مثلاً 5)
 *
 * مثال:
 *   threshold_amount: 9000
 *   window_hours: 24
 *   min_count: 5
 *   → 5+ معاملات بين 8,000-9,999 خلال 24 ساعة = structuring suspected
 */
class StructuringRule implements AmlRuleInterface
{
    public function getType(): string { return 'structuring'; }

    public function evaluate(TransactionContext $context, AmlRule $config): RuleEvaluationResult
    {
        $threshold = (string)($config->parameters['threshold_amount'] ?? '9000');
        $windowHours = (int)($config->parameters['window_hours'] ?? 24);
        $minCount = (int)($config->parameters['min_count'] ?? 5);

        // المعاملة الحالية فقط تُحسب لو "قريبة من الـ threshold لكن تحته"
        $lowerBound = bcmul($threshold, '0.85', 4); // 85% من الـ threshold
        if (
            bccomp($context->amount, $lowerBound, 4) < 0 ||
            bccomp($context->amount, $threshold, 4) >= 0
        ) {
            return RuleEvaluationResult::noMatch();
        }

        // عد المعاملات السابقة المشبوهة (قريبة من threshold لكن تحته)
        $since = $context->timestamp->copy()->subHours($windowHours);
        $count = DB::table('aml_rule_evaluations')
            ->where('actor_user_id', $context->actorUserId)
            ->where('created_at', '>=', $since)
            ->whereBetween('amount', [(float)$lowerBound, (float)$threshold - 0.01])
            ->distinct('transaction_ulid')
            ->count('transaction_ulid');

        $totalCount = $count + 1; // مع الحالية

        if ($totalCount < $minCount) {
            return RuleEvaluationResult::noMatch([
                'pattern_count' => $totalCount,
                'min_for_flag' => $minCount,
            ]);
        }

        return RuleEvaluationResult::match(
            riskScore: (float)$config->risk_score_contribution,
            action: $config->action_on_match,
            context: [
                'pattern_count' => $totalCount,
                'window_hours' => $windowHours,
                'threshold_avoided' => $threshold,
                'lower_bound' => $lowerBound,
            ],
            reason: "Structuring pattern detected: {$totalCount} transactions near threshold {$threshold}",
        );
    }
}
