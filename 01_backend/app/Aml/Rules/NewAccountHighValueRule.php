<?php

namespace App\Aml\Rules;

use App\Aml\RuleEvaluationResult;
use App\Aml\TransactionContext;
use App\Models\Aml\AmlRule;
use App\Models\User;

/**
 * AMIAL-AML-001 (v1.4)
 *
 * NewAccountHighValueRule — حساب جديد + معاملة كبيرة = مشبوه.
 *
 * Parameters:
 *   - max_account_age_days: عمر الحساب الذي يُعتبر "جديد"
 *   - min_amount: الحد الأدنى للمعاملة التي تُعتبر "كبيرة"
 *
 * مثال:
 *   max_account_age_days: 7
 *   min_amount: 5000
 *   → حساب جديد < 7 أيام + معاملة > 5000 = flag
 *
 * استخدام عملي: stolen identity → فتح حساب → تحويل سريع لمبلغ كبير.
 */
class NewAccountHighValueRule implements AmlRuleInterface
{
    public function getType(): string { return 'new_account_high_value'; }

    public function evaluate(TransactionContext $context, AmlRule $config): RuleEvaluationResult
    {
        $maxAccountAgeDays = (int)($config->parameters['max_account_age_days'] ?? 7);
        $minAmount = (string)($config->parameters['min_amount'] ?? '5000');

        $user = User::find($context->actorUserId);
        if (!$user || !$user->created_at) return RuleEvaluationResult::noMatch();

        $accountAgeDays = $user->created_at->diffInDays($context->timestamp);

        if ($accountAgeDays > $maxAccountAgeDays) {
            return RuleEvaluationResult::noMatch([
                'note' => 'account age above threshold',
                'account_age_days' => $accountAgeDays,
            ]);
        }

        if (bccomp($context->amount, $minAmount, 4) < 0) {
            return RuleEvaluationResult::noMatch([
                'note' => 'new account but below min amount',
            ]);
        }

        return RuleEvaluationResult::match(
            riskScore: (float)$config->risk_score_contribution,
            action: $config->action_on_match,
            context: [
                'account_age_days' => $accountAgeDays,
                'max_allowed' => $maxAccountAgeDays,
                'amount' => $context->amount,
            ],
            reason: "New account ({$accountAgeDays}d) high value transaction {$context->amount}",
        );
    }
}
