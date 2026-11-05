<?php

namespace App\Models;

use App\Services\MoneyService;
use Illuminate\Database\Eloquent\Model;

/**
 * AMIAL-PLAN-CATALOG-001 — باقةٌ في الكتالوج. `base_tier` رتبتُها لقدرات تطبيق أميال باي،
 * و`business_features`/`business_limits` ما تفتحه في أميال أعمال.
 */
class SubscriptionPlan extends Model
{
    protected $fillable = [
        'code', 'name_ar', 'name_en', 'description_ar', 'base_tier', 'monthly_price', 'currency',
        'business_features', 'business_limits', 'durations', 'is_active', 'is_public', 'is_system', 'sort_order',
    ];

    protected $casts = [
        'business_features' => 'array', 'business_limits' => 'array', 'durations' => 'array',
        'is_active' => 'boolean', 'is_public' => 'boolean', 'is_system' => 'boolean',
    ];

    /** سعر مدّةٍ معروضة: الشهريّ × الأشهر − الخصم، بمنزلتين. مدّةٌ غير معروضة ⇒ null. */
    public function priceFor(int $months): ?string
    {
        $d = collect($this->durations ?? [])->firstWhere('months', $months);
        if (!$d) {
            return null;
        }
        $gross = MoneyService::mul((string) $this->monthly_price, (string) $months);
        $discount = MoneyService::div(MoneyService::mul($gross, (string) ($d['discount_percent'] ?? 0)), '100');

        // سعرٌ يُدفع: منزلتان بتقريب HALF_UP.
        return MoneyService::display(MoneyService::sub($gross, $discount), 2);
    }
}
