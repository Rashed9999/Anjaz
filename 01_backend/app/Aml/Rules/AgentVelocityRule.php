<?php

namespace App\Aml\Rules;

use App\Aml\RuleEvaluationResult;
use App\Aml\TransactionContext;
use App\Models\Aml\AmlRule;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AML-002 (v2.5)
 *
 * AgentVelocityRule — كشف نشاط وكيل غير طبيعي.
 *
 * النمط المشبوه (من وثيقة "رصد"):
 *   "إذا قام الوكيل بعمليات Cash-In لعدد كبير من العملاء بمبالغ متطابقة
 *    خلال دقائق، يتدخل رصد."
 *
 * هذا قد يشير إلى:
 *   - تبييض أموال موزّع على حسابات وهمية
 *   - استغلال عمولات بعمليات صورية
 *   - حساب وكيل مخترَق
 *
 * **الإعدادات:**
 *   - window_minutes: النافذة (افتراضي 10 دقائق)
 *   - max_identical_count: عدد العمليات المتطابقة المسموح (افتراضي 5)
 */
class AgentVelocityRule implements AmlRuleInterface
{
    public function getType(): string { return 'agent_velocity'; }

    public function evaluate(TransactionContext $context, AmlRule $config): RuleEvaluationResult
    {
        // ينطبق فقط على cash-in من وكيل
        if ($context->transactionType !== 'agent_cash_in') {
            return RuleEvaluationResult::noMatch();
        }

        $windowMinutes = (int)($config->parameters['window_minutes'] ?? 10);
        $maxIdentical = (int)($config->parameters['max_identical_count'] ?? 5);

        $since = $context->timestamp->copy()->subMinutes($windowMinutes);
        $agentId = $context->actorUserId;

        // عد عمليات cash-in من نفس الوكيل بنفس المبلغ خلال النافذة
        $identicalCount = DB::table('transactions')
            ->where('from_user_id', $agentId)
            ->where('amount', (float)$context->amount)
            ->where('created_at', '>=', $since)
            ->whereIn('transaction_type', [defined('CASH_OUT') ? CASH_OUT : 3])
            ->count();

        $totalWithCurrent = $identicalCount + 1;

        if ($totalWithCurrent > $maxIdentical) {
            return RuleEvaluationResult::match(
                riskScore: (float)$config->risk_score_contribution,
                action: $config->action_on_match,
                context: [
                    'agent_id' => $agentId,
                    'identical_amount' => $context->amount,
                    'count' => $totalWithCurrent,
                    'window_minutes' => $windowMinutes,
                ],
                reason: "Agent velocity anomaly: {$totalWithCurrent} identical cash-ins of {$context->amount} in {$windowMinutes}min",
            );
        }

        // فحص ثانوي: عدد كبير من العملاء المختلفين خلال النافذة
        $distinctCustomers = DB::table('transactions')
            ->where('from_user_id', $agentId)
            ->where('created_at', '>=', $since)
            ->whereIn('transaction_type', [defined('CASH_OUT') ? CASH_OUT : 3])
            ->distinct('to_user_id')
            ->count('to_user_id');

        $maxDistinctCustomers = (int)($config->parameters['max_distinct_customers'] ?? 15);
        if ($distinctCustomers > $maxDistinctCustomers) {
            return RuleEvaluationResult::match(
                riskScore: (float)$config->risk_score_contribution * 0.7,
                action: $config->action_on_match,
                context: ['agent_id' => $agentId, 'distinct_customers' => $distinctCustomers],
                reason: "Agent serving {$distinctCustomers} customers in {$windowMinutes}min (threshold: {$maxDistinctCustomers})",
            );
        }

        return RuleEvaluationResult::noMatch();
    }
}
