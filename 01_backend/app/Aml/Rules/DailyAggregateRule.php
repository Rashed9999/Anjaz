<?php

namespace App\Aml\Rules;

use App\Aml\RuleEvaluationResult;
use App\Aml\TransactionContext;
use App\Models\Aml\AmlRule;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AML-001 (v1.4)
 *
 * DailyAggregateRule — يكتشف "مجموع اليوم تجاوز الحد".
 *
 * Parameters:
 *   - threshold_amount: الحد الأقصى للمجموع اليومي
 *
 * مثال:
 *   threshold_amount: "50000"
 *   → لو user حول 30,000 + 25,000 في نفس اليوم = 55,000 → flag
 *
 * استخدام عملي: anti-money-laundering. غسيل الأموال غالباً يقسم مبالغ كبيرة.
 */
class DailyAggregateRule implements AmlRuleInterface
{
    public function getType(): string { return 'daily_aggregate'; }

    public function evaluate(TransactionContext $context, AmlRule $config): RuleEvaluationResult
    {
        $threshold = (string)($config->parameters['threshold_amount'] ?? '0');
        if (bccomp($threshold, '0', 4) <= 0) {
            return RuleEvaluationResult::noMatch();
        }

        $startOfDay = $context->timestamp->copy()->startOfDay();

        // مجموع المعاملات اليوم
        $todaySum = (string)DB::table('aml_rule_evaluations')
            ->where('actor_user_id', $context->actorUserId)
            ->where('created_at', '>=', $startOfDay)
            ->whereNotNull('amount')
            ->distinct('transaction_ulid')
            ->sum('amount');

        // ملاحظة: هذا يحسب فقط من aml_rule_evaluations، قد لا يشمل معاملات قديمة قبل الـ AML
        // في production: استعلام من الـ transactions table مباشرة

        // أضف المعاملة الحالية
        $totalWithCurrent = bcadd($todaySum, $context->amount, 4);

        if (bccomp($totalWithCurrent, $threshold, 4) > 0) {
            return RuleEvaluationResult::match(
                riskScore: (float)$config->risk_score_contribution,
                action: $config->action_on_match,
                context: [
                    'today_sum' => $todaySum,
                    'with_current' => $totalWithCurrent,
                    'threshold' => $threshold,
                ],
                reason: "Daily aggregate {$totalWithCurrent} exceeds {$threshold}",
            );
        }

        return RuleEvaluationResult::noMatch();
    }
}
