<?php

namespace App\Services;

use App\CentralLogics\Helpers;

use App\Aml\AmlDecision;
use App\Aml\RuleEvaluationResult;
use App\Aml\Rules\AmlRuleInterface;
use App\Aml\Rules\AgentVelocityRule;
use App\Aml\Rules\CircularTransferRule;
use App\Aml\Rules\DailyAggregateRule;
use App\Aml\Rules\MaxSingleTransactionRule;
use App\Aml\Rules\NewAccountHighValueRule;
use App\Aml\Rules\OffHoursRule;
use App\Aml\Rules\StructuringRule;
use App\Aml\Rules\VelocityRule;
use App\Aml\TransactionContext;
use App\Models\Aml\AmlAlert;
use App\Models\Aml\AmlFlaggedTransaction;
use App\Models\Aml\AmlRule;
use App\Models\Aml\AmlRuleEvaluation;
use App\Models\Aml\AmlUserRiskProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * AMIAL-AML-001 (v1.4)
 *
 * AmlScreeningService — القلب الرئيسي للمحرك.
 *
 * **التدفق:**
 *   1. screen($context) → يستدعي قبل تنفيذ المعاملة المالية
 *   2. يحضر الـ active rules التي تنطبق على هذا النوع
 *   3. ينفذ كل rule عبر الـ strategy المناسب
 *   4. يجمع النتائج → AmlDecision النهائية
 *   5. يسجل كل تقييم في aml_rule_evaluations
 *   6. لو الـ decision = hold/block → يخلق AmlFlaggedTransaction + Alert
 *   7. يرجع AmlDecision للـ caller
 *
 * **الـ caller (TransactionTrait, SafePaymentService, etc.):**
 *   - يفحص $decision->shouldExecuteTransaction()
 *   - إذا false → throws AmlBlockedException أو AmlHeldException
 *   - إذا true (allow/flag) → ينفذ المعاملة (الـ flag مسجَّل للمراجعة)
 *
 * **أولوية القرارات:**
 *   block > hold > flag > allow
 *   لو أي rule = block → final = block
 *   لو أي rule = hold (ولا block) → final = hold
 *   لو أي rule = flag (ولا block/hold) → final = flag
 *   لو لا match → allow
 */
class AmlScreeningService
{
    /** @var array<string, AmlRuleInterface> */
    private array $strategies = [];

    public function __construct()
    {
        // تسجيل rule strategies
        $this->registerStrategy(new MaxSingleTransactionRule());
        $this->registerStrategy(new VelocityRule());
        $this->registerStrategy(new DailyAggregateRule());
        $this->registerStrategy(new OffHoursRule());
        $this->registerStrategy(new NewAccountHighValueRule());
        $this->registerStrategy(new StructuringRule());
        // AMIAL-AML-002 (v2.5): قواعد سلوكية جديدة
        $this->registerStrategy(new CircularTransferRule());
        $this->registerStrategy(new AgentVelocityRule());
    }

    private function registerStrategy(AmlRuleInterface $rule): void
    {
        $this->strategies[$rule->getType()] = $rule;
    }

    /**
     * فحص المعاملة قبل التنفيذ.
     *
     * يجب الاستدعاء داخل DB::transaction (وليس بعد commit).
     */
    public function screen(TransactionContext $context): AmlDecision
    {
        // 1) Check whitelist/blacklist override
        $profile = $this->getOrCreateProfile($context->actorUserId);
        if ($profile->isBlacklisted()) {
            // blacklisted = block فوري
            $decision = new AmlDecision(
                finalAction: 'block',
                totalRiskScore: 100,
                triggeredRules: [['code' => 'BLACKLIST', 'score' => 100, 'reason' => 'User blacklisted']],
                reasonSummary: 'User blacklisted',
            );
            $this->persistDecision($context, $decision);
            return $decision;
        }

        // 2) Get active rules
        $rules = $this->getApplicableRules($context->transactionType);

        if ($rules->isEmpty()) {
            $coveredType = in_array($context->transactionType,
                (array) config('amial.aml.screened_types', []), true);

            // **A monitored money flow without rules is not "clean".**
            // The former allow made a disabled/missing seed indistinguishable
            // from a successful screening and quietly turned AML off.  We
            // hold only types explicitly declared as screened; generic calls
            // to this service keep their ordinary no-rule allow behaviour.
            //
            // ══════════════════════════════════════════════════════════
            // AMIAL-AML-COVERAGE-002 — **و«فجوةُ تغطية» غيرُ «لم يُضبَط
            // النظامُ بعد».**
            //
            // **الثمنُ الذي قِيس، وأمسكته البوّابةُ قبل النشر:**
            //
            // الحجزُ أعلاه صحيحُ المبدأ (‏القاعدة السابعة: «غير معروف»
            // ليس صفراً). لكنّه كان يقع أيضاً حين يكون **جدولُ القواعد
            // فارغاً تماماً** — وذلك ليس فجوةَ تغطية، بل **بذرةً لم
            // تُشغَّل**: قاعدةٌ جديدة، أو `db:seed` سقط في الإقلاع
            // (‏و`entrypoint.sh` يبتلع فشلَه بـ`|| true`).
            //
            // وأثرُه أنّ **كلَّ حركةِ مالٍ في المنتج تُحجَز**: لا تحويل،
            // ولا سحبٌ من وكيل، ولا دفعٌ لتاجر. أي انقطاعٌ كاملٌ سببُه
            // إعدادٌ ناقصٌ لا خطرٌ مرصود. وقِيس في المجموعة: ٢٩١ اختباراً
            // ساقطاً، وكلُّ مشاهد التحويل المتوازية تُخرج `AmlHeldException`.
            //
            // **فالحالتان تفترقان:**
            //
            //   قواعدُ موجودةٌ ولا تغطّي هذا النوع  ⇒ **فجوةٌ حقيقيّة، يُحجَز**
            //   لا قاعدةَ في النظام إطلاقاً        ⇒ **النظامُ غيرُ مضبوط،
            //                                        يُرفَع عطلٌ صارخٌ ويمرّ**
            //
            // ولا يُسكَت عن الثانية: تُرفَع إلى مركز الأعطال في كلّ مرّة،
            // فيراها الأدمنُ في الشاشة بدل أن يكتشفها بتوقّف المنتج.
            // (‏«حارسٌ يقتل المنتجَ ليس حارساً — هو عطلٌ ثانٍ».)
            $noRulesAtAll = AmlRule::active()->count() === 0;

            if ($coveredType && $noRulesAtAll) {
                app(\App\Services\OpsAlertService::class)->note(
                    'aml.not_seeded',
                    'الرقابةُ على غسل الأموال غيرُ مضبوطة',
                    'لا قاعدةَ AML فعّالةٌ واحدةٌ في النظام، فالفحصُ لا يجري على '
                    . 'أيّ تدفّقٍ ماليّ. شغّل: php artisan db:seed '
                    . '--class=AmlDefaultRulesSeeder --force',
                );

                Log::critical('AML has no active rules at all — screening is off', [
                    'transaction_type' => $context->transactionType,
                ]);

                return new AmlDecision(
                    finalAction: 'allow', totalRiskScore: 0, triggeredRules: [],
                    reasonSummary: 'AML not configured: no active rules in the system',
                );
            }

            if ($coveredType && config('amial.aml.hold_when_uncovered', true)) {
                $decision = new AmlDecision(
                    finalAction: 'hold',
                    totalRiskScore: 100,
                    triggeredRules: [[
                        'code' => 'AML_COVERAGE_GAP',
                        'score' => 100,
                        'action' => 'hold',
                        'reason' => 'No active AML rule covers this screened transaction type',
                    ]],
                    reasonSummary: 'AML coverage gap: no applicable active rules',
                );
                $this->persistDecision($context, $decision);
                $this->updateProfile($profile, $context, $decision);

                Log::critical('AML coverage gap: screened transaction held', [
                    'transaction_type' => $context->transactionType,
                    'actor_user_id' => $context->actorUserId,
                    'transaction_ulid' => $context->transactionUlid,
                ]);

                return $decision;
            }

            return new AmlDecision(
                finalAction: 'allow', totalRiskScore: 0, triggeredRules: [],
                reasonSummary: 'No applicable rules',
            );
        }

        // 3) Evaluate each rule
        $triggered = [];           // القواعد الفعلية (غير shadow) التي أطلقت
        $shadowTriggered = [];     // قواعد shadow التي أطلقت (للتسجيل فقط)
        $totalScore = 0;
        $shadowScore = 0;
        $actions = [];             // actions الفعلية
        $shadowActions = [];       // actions لو لم تكن shadow

        foreach ($rules as $rule) {
            $strategy = $this->strategies[$rule->rule_type] ?? null;
            if (!$strategy) {
                Log::warning("AML: no strategy for rule type {$rule->rule_type}");
                if ($this->isCriticalRule($rule)) {
                    return $this->holdForCriticalFailure($context, $profile, $rule, 'لا توجد استراتيجية للقاعدة');
                }
                continue;
            }

            try {
                $result = $strategy->evaluate($context, $rule);
            } catch (\Throwable $e) {
                Log::error('AML rule evaluation failed', [
                    'rule' => $rule->code,
                    'error' => $e->getMessage(),
                ]);
                if ($this->isCriticalRule($rule)) {
                    return $this->holdForCriticalFailure($context, $profile, $rule, 'فشل تنفيذ القاعدة');
                }
                continue;
            }

            // سجل التقييم
            $this->recordEvaluation($context, $rule, $result);

            if ($result->matched) {
                $isShadow = (bool)($rule->shadow_mode ?? false);

                $entry = [
                    'code' => $rule->code,
                    'name' => $rule->name_ar,
                    'score' => $result->contributedRiskScore,
                    'action' => $result->action,
                    'reason' => $result->reason,
                    'context' => $result->context,
                    'shadow' => $isShadow,
                ];

                // القرار "الكامل" (لو كل القواعد فعلية) — للمقارنة في shadow log
                $shadowScore += $result->contributedRiskScore;
                $shadowActions[] = $result->action;
                $shadowTriggered[] = $entry;

                if (!$isShadow) {
                    // القاعدة فعلية → تؤثر على القرار الحقيقي
                    $totalScore += $result->contributedRiskScore;
                    $actions[] = $result->action;
                    $triggered[] = $entry;
                }
            }
        }

        // 3.5) سجل قرار الـ shadow (ماذا كان سيحدث لو كل القواعد فعلية)
        $wouldBeAction = $this->resolveFinalAction($shadowActions);
        if ($wouldBeAction !== 'allow' && $wouldBeAction !== $this->resolveFinalAction($actions)) {
            $this->recordShadowDecision($context, $wouldBeAction, $shadowScore, $shadowTriggered);
        }

        // 4) Determine final decision
        $finalAction = $this->resolveFinalAction($actions);

        $decision = new AmlDecision(
            finalAction: $finalAction,
            totalRiskScore: $totalScore,
            triggeredRules: $triggered,
            reasonSummary: !empty($triggered)
                ? "Triggered: " . implode(', ', array_column($triggered, 'code'))
                : 'No matches',
        );

        // 5) Persist if non-allow
        $this->persistDecision($context, $decision);

        // 6) Update profile
        $this->updateProfile($profile, $context, $decision);

        return $decision;
    }

    /**
     * أولوية: block > hold > flag > allow
     */
    private function resolveFinalAction(array $actions): string
    {
        if (in_array('block', $actions, true)) return 'block';
        if (in_array('hold', $actions, true)) return 'hold';
        if (in_array('flag', $actions, true)) return 'flag';
        return 'allow';
    }

    /**
     * AMIAL-AML-002 (v2.5): تسجيل ما كان النظام سيقرره لو لم تكن القواعد shadow.
     * يُستخدم لضبط القواعد قبل تفعيلها فعلياً.
     */
    private function recordShadowDecision(
        TransactionContext $context,
        string $wouldBeAction,
        float $totalScore,
        array $triggeredRules,
    ): void {
        try {
            DB::table('aml_shadow_decisions')->insert([
                'user_id' => $context->actorUserId,
                'transaction_ulid' => $context->transactionUlid,
                'transaction_type' => $context->transactionType,
                'amount' => (float)$context->amount,
                'would_be_action' => $wouldBeAction,
                'total_risk_score' => $totalScore,
                'triggered_rules' => json_encode($triggeredRules, JSON_UNESCAPED_UNICODE),
                'actual_action' => 'allow', // في shadow، الفعلي دائماً allow
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Shadow decision log failed', ['err' => $e->getMessage()]);
        }
    }


    /**
     * @return \Illuminate\Database\Eloquent\Collection<AmlRule>
     */
    private function getApplicableRules(string $transactionType)
    {
        return AmlRule::active()
            ->orderBy('priority')
            ->get()
            ->filter(fn($rule) => $rule->appliesToType($transactionType));
    }

    private function recordEvaluation(TransactionContext $context, AmlRule $rule, RuleEvaluationResult $result): void
    {
        // إذا كان التدفق مالياً وفي معاملة قاعدة بيانات، ففشل هذا السجل يجب
        // أن يفشل العملية معها. سجّل اختفى بعد Hold/Block أسوأ من منع ظاهر.
        AmlRuleEvaluation::create([
            'transaction_ulid' => $context->transactionUlid,
            'transaction_type' => $context->transactionType,
            'actor_user_id' => $context->actorUserId,
            'counterparty_user_id' => $context->counterpartyUserId,
            'amount' => $context->amount,
            'rule_id' => $rule->id,
            'rule_code' => $rule->code,
            'matched' => $result->matched,
            'contributed_risk_score' => $result->contributedRiskScore,
            'evaluation_context' => $result->context,
            'created_at' => $context->timestamp,
        ]);
    }

    private function persistDecision(TransactionContext $context, AmlDecision $decision): void
    {
        if ($decision->isAllowed()) return;

        DB::transaction(function () use ($context, $decision): void {
            // إنشاء flagged transaction record
            $flagged = AmlFlaggedTransaction::create([
                'flag_ulid' => (string) Str::ulid(),
                'transaction_ulid' => $context->transactionUlid,
                'transaction_type' => $context->transactionType,
                'actor_user_id' => $context->actorUserId,
                'counterparty_user_id' => $context->counterpartyUserId,
                'amount' => $context->amount,
                'total_risk_score' => $decision->totalRiskScore,
                'triggered_rules' => $decision->triggeredRules,
                'initial_decision' => $decision->finalAction,
                'current_status' => $decision->isFlagged() ? 'auto_resolved' : 'pending_review',
                'transaction_executed' => $decision->isFlagged(), // flag = ينفذ
            ]);

            // alert للـ admin إذا حساسية عالية
            if ($decision->isBlocked() || $decision->isHeld()) {
                $this->createAlert($flagged, $decision);
            }
        });
    }

    private function isCriticalRule(AmlRule $rule): bool
    {
        return in_array($rule->action_on_match, ['hold', 'block'], true)
            || str_contains(strtoupper((string) $rule->code), 'SANCTION')
            || str_contains(strtoupper((string) $rule->code), 'HARD');
    }

    private function holdForCriticalFailure(
        TransactionContext $context,
        AmlUserRiskProfile $profile,
        AmlRule $rule,
        string $failure,
    ): AmlDecision {
        $decision = new AmlDecision(
            finalAction: 'hold', totalRiskScore: 100,
            triggeredRules: [[
                'code' => 'AML_CRITICAL_RULE_FAILURE', 'action' => 'hold', 'score' => 100,
                'reason' => $failure . ': ' . $rule->code,
            ]],
            reasonSummary: 'Critical AML control failure: ' . $rule->code,
        );
        $this->persistDecision($context, $decision);
        $this->updateProfile($profile, $context, $decision);

        return $decision;
    }

    private function createAlert(AmlFlaggedTransaction $flagged, AmlDecision $decision): void
    {
        $severity = match (true) {
            $decision->totalRiskScore >= 80 => 'critical',
            $decision->totalRiskScore >= 50 => 'high',
            $decision->totalRiskScore >= 25 => 'medium',
            default => 'low',
        };

        $codes = array_column($decision->triggeredRules, 'code');
        AmlAlert::create([
            'alert_ulid' => (string) Str::ulid(),
            'alert_code' => $codes[0] ?? 'AML_GENERIC',
            'severity' => $severity,
            'subject_type' => 'flagged_transaction',
            'subject_id' => $flagged->id,
            'title_ar' => "معاملة مشبوهة (نقاط: {$decision->totalRiskScore})",
            'message_ar' => "تم {$decision->finalAction} عملية بقيمة " . Helpers::money($flagged->amount) . " ر.ي. القواعد المُفعَّلة: " . implode(', ', $codes),
            'context' => [
                'flag_id' => $flagged->id,
                'rules' => $codes,
                'amount' => $flagged->amount,
            ],
            'status' => 'open',
        ]);
    }

    private function getOrCreateProfile(int $userId): AmlUserRiskProfile
    {
        return AmlUserRiskProfile::firstOrCreate(
            ['user_id' => $userId],
            [
                'current_risk_score' => 0,
                'risk_level' => 'low',
                'manual_override' => 'none',
            ],
        );
    }

    private function updateProfile(AmlUserRiskProfile $profile, TransactionContext $context, AmlDecision $decision): void
    {
        try {
            DB::transaction(function () use ($profile, $context, $decision) {
                $locked = AmlUserRiskProfile::lockForUpdate()->find($profile->user_id);
                if (!$locked) return;

                $locked->total_transactions++;
                $locked->lifetime_volume = (string)bcadd((string)$locked->lifetime_volume, $context->amount, 4);

                if (!$decision->isAllowed()) {
                    $locked->total_flagged++;
                    $locked->last_flagged_at = now();
                    if ($decision->isBlocked()) $locked->total_blocked++;
                    if ($decision->isHeld()) $locked->total_held++;
                }

                // EMA للـ risk score (نسبة 0.3 للحالي، 0.7 للجديد)
                if (!$decision->isAllowed()) {
                    $oldScore = (float)$locked->current_risk_score;
                    $newScore = ($oldScore * 0.7) + ($decision->totalRiskScore * 0.3);
                    $locked->current_risk_score = round($newScore, 2);
                } else {
                    // decay slightly when allowed
                    $locked->current_risk_score = round((float)$locked->current_risk_score * 0.95, 2);
                }

                // risk level
                $locked->risk_level = match (true) {
                    $locked->current_risk_score >= 70 => 'critical',
                    $locked->current_risk_score >= 40 => 'high',
                    $locked->current_risk_score >= 20 => 'medium',
                    default => 'low',
                };

                $locked->last_evaluation_at = now();
                $locked->save();
            });
        } catch (\Throwable $e) {
            Log::warning('AML profile update failed', ['err' => $e->getMessage()]);
        }
    }
}
