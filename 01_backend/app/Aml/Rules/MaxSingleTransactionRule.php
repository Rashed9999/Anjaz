<?php

namespace App\Aml\Rules;

use App\Aml\RuleEvaluationResult;
use App\Aml\TransactionContext;
use App\Models\Aml\AmlRule;

/**
 * AMIAL-AML-001 (v1.4)
 *
 * MaxSingleTransactionRule — يرفض/يرفع flag عند تجاوز مبلغ واحد للحد.
 *
 * Parameters:
 *   - threshold_amount: الحد الأقصى للمعاملة الواحدة
 *
 * مثال:
 *   threshold_amount: "10000.00"
 *   action_on_match: "block"
 *   → كل معاملة > 10,000 ر.ي تُرفض
 */
class MaxSingleTransactionRule implements AmlRuleInterface
{
    public function getType(): string { return 'max_single_transaction'; }

    public function evaluate(TransactionContext $context, AmlRule $config): RuleEvaluationResult
    {
        $threshold = (string)($config->parameters['threshold_amount'] ?? '0');
        if (bccomp($threshold, '0', 4) <= 0) {
            return RuleEvaluationResult::noMatch(['note' => 'rule disabled (no threshold)']);
        }

        if (bccomp($context->amount, $threshold, 4) > 0) {
            return RuleEvaluationResult::match(
                riskScore: (float)$config->risk_score_contribution,
                action: $config->action_on_match,
                context: [
                    'transaction_amount' => $context->amount,
                    'threshold' => $threshold,
                    'exceeded_by' => bcsub($context->amount, $threshold, 4),
                ],
                reason: "Transaction amount {$context->amount} exceeds threshold {$threshold}",
            );
        }

        return RuleEvaluationResult::noMatch();
    }
}
