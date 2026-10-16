<?php

namespace App\Exceptions;

use App\Support\Access\AccessConstants as A;
use Exception;
use Illuminate\Http\JsonResponse;

/**
 * CRITICAL-001-USAGE — تُطرح عند تجاوز حدّ خطّة.
 *
 * تحوي:
 *   - نوع الحدّ المُتجاوَز (monthly_operations, products, employees, ...).
 *   - القيمة الحالية والقيمة القصوى.
 *   - الخطّة الحالية + خطّة الترقية المُقترحة.
 *
 * تُترجَم إلى HTTP 402 Payment Required (الكود القياسي لـ "اشترك للمتابعة").
 */
class UsageLimitExceededException extends Exception
{
    public function __construct(
        public readonly string $limitType,
        public readonly int $currentValue,
        public readonly int $maxValue,
        public readonly string $currentPlan,
        public readonly ?string $suggestedPlan = null,
        string $message = '',
    ) {
        parent::__construct($message ?: $this->defaultMessage());
    }

    /** يقترح الخطّة التالية تلقائياً. */
    public static function suggestUpgrade(string $currentPlan): ?string
    {
        return match ($currentPlan) {
            A::PLAN_FREE => A::PLAN_BUSINESS,
            A::PLAN_BUSINESS => A::PLAN_ENTERPRISE,
            default => null,
        };
    }

    private function defaultMessage(): string
    {
        $label = match ($this->limitType) {
            'monthly_operations' => 'الحدّ الشهري لعمليات البيع',
            'products' => 'الحدّ الأقصى لعدد المنتجات',
            'employees' => 'الحدّ الأقصى لعدد الموظفين',
            'branches' => 'الحدّ الأقصى لعدد الفروع',
            'pos_devices' => 'الحدّ الأقصى لنقاط البيع',
            default => 'حدّ الخطّة',
        };

        if ($this->maxValue === 0) {
            return "{$label} غير متاح في خطّتك الحالية.";
        }

        return "وصلت إلى {$label} ({$this->currentValue}/{$this->maxValue}). "
             . 'قم بترقية الخطّة للمتابعة.';
    }

    /** يحوّل الـ exception إلى JSON Response منظّم. */
    public function toJsonResponse(): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => 'USAGE_LIMIT_EXCEEDED',
            'message' => $this->getMessage(),
            'errors' => (object)[],
            'meta' => [
                'limit_type' => $this->limitType,
                'current_value' => $this->currentValue,
                'max_value' => $this->maxValue,
                'current_plan' => $this->currentPlan,
                'current_plan_label' => A::PLAN_LABELS[$this->currentPlan] ?? $this->currentPlan,
                'suggested_plan' => $this->suggestedPlan,
                'suggested_plan_label' => $this->suggestedPlan
                    ? (A::PLAN_LABELS[$this->suggestedPlan] ?? $this->suggestedPlan)
                    : null,
                'suggested_plan_price_sar' => $this->suggestedPlan
                    ? (A::PLAN_PRICES_SAR[$this->suggestedPlan] ?? 0)
                    : null,
                'suggested_plan_price_currency' => A::PLAN_PRICE_CURRENCY,
            ],
        ], 402); // 402 Payment Required
    }
}
