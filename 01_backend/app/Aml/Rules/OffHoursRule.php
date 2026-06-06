<?php

namespace App\Aml\Rules;

use App\Aml\RuleEvaluationResult;
use App\Aml\TransactionContext;
use App\Models\Aml\AmlRule;

/**
 * AMIAL-AML-001 (v1.4)
 *
 * OffHoursRule — معاملات كبيرة في ساعات غير معتادة (2-5 AM مثلاً).
 *
 * Parameters:
 *   - start_hour: ساعة البداية (24h, default 2)
 *   - end_hour: ساعة النهاية (24h, default 5)
 *   - min_amount: الحد الأدنى للمبلغ (لتجنب flag معاملات صغيرة طبيعية)
 */
class OffHoursRule implements AmlRuleInterface
{
    public function getType(): string { return 'off_hours'; }

    public function evaluate(TransactionContext $context, AmlRule $config): RuleEvaluationResult
    {
        $startHour = (int)($config->parameters['start_hour'] ?? 2);
        $endHour = (int)($config->parameters['end_hour'] ?? 5);
        $minAmount = (string)($config->parameters['min_amount'] ?? '0');

        $hour = $context->getHour();
        $inWindow = $startHour <= $endHour
            ? ($hour >= $startHour && $hour < $endHour)
            : ($hour >= $startHour || $hour < $endHour); // يعبر منتصف الليل

        if (!$inWindow) return RuleEvaluationResult::noMatch();

        if (bccomp($context->amount, $minAmount, 4) < 0) {
            return RuleEvaluationResult::noMatch([
                'note' => 'in window but below min amount',
                'amount' => $context->amount,
                'min_amount' => $minAmount,
            ]);
        }

        return RuleEvaluationResult::match(
            riskScore: (float)$config->risk_score_contribution,
            action: $config->action_on_match,
            context: [
                'hour' => $hour,
                'window' => "{$startHour}-{$endHour}",
                'amount' => $context->amount,
            ],
            reason: "Off-hours transaction at {$hour}:00, amount {$context->amount}",
        );
    }
}
