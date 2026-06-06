<?php

namespace App\Aml\Rules;

use App\Aml\RuleEvaluationResult;
use App\Aml\TransactionContext;
use App\Models\Aml\AmlRule;

/**
 * AMIAL-AML-001 (v1.4)
 *
 * AmlRuleInterface — كل rule strategy يطبقه.
 */
interface AmlRuleInterface
{
    /**
     * يقيم القاعدة على transaction.
     *
     * @param TransactionContext $context
     * @param AmlRule $config إعدادات القاعدة (parameters, threshold, etc.)
     * @return RuleEvaluationResult
     */
    public function evaluate(TransactionContext $context, AmlRule $config): RuleEvaluationResult;

    /**
     * نوع القاعدة (يطابق aml_rules.rule_type).
     */
    public function getType(): string;
}
