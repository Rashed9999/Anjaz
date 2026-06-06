<?php

namespace App\Services;

use App\Models\MerchantProfile;
use App\Models\User;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\AccessPresets;

/**
 * CRITICAL-001 — Feature Access Service.
 *
 * الخدمة الجوهرية التي تقرّر ما يراه كل مستخدم.
 *
 * المنطق:
 *   features = roleBase(role)
 *            ∪ businessTypeFeatures(business_type)
 *            ∪ planFeatures(plan)
 *            ∪ verificationFeatures(verification_level)
 *            ∪ extra_features (من الأدمن)
 */
class FeatureAccessService
{
    /**
     * يُرجع كل البيانات اللازمة للواجهة:
     *   - role
     *   - verification_level
     *   - business_type
     *   - subscription_plan
     *   - subscription_expires_at
     *   - features[]
     *   - limits{}
     *   - labels (للعرض)
     */
    public function accessFor(User $user): array
    {
        $role = $user->role ?? A::ROLE_USER;
        $verificationLevel = $user->verification_level ?? A::VERIFICATION_BASIC;

        $merchantProfile = null;
        $businessType = null;
        $plan = A::PLAN_FREE;
        $extraFeatures = [];
        $expiresAt = null;
        $notes = null;

        if ($role === A::ROLE_MERCHANT) {
            $merchantProfile = MerchantProfile::where('user_id', $user->id)->first();
            if ($merchantProfile) {
                $businessType = $merchantProfile->business_type;
                $plan = $merchantProfile->subscription_plan ?? A::PLAN_FREE;
                $extraFeatures = is_array($merchantProfile->extra_features)
                    ? $merchantProfile->extra_features : [];
                $expiresAt = $merchantProfile->subscription_expires_at?->toIso8601String();
                $notes = $merchantProfile->subscription_notes;

                // CRITICAL-001-PLANS — فحص انتهاء الاشتراك
                // إن انتهى التاريخ والـ plan ليس FREE، يعود تلقائياً لـ FREE
                if ($plan !== A::PLAN_FREE
                    && $merchantProfile->subscription_expires_at !== null
                    && $merchantProfile->subscription_expires_at->isPast()) {
                    $plan = A::PLAN_FREE;
                }
            }
        }

        $features = $this->resolveFeatures($role, $verificationLevel, $businessType, $plan, $extraFeatures);
        $limits = $role === A::ROLE_MERCHANT ? AccessPresets::planLimits($plan) : [];

        return [
            'role' => $role,
            'verification_level' => $verificationLevel,
            'business_type' => $businessType,
            'business_type_label' => $businessType ? (A::BUSINESS_TYPE_LABELS[$businessType] ?? null) : null,
            'subscription_plan' => $plan,
            'subscription_plan_label' => A::PLAN_LABELS[$plan] ?? $plan,
            'subscription_price_sar' => A::PLAN_PRICES_SAR[$plan] ?? 0,
            'subscription_expires_at' => $expiresAt,
            'subscription_notes' => $notes,
            'features' => array_values($features),
            'limits' => $limits,
        ];
    }

    /**
     * يحسب قائمة Features النهائية بدمج كل المصادر.
     */
    public function resolveFeatures(
        string $role,
        string $verificationLevel,
        ?string $businessType,
        string $plan,
        array $extraFeatures = [],
    ): array {
        $features = [];

        // 1. أساس الدور
        foreach (AccessPresets::roleBase($role) as $f) $features[$f] = true;

        // 2. Business Type (للتجّار فقط)
        if ($role === A::ROLE_MERCHANT && $businessType !== null) {
            foreach (AccessPresets::businessTypeFeatures($businessType) as $f) $features[$f] = true;
        }

        // 3. Plan
        if ($role === A::ROLE_MERCHANT) {
            foreach (AccessPresets::planFeatures($plan) as $f) $features[$f] = true;
        }

        // 4. Verification
        foreach (AccessPresets::verificationFeatures($verificationLevel) as $f) $features[$f] = true;

        // 5. Extra (يُفعّلها الأدمن يدوياً)
        foreach ($extraFeatures as $f) {
            if (is_string($f)) $features[$f] = true;
        }

        return array_keys($features);
    }

    /** فحص سريع لميزة محدّدة. */
    public function hasFeature(User $user, string $feature): bool
    {
        $access = $this->accessFor($user);
        return in_array($feature, $access['features'], true);
    }

    /** Admin: تغيير خطّة تاجر. */
    public function updateMerchantPlan(
        MerchantProfile $merchant,
        string $newPlan,
        ?\DateTimeInterface $expiresAt = null,
        ?string $notes = null,
    ): MerchantProfile {
        if (!in_array($newPlan, A::ALL_PLANS, true)) {
            throw new \InvalidArgumentException("خطّة غير صحيحة: {$newPlan}");
        }
        $merchant->update([
            'subscription_plan' => $newPlan,
            'subscription_expires_at' => $expiresAt,
            'subscription_notes' => $notes,
        ]);
        return $merchant->fresh();
    }

    /** Admin: تغيير business_type تاجر. */
    public function updateBusinessType(MerchantProfile $merchant, ?string $type): MerchantProfile
    {
        if ($type !== null && !in_array($type, A::ALL_BUSINESS_TYPES, true)) {
            throw new \InvalidArgumentException("نوع نشاط غير صحيح: {$type}");
        }
        $merchant->update(['business_type' => $type]);
        return $merchant->fresh();
    }

    /** Admin: إضافة feature إضافية يدوياً (للحالات الاستثنائية). */
    public function addExtraFeature(MerchantProfile $merchant, string $feature): MerchantProfile
    {
        $current = is_array($merchant->extra_features) ? $merchant->extra_features : [];
        if (!in_array($feature, $current, true)) {
            $current[] = $feature;
            $merchant->update(['extra_features' => $current]);
        }
        return $merchant->fresh();
    }

    public function removeExtraFeature(MerchantProfile $merchant, string $feature): MerchantProfile
    {
        $current = is_array($merchant->extra_features) ? $merchant->extra_features : [];
        $merchant->update(['extra_features' => array_values(array_filter($current, fn($f) => $f !== $feature))]);
        return $merchant->fresh();
    }
}
