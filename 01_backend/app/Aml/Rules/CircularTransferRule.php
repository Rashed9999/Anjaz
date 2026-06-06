<?php

namespace App\Aml\Rules;

use App\Aml\RuleEvaluationResult;
use App\Aml\TransactionContext;
use App\Models\Aml\AmlRule;
use Illuminate\Support\Facades\DB;

/**
 * AMIAL-AML-002 (v2.5)
 *
 * CircularTransferRule — كشف الحوالات الدائرية.
 *
 * النمط المشبوه: A → B ثم B → A (أو A→B→C→A) خلال فترة قصيرة.
 * يُستخدم غالباً لـ:
 *   - غسل الأموال (تحريك المال لإخفاء مصدره)
 *   - تضخيم حجم التعاملات وهمياً (لاستغلال عروض/عمولات)
 *
 * **الإعدادات (parameters):**
 *   - window_hours: نافذة الكشف (افتراضي 24 ساعة)
 *   - min_cycle_amount: أقل مبلغ يُعتبر مشبوهاً (افتراضي 5000)
 */
class CircularTransferRule implements AmlRuleInterface
{
    public function getType(): string { return 'circular_transfer'; }

    public function evaluate(TransactionContext $context, AmlRule $config): RuleEvaluationResult
    {
        // ينطبق فقط على التحويلات بين مستخدمين
        if ($context->counterpartyUserId === null) {
            return RuleEvaluationResult::noMatch();
        }

        $windowHours = (int)($config->parameters['window_hours'] ?? 24);
        $minAmount = (string)($config->parameters['min_cycle_amount'] ?? '5000');

        // المبلغ صغير → لا فحص
        if (bccomp($context->amount, $minAmount, 4) < 0) {
            return RuleEvaluationResult::noMatch();
        }

        $since = $context->timestamp->copy()->subHours($windowHours);
        $actor = $context->actorUserId;
        $counterparty = $context->counterpartyUserId;

        // هل الطرف الآخر حوّل للـ actor خلال النافذة؟ (دورة مباشرة A→B→A)
        $reverseTransfer = DB::table('transactions')
            ->where('from_user_id', $counterparty)
            ->where('to_user_id', $actor)
            ->where('created_at', '>=', $since)
            ->whereIn('transaction_type', [defined('SEND_MONEY') ? SEND_MONEY : 1])
            ->exists();

        if ($reverseTransfer) {
            return RuleEvaluationResult::match(
                riskScore: (float)$config->risk_score_contribution,
                action: $config->action_on_match,
                context: [
                    'pattern' => 'direct_cycle',
                    'actor' => $actor,
                    'counterparty' => $counterparty,
                    'window_hours' => $windowHours,
                ],
                reason: "Circular transfer detected: A→B→A within {$windowHours}h",
            );
        }

        // كشف الدورة غير المباشرة (A→B→C→A) — تحقق إن كان counterparty
        // جزءاً من سلسلة تعود للـ actor
        $indirectCycle = DB::table('transactions as t1')
            ->join('transactions as t2', 't1.to_user_id', '=', 't2.from_user_id')
            ->where('t1.from_user_id', $counterparty)
            ->where('t2.to_user_id', $actor)
            ->where('t1.created_at', '>=', $since)
            ->where('t2.created_at', '>=', $since)
            ->exists();

        if ($indirectCycle) {
            return RuleEvaluationResult::match(
                riskScore: (float)$config->risk_score_contribution * 0.8, // أقل ثقة قليلاً
                action: $config->action_on_match,
                context: ['pattern' => 'indirect_cycle', 'actor' => $actor],
                reason: "Indirect circular transfer detected (A→B→C→A) within {$windowHours}h",
            );
        }

        return RuleEvaluationResult::noMatch();
    }
}
