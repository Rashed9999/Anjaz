<?php

namespace App\Services;

use App\Exceptions\UsageLimitExceededException;
use App\Models\MerchantProfile;
use App\Models\MerchantUsageCounter;
use App\Models\PharmacyProduct;
use App\Models\User;
use App\Models\WholesaleProduct;
use App\Support\Access\AccessConstants as A;
use Illuminate\Support\Facades\DB;

/**
 * CRITICAL-001-USAGE — خدمة فرض حدود الخطط.
 *
 * تتعامل مع نوعين من الحدود:
 *
 * 1. **حدود ديناميكية** (counter table): monthly_operations.
 *    - increment ذرّي عبر UPSERT.
 *    - فحص قبل العملية + زيادة بعدها.
 *
 * 2. **حدود ثابتة** (live count): products, employees, branches, pos_devices.
 *    - فحص COUNT(*) من الجدول المعنيّ قبل الإضافة.
 *
 * تطرح UsageLimitExceededException عند التجاوز.
 */
class UsageLimitService
{
    /**
     * فحص + تسجيل عملية. إن تجاوزت الحدّ، يطرح Exception.
     *
     * استخدام نموذجي:
     *   UsageLimitService::recordSaleOperation($user);
     *   ← يفحص الحدّ الشهري + يزيد العدّاد.
     *
     * @throws UsageLimitExceededException
     */
    public function recordSaleOperation(User $merchant): MerchantUsageCounter
    {
        return $this->recordOperation(
            $merchant,
            MerchantUsageCounter::TYPE_SALE_OPERATION,
            'monthly_operations',
        );
    }

    /**
     * تسجيل عام لأي counter_type مع فحص الحدّ.
     */
    private function recordOperation(
        User $merchant,
        string $counterType,
        string $limitKey,
    ): MerchantUsageCounter {
        $plan = $this->planFor($merchant);
        $limit = A::PLAN_LIMITS[$plan][$limitKey] ?? 0;

        // -1 = غير محدود → سجّل بدون فحص
        // 0 = ممنوع → ارفض فوراً
        if ($limit === 0) {
            throw new UsageLimitExceededException(
                limitType: $limitKey,
                currentValue: 0,
                maxValue: 0,
                currentPlan: $plan,
                suggestedPlan: UsageLimitExceededException::suggestUpgrade($plan),
            );
        }

        $periodKey = MerchantUsageCounter::currentMonthKey();

        // فحص قيمة العدّاد الحالية (إن حدّ محدّد)
        if ($limit > 0) {
            $current = $this->getCounter($merchant->id, $counterType, $periodKey);
            if ($current >= $limit) {
                throw new UsageLimitExceededException(
                    limitType: $limitKey,
                    currentValue: $current,
                    maxValue: $limit,
                    currentPlan: $plan,
                    suggestedPlan: UsageLimitExceededException::suggestUpgrade($plan),
                );
            }
        }

        // INCREMENT ذرّي عبر UPSERT
        return $this->incrementCounterAtomic($merchant->id, $counterType, $periodKey);
    }

    /**
     * فحص فقط دون زيادة العدّاد (لـ Middleware قبل عملية).
     *
     * @throws UsageLimitExceededException
     */
    public function assertCanPerformSale(User $merchant): void
    {
        $plan = $this->planFor($merchant);
        $limit = A::PLAN_LIMITS[$plan]['monthly_operations'] ?? 0;

        if ($limit === 0) {
            throw new UsageLimitExceededException(
                limitType: 'monthly_operations',
                currentValue: 0, maxValue: 0,
                currentPlan: $plan,
                suggestedPlan: UsageLimitExceededException::suggestUpgrade($plan),
            );
        }
        if ($limit < 0) return; // غير محدود

        $periodKey = MerchantUsageCounter::currentMonthKey();
        $current = $this->getCounter(
            $merchant->id, MerchantUsageCounter::TYPE_SALE_OPERATION, $periodKey
        );

        if ($current >= $limit) {
            throw new UsageLimitExceededException(
                limitType: 'monthly_operations',
                currentValue: $current, maxValue: $limit,
                currentPlan: $plan,
                suggestedPlan: UsageLimitExceededException::suggestUpgrade($plan),
            );
        }
    }

    /**
     * فحص حدود ثابتة (live count من DB).
     * يُستدعى قبل إضافة منتج/موظف/فرع.
     *
     * @throws UsageLimitExceededException
     */
    public function assertCanAddProduct(User $merchant, string $sector): void
    {
        $plan = $this->planFor($merchant);
        $max = A::PLAN_LIMITS[$plan]['products'] ?? 0;

        if ($max < 0) return; // غير محدود
        if ($max === 0) {
            throw new UsageLimitExceededException(
                limitType: 'products',
                currentValue: 0, maxValue: 0,
                currentPlan: $plan,
                suggestedPlan: UsageLimitExceededException::suggestUpgrade($plan),
            );
        }

        $current = $this->countProducts($merchant, $sector);
        if ($current >= $max) {
            throw new UsageLimitExceededException(
                limitType: 'products',
                currentValue: $current, maxValue: $max,
                currentPlan: $plan,
                suggestedPlan: UsageLimitExceededException::suggestUpgrade($plan),
            );
        }
    }

    /**
     * snapshot الاستخدام الحالي (لشاشة "خطّتي").
     */
    public function usageSnapshot(User $merchant): array
    {
        $plan = $this->planFor($merchant);
        $limits = A::PLAN_LIMITS[$plan] ?? A::PLAN_LIMITS[A::PLAN_FREE];

        $monthOps = $this->getCounter(
            $merchant->id, MerchantUsageCounter::TYPE_SALE_OPERATION,
            MerchantUsageCounter::currentMonthKey()
        );

        return [
            'plan' => $plan,
            'plan_label' => A::PLAN_LABELS[$plan] ?? $plan,
            'period' => MerchantUsageCounter::currentMonthKey(),
            'monthly_operations' => [
                'current' => $monthOps,
                'max' => $limits['monthly_operations'],
                'is_unlimited' => $limits['monthly_operations'] < 0,
                'percentage' => $this->pctOf($monthOps, $limits['monthly_operations']),
            ],
            'products' => [
                'current' => $this->countProducts($merchant, 'auto'),
                'max' => $limits['products'],
                'is_unlimited' => $limits['products'] < 0,
            ],
            'employees' => [
                'current' => $this->countEmployees($merchant),
                'max' => $limits['employees'],
                'is_unlimited' => $limits['employees'] < 0,
            ],
            'archive_days' => $limits['archive_days'],
        ];
    }

    // ============ Private Helpers ============

    /** خطّة التاجر الحالية (مع احتساب انتهاء الاشتراك). */
    private function planFor(User $merchant): string
    {
        $profile = MerchantProfile::where('user_id', $merchant->id)->first();
        if (!$profile) return A::PLAN_FREE;

        $plan = A::canonicalPlan($profile->subscription_plan);

        // فحص انتهاء — يعود لـ FREE
        if ($plan !== A::PLAN_FREE
            && $profile->subscription_expires_at !== null
            && $profile->subscription_expires_at->isPast()) {
            return A::PLAN_FREE;
        }
        return $plan;
    }

    private function getCounter(int $merchantId, string $type, string $periodKey): int
    {
        return (int) MerchantUsageCounter::where('merchant_user_id', $merchantId)
            ->where('counter_type', $type)
            ->where('period_key', $periodKey)
            ->value('count') ?? 0;
    }

    /**
     * INCREMENT ذرّي عبر INSERT ON DUPLICATE KEY UPDATE.
     * آمن مع concurrent requests.
     */
    private function incrementCounterAtomic(
        int $merchantId, string $type, string $periodKey,
    ): MerchantUsageCounter {
        $now = now();

        // MySQL/MariaDB: INSERT ... ON DUPLICATE KEY UPDATE
        DB::statement(
            'INSERT INTO merchant_usage_counters
                (merchant_user_id, counter_type, period_key, count, last_incremented_at, created_at, updated_at)
             VALUES (?, ?, ?, 1, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                count = count + 1,
                last_incremented_at = VALUES(last_incremented_at),
                updated_at = VALUES(updated_at)',
            [$merchantId, $type, $periodKey, $now, $now, $now]
        );

        return MerchantUsageCounter::where('merchant_user_id', $merchantId)
            ->where('counter_type', $type)
            ->where('period_key', $periodKey)
            ->first();
    }

    /**
     * **عامٌّ عمداً — فهذا هو التعريفُ الوحيدُ لعدّ الأصناف.**
     *
     * `EntitlementService::usageFor` كان يعدّ `merchant_products` وحدَه
     * مهما كان القطاع، فافترق عدّادان لحدٍّ واحد. وقِيس بالتشغيل:
     * تاجرُ جملةٍ له صنفٌ واحد ⇒ ① يقول 1 و② يقول 0.
     */
    public function countProducts(User $merchant, string $sector): int
    {
        // 'auto' = حدّد القطاع من business_type
        if ($sector === 'auto') {
            $profile = MerchantProfile::where('user_id', $merchant->id)->first();
            $sector = $profile?->business_type ?? '';
        }

        // ══════════════════════════════════════════════════════════════
        //  AMIAL-PRODUCT-QUOTA-002 — **كلُّ قطاعٍ يُعدّ، و«غير معروف» ليس صفراً.**
        //
        //  **الثمن الذي دُفع:** قال صاحبُ المشروع «عدد المنتجات مرتبطٌ
        //  بالباقات»، فوُضعت البوّابةُ على باب الكاشير. ثمّ كشفت مراجعةٌ
        //  آليّةٌ أنّ ثلاثةَ أبوابٍ أُخَر بلا حدّ — **والأعمقُ أنّ هذا
        //  العدّاد نفسَه لا يعرف إلّا قطاعين.**
        //
        //  و`default => 0` تعني: قطاعٌ لا يعرفه العدّادُ **يُقرأ بلا
        //  منتجاتٍ فيمرّ أبداً**. فصفرٌ هنا يُقرأ «فحصنا فلم نجد»، وهو في
        //  الحقيقة «لم نفحص» (القاعدة السابعة).
        //
        //  فصار كلُّ قطاعٍ يُعدّ من جدوله، و**المجهولُ يقع على الجدول
        //  الأساسيّ** لا على صفرٍ صامت: تاجرٌ بقطاعٍ لم يُسجَّل بعدُ يُحاسَب
        //  على أصنافه في `merchant_products` كأيّ تاجر.
        // ══════════════════════════════════════════════════════════════
        return match ($sector) {
            'wholesale' => $this->countWholesaleProducts($merchant->id),
            'pharmacy' => $this->countPharmacyProducts($merchant->id),
            'fuel', 'fuel_station' => $this->countFuelProducts($merchant->id),
            default => $this->countCoreProducts($merchant->id),
        };
    }

    /** أصنافُ الوقود تحت محطّات التاجر كلِّها. */
    private function countFuelProducts(int $merchantId): int
    {
        $stationIds = \App\Models\FuelStation::where('merchant_user_id', $merchantId)
            ->pluck('id');

        if ($stationIds->isEmpty()) {
            return 0;
        }

        return \App\Models\FuelProduct::whereIn('station_id', $stationIds)
            ->where('is_active', true)->count();
    }

    /**
     * الجدولُ الأساسيّ — للتجزئة ولكلّ قطاعٍ لا جدولَ خاصَّ له.
     *
     * **والأبُ لا يُحسب مع أبنائه**: قميصٌ بأربعة ألوانٍ أربعةُ أصنافٍ لا
     * خمسة — كما في `EntitlementService::usageFor` حرفاً بحرف، فالبابان
     * يجب أن يُعطيا الرقمَ نفسَه.
     */
    private function countCoreProducts(int $merchantId): int
    {
        return \App\Models\MerchantProduct::where('merchant_user_id', $merchantId)
            ->where('is_variant_parent', false)
            ->where('is_active', true)
            ->count();
    }

    private function countWholesaleProducts(int $merchantId): int
    {
        $bizId = \App\Models\WholesaleBusiness::where('merchant_user_id', $merchantId)->value('id');
        if (!$bizId) return 0;
        return WholesaleProduct::where('business_id', $bizId)->where('is_active', true)->count();
    }

    private function countPharmacyProducts(int $merchantId): int
    {
        $pharmacyId = \App\Models\Pharmacy::where('merchant_user_id', $merchantId)->value('id');
        if (!$pharmacyId) return 0;
        return PharmacyProduct::where('pharmacy_id', $pharmacyId)->where('is_active', true)->count();
    }

    private function countEmployees(User $merchant): int
    {
        return \App\Models\PosUser::where('merchant_user_id', $merchant->id)
            ->where('is_active', true)->count();
    }

    private function pctOf(int $current, int $max): ?float
    {
        if ($max < 0) return null;       // غير محدود
        if ($max === 0) return 100.0;
        return round(min(100, $current / $max * 100), 1);
    }
}
