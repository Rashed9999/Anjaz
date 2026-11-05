<?php

namespace App\Aml\Rules;

use App\Aml\RuleEvaluationResult;
use App\Aml\TransactionContext;
use App\Models\Aml\AmlRule;
use App\Models\BusinessGateway\BusinessLink;
use App\Models\BusinessGateway\BusinessPaymentIntent;
use App\Models\BusinessGateway\BusinessRefund;

/**
 * AMIAL-BUSINESS-AML-001 — نسبة الاستردادات إلى المدفوعات لتاجرٍ في أميال أعمال.
 *
 * **النمط:** الاسترداد يُعيد المال لمن دفع، فيصير قناةً لإعادة تدوير مال: يدفع
 * حسابٌ للتاجر ثم «يُسترد» له، فيبدو المال خارجاً من نشاطٍ تجاري. تاجرٌ حقيقي
 * يُرجع نسبةً صغيرة من مبيعاته؛ نسبةٌ كبيرة في نافذة قصيرة إشارةٌ تُراجَع.
 *
 * Parameters:
 *   - window_days: النافذة (7)
 *   - max_ratio:   أعلى نسبة مقبولة (0.30)
 *   - min_amount:  لا يُحكم على مبالغ صغيرة (50000)
 */
class RefundRatioRule implements AmlRuleInterface
{
    public function getType(): string { return 'refund_ratio'; }

    public function evaluate(TransactionContext $context, AmlRule $config): RuleEvaluationResult
    {
        $windowDays = (int) ($config->parameters['window_days'] ?? 7);
        $maxRatio = (string) ($config->parameters['max_ratio'] ?? '0.30');
        $minAmount = (string) ($config->parameters['min_amount'] ?? '50000');

        $link = BusinessLink::where('merchant_user_id', $context->actorUserId)->first();
        if (!$link) {
            return RuleEvaluationResult::noMatch(['reason' => 'not_linked']);
        }
        $since = $context->timestamp->copy()->subDays($windowDays);
        $refunded = (string) BusinessRefund::where('business_link_id', $link->id)->where('status', BusinessRefund::COMPLETED)
            ->where('decided_at', '>=', $since)->sum('amount');
        $refunded = bcadd($refunded ?: '0', $context->amount, 4);
        $received = (string) BusinessPaymentIntent::where('business_link_id', $link->id)->where('status', BusinessPaymentIntent::STATUS_SUCCEEDED)
            ->where('succeeded_at', '>=', $since)->sum('amount');
        $received = $received ?: '0';

        if (bccomp($refunded, $minAmount, 4) < 0) {
            return RuleEvaluationResult::noMatch(['refunded' => $refunded, 'min_amount' => $minAmount]);
        }
        $ratio = bccomp($received, '0', 4) > 0 ? bcdiv($refunded, $received, 4) : '1.0000';
        if (bccomp($ratio, $maxRatio, 4) <= 0) {
            return RuleEvaluationResult::noMatch(['ratio' => $ratio, 'max_ratio' => $maxRatio]);
        }

        return RuleEvaluationResult::match(
            riskScore: (float) $config->risk_score_contribution,
            action: $config->action_on_match,
            context: ['ratio' => $ratio, 'refunded' => $refunded, 'received' => $received, 'window_days' => $windowDays],
            reason: "Refunds are {$ratio} of payments received in {$windowDays} days",
        );
    }
}
