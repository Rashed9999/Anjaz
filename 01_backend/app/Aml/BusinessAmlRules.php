<?php

namespace App\Aml;

use App\Models\Aml\AmlRule;

/**
 * AMIAL-BUSINESS-AML-001 — قواعد سحب التاجر عبر الوكيل واسترداد أميال أعمال.
 *
 * لماذا نوعان جديدان لا «cash_out»: حدود سحب العميل (حجبٌ فوق 50,000 ومراجعةٌ فوق
 * 10,000) لأفراد؛ تطبيقها على تاجر يحجز سحبه اليومي كله. فلكل نوعٍ قواعده.
 *
 * تُكتب عند الإنشاء فقط (`firstOrCreate`): ما عدّله المشغّل بعد مراجعة بياناته لا يُمحى.
 */
final class BusinessAmlRules
{
    /** @return list<array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            ['code' => 'MERCHANT_CASHOUT_LARGE', 'name_ar' => 'سحب تاجر كبير عبر وكيل', 'description_ar' => 'سحب تاجر فوق 300,000 ينتظر مراجعة الامتثال',
                'rule_type' => 'max_single_transaction', 'applies_to' => 'merchant_cash_out', 'parameters' => ['threshold_amount' => '300000'],
                'action_on_match' => 'hold', 'risk_score_contribution' => 40, 'priority' => 20, 'shadow_mode' => false],
            ['code' => 'MERCHANT_CASHOUT_VELOCITY', 'name_ar' => 'سحوبات تاجر متكررة', 'description_ar' => 'أكثر من 4 سحوبات في ساعة',
                'rule_type' => 'velocity', 'applies_to' => 'merchant_cash_out', 'parameters' => ['max_count' => 4, 'window_minutes' => 60],
                'action_on_match' => 'hold', 'risk_score_contribution' => 40, 'priority' => 30, 'shadow_mode' => false],
            ['code' => 'MERCHANT_CASHOUT_STRUCTURING', 'name_ar' => 'تجزئة سحب التاجر', 'description_ar' => 'سحوبات متكررة قرب حدّ المراجعة لتجنّبه',
                'rule_type' => 'structuring', 'applies_to' => 'merchant_cash_out', 'parameters' => ['threshold_amount' => '300000', 'window_hours' => 24, 'min_count' => 3],
                'action_on_match' => 'hold', 'risk_score_contribution' => 60, 'priority' => 25, 'shadow_mode' => false],
            ['code' => 'BUSINESS_REFUND_LARGE', 'name_ar' => 'استرداد كبير', 'description_ar' => 'استرداد فوق 200,000 ينتظر مراجعة الامتثال',
                'rule_type' => 'max_single_transaction', 'applies_to' => 'business_refund', 'parameters' => ['threshold_amount' => '200000'],
                'action_on_match' => 'hold', 'risk_score_contribution' => 40, 'priority' => 20, 'shadow_mode' => false],
            ['code' => 'BUSINESS_REFUND_RATIO', 'name_ar' => 'استردادات كثيرة مقارنة بالمبيعات', 'description_ar' => 'الاستردادات أكثر من 30% من مدفوعات 7 أيام (من 50,000)',
                'rule_type' => 'refund_ratio', 'applies_to' => 'business_refund', 'parameters' => ['window_days' => 7, 'max_ratio' => '0.30', 'min_amount' => '50000'],
                'action_on_match' => 'hold', 'risk_score_contribution' => 60, 'priority' => 15, 'shadow_mode' => false],
            ['code' => 'BUSINESS_REFUND_VELOCITY', 'name_ar' => 'استردادات متسارعة', 'description_ar' => 'أكثر من 10 استردادات في ساعة',
                'rule_type' => 'velocity', 'applies_to' => 'business_refund', 'parameters' => ['max_count' => 10, 'window_minutes' => 60],
                'action_on_match' => 'flag', 'risk_score_contribution' => 25, 'priority' => 40, 'shadow_mode' => false],
        ];
    }

    public static function install(): int
    {
        $created = 0;
        foreach (self::definitions() as $rule) {
            $row = AmlRule::firstOrCreate(['code' => $rule['code']], $rule + ['is_active' => true]);
            $created += $row->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }
}
