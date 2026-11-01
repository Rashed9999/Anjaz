<?php

namespace App\Services\Access;

use App\Models\MerchantProfile;
use App\Models\SubscriptionPlan;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\BusinessFeatureRegistry as R;
use Illuminate\Validation\ValidationException;

/**
 * AMIAL-PLAN-CATALOG-001 — كتالوج الباقات.
 *
 *   - الباقة النظاميّة (free/business/enterprise) لا تُحذف ولا يتغيّر رمزها أو رتبتها؛ سعرُها وميزاتها تُعدَّل.
 *   - رمزُ الباقة لا يتغيّر بعد إنشائها (يُخزَّن عند التجّار)، والباقةُ المستعملة لا تُحذف — تُوقف.
 *   - الميزاتُ من BusinessFeatureRegistry وحده؛ رمزٌ مجهولٌ يُرفض لا يُخزَّن.
 */
class PlanCatalogService
{
    /** باقة التاجر: رمزه في الكتالوج، وإلا باقة رتبته النظاميّة. لا كتالوج (قاعدةٌ قبل الترحيل) ⇒ null. */
    public function forProfile(?MerchantProfile $profile): ?SubscriptionPlan
    {
        $tier = A::canonicalPlan($profile?->subscription_plan ?? A::PLAN_FREE);
        $code = $profile?->subscription_plan_code;

        return ($code ? SubscriptionPlan::where('code', $code)->first() : null)
            ?? SubscriptionPlan::where('code', $tier)->first();
    }

    /** للإسناد: باقةٌ مفعّلة بالرمز، أو رتبةٌ نظاميّة (توافقٌ مع من يرسل free/business/enterprise). */
    public function resolveAssignable(string $code, bool $includeInactive = false): SubscriptionPlan
    {
        // الموقوفة لا تُباع لجديد، لكنّ تجديد مشتركٍ قائمٍ فيها أو تمديده مسموح.
        $plan = SubscriptionPlan::where('code', $code)->when(!$includeInactive, fn ($q) => $q->where('is_active', true))->first();
        if (!$plan) {
            throw new \InvalidArgumentException("Invalid plan: {$code}");
        }

        return $plan;
    }

    public function save(?SubscriptionPlan $plan, array $data): SubscriptionPlan
    {
        $features = array_values(array_unique((array) ($data['business_features'] ?? [])));
        $unknown = array_diff($features, array_keys(R::FEATURES));
        if ($unknown) {
            throw ValidationException::withMessages(['business_features' => 'ميزات غير معروفة: '.implode('، ', $unknown)]);
        }
        $durations = collect((array) ($data['durations'] ?? []))
            ->map(fn ($d) => ['months' => (int) $d['months'], 'discount_percent' => round((float) ($d['discount_percent'] ?? 0), 4)])
            ->unique('months')->sortBy('months')->values()->all();
        if ($durations === []) {
            throw ValidationException::withMessages(['durations' => 'مدّةٌ واحدة على الأقل.']);
        }

        $attrs = [
            'name_ar' => $data['name_ar'], 'name_en' => $data['name_en'], 'description_ar' => $data['description_ar'] ?? null,
            'monthly_price' => $data['monthly_price'], 'business_features' => $features,
            'business_limits' => ['users' => $data['limits']['users'] ?? null, 'products' => $data['limits']['products'] ?? null],
            'durations' => $durations, 'is_public' => (bool) ($data['is_public'] ?? true), 'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        if ($plan === null) {
            $plan = SubscriptionPlan::create($attrs + ['code' => $data['code'], 'base_tier' => $data['base_tier'], 'currency' => 'SAR', 'is_active' => true]);
        } else {
            if (!$plan->is_system && isset($data['base_tier'])) {
                $attrs['base_tier'] = $data['base_tier'];
            }
            $plan->update($attrs);
        }
        $this->announceTo($plan);

        return $plan->fresh();
    }

    public function toggle(SubscriptionPlan $plan): SubscriptionPlan
    {
        if ($plan->is_system) {
            throw ValidationException::withMessages(['plan' => 'الباقة النظاميّة لا تُوقف.']);
        }
        $plan->update(['is_active' => !$plan->is_active]);

        return $plan;
    }

    public function subscribers(SubscriptionPlan $plan): int
    {
        return MerchantProfile::where(fn ($q) => $q->where('subscription_plan_code', $plan->code)
            ->orWhere(fn ($w) => $w->whereNull('subscription_plan_code')->where('subscription_plan', $plan->code)))->count();
    }

    /** تعديل ميزات باقةٍ يغيّر استحقاقات مشتركيها في أميال أعمال: يُبلَّغون. */
    private function announceTo(SubscriptionPlan $plan): void
    {
        $svc = app(\App\Services\BusinessGateway\BusinessEntitlementService::class);
        MerchantProfile::where(fn ($q) => $q->where('subscription_plan_code', $plan->code)
            ->orWhere(fn ($w) => $w->whereNull('subscription_plan_code')->where('subscription_plan', $plan->code)))
            ->whereIn('user_id', \App\Models\BusinessGateway\BusinessLink::query()->select('merchant_user_id'))
            ->pluck('user_id')->each(fn ($id) => $svc->announce((int) $id));
    }
}
