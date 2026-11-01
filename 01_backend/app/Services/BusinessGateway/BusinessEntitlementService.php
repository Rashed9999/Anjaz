<?php

namespace App\Services\BusinessGateway;

use App\Models\BusinessGateway\BusinessLink;
use App\Models\MerchantProfile;
use App\Models\SubscriptionPlan;
use App\Services\Access\PlanCatalogService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\BusinessFeatureRegistry as R;

/**
 * AMIAL-BUSINESS-PLANS-001 · AMIAL-PLAN-CATALOG-001 — ما يحقّ لتاجرٍ في أميال أعمال، من اشتراكه هنا.
 *
 * الميزات والحدود من **باقته في الكتالوج** (تُدار من لوحة الإدارة بمربّعات اختيار)، والإضافات الإداريّة
 * فوقها. الانتهاء بالقاعدة نفسها التي يقرأ بها التطبيق: مدفوعةٌ انتهت ⇒ باقة «free» في الكتالوج.
 * ويُرسل ما يسري بعد الانتهاء وموعدُه، فتنزل أميال أعمال في وقتها ولو انقطعت القناة.
 */
class BusinessEntitlementService
{
    public function __construct(private readonly PlanCatalogService $catalog) {}

    public function for(BusinessLink $link): array
    {
        $profile = MerchantProfile::where('user_id', $link->merchant_user_id)->first();
        $tier = A::canonicalPlan($profile?->subscription_plan ?? A::PLAN_FREE);
        if (!in_array($tier, A::ALL_PLANS, true)) {
            $tier = A::PLAN_FREE;
        }
        $expiresAt = $profile?->subscription_expires_at;
        $expired = $tier !== A::PLAN_FREE && $expiresAt !== null && $expiresAt->isPast();

        $paidPlan = $this->catalog->forProfile($profile);
        $freePlan = SubscriptionPlan::where('code', A::PLAN_FREE)->first();
        $current = $expired ? $freePlan : $paidPlan;

        $planFeatures = $current ? (array) $current->business_features : R::availableOn($expired ? A::PLAN_FREE : $tier);
        $limits = $current ? (array) $current->business_limits : R::LIMITS[$expired ? A::PLAN_FREE : $tier];
        $extras = array_values(array_filter((array) ($profile?->extra_features ?? []), fn ($c) => is_string($c) && R::exists($c)));

        $features = [];
        foreach (R::FEATURES as $code => $f) {
            if (!empty($f['retired'])) {
                continue;
            }
            $byPlan = in_array($code, $planFeatures, true);
            $features[] = [
                'code' => $code, 'name_ar' => $f['ar'], 'name_en' => $f['en'], 'group' => $f['group'],
                'min_plan' => $this->cheapestWith($code) ?? $f['min_plan'],
                'available' => $byPlan || in_array($code, $extras, true),
                'source' => $byPlan ? 'plan' : (in_array($code, $extras, true) ? 'addon' : 'locked'),
            ];
        }

        $planCode = $expired ? A::PLAN_FREE : ($paidPlan?->code ?? $tier);
        $body = [
            'plan' => $planCode,
            'plan_label' => $current?->name_ar ?? (A::PLAN_LABELS[$tier] ?? $tier),
            'paid_plan' => $paidPlan?->code ?? $tier,
            'status' => $expired ? 'expired' : ($tier === A::PLAN_FREE ? 'free' : 'active'),
            'expires_at' => $expired ? null : $expiresAt?->toIso8601String(),
            'features' => $features,
            'limits' => ['users' => $limits['users'] ?? null, 'products' => $limits['products'] ?? null],
            'after_expiry' => [
                'features' => array_values(array_unique(array_merge($freePlan ? (array) $freePlan->business_features : R::availableOn(A::PLAN_FREE), $extras))),
                'limits' => $freePlan ? (array) $freePlan->business_limits : R::LIMITS[A::PLAN_FREE],
            ],
            'plans' => SubscriptionPlan::where('is_active', true)->where('is_public', true)->orderBy('sort_order')->orderBy('monthly_price')->get()
                ->map(fn (SubscriptionPlan $p) => [
                    'code' => $p->code, 'label' => $p->name_ar, 'monthly_price' => (string) $p->monthly_price,
                    'currency' => $p->currency, 'currency_label' => A::PLAN_PRICE_CURRENCY,
                    'durations' => collect($p->durations)->map(fn ($d) => $d + ['price' => $p->priceFor((int) $d['months'])])->values()->all(),
                    'features' => (array) $p->business_features, 'limits' => (array) $p->business_limits,
                ])->values()->all(),
            'upgrade_channel' => 'amial_pay_app',
        ];
        $body['version'] = substr(hash('sha256', json_encode($body)), 0, 16);

        return $body;
    }

    /** أرخص باقةٍ معروضة تفتح الميزة — لعبارة «في باقة كذا» هناك. */
    private function cheapestWith(string $code): ?string
    {
        return SubscriptionPlan::where('is_active', true)->where('is_public', true)->orderBy('monthly_price')->get()
            ->first(fn (SubscriptionPlan $p) => in_array($code, (array) $p->business_features, true))?->code;
    }

    /** يُنادى عند كل تغيّر في الاشتراك أو الإضافات أو الباقة نفسها: أميال أعمال تعيد القراءة. */
    public function announce(int $merchantUserId): void
    {
        $link = BusinessLink::where('merchant_user_id', $merchantUserId)->first();
        if (!$link) {
            return;
        }
        app(BusinessOutbox::class)->record($link, 'entitlements.changed', ['version' => $this->for($link)['version']]);
    }
}
