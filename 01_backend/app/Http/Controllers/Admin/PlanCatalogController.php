<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubscriptionPlan;
use App\Services\Access\PlanCatalogService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\BusinessFeatureRegistry as R;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * AMIAL-PLAN-CATALOG-001 — كتالوج الباقات: اسم · سعر · ميزات (مربّعات) · حدود · مدد بخصم.
 * كل حفظٍ يُدقَّق ويُبلَّغ لمشتركي الباقة في أميال أعمال.
 */
class PlanCatalogController extends Controller
{
    public function __construct(private readonly PlanCatalogService $catalog) {}

    public function page(): View
    {
        return view('admin-views.amial.hub.plans', [
            'features' => collect(R::FEATURES)->reject(fn ($f) => !empty($f['retired']))
                ->map(fn ($f, $code) => ['code' => $code, 'name' => $f['ar'], 'group' => $f['group']])->values(),
            'tiers' => array_map(fn ($t) => ['code' => $t, 'label' => A::PLAN_LABELS[$t] ?? $t], A::ALL_PLANS),
            'currencyLabel' => A::PLAN_PRICE_CURRENCY,
        ]);
    }

    public function list(): JsonResponse
    {
        return response()->json(['data' => SubscriptionPlan::orderBy('sort_order')->orderBy('monthly_price')->get()->map(fn (SubscriptionPlan $p) => $p->only([
            'id', 'code', 'name_ar', 'name_en', 'description_ar', 'base_tier', 'monthly_price', 'currency', 'business_features',
            'business_limits', 'durations', 'is_active', 'is_public', 'is_system', 'sort_order',
        ]) + [
            'subscribers' => $this->catalog->subscribers($p),
            'prices' => collect($p->durations)->map(fn ($d) => ['months' => $d['months'], 'price' => $p->priceFor((int) $d['months'])])->values(),
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $plan = $this->catalog->save(null, $data);
        $this->audit($request, 'PLAN_CATALOG_CREATED', $plan);

        return response()->json(['message' => 'أُنشئت الباقة', 'id' => $plan->id], 201);
    }

    public function update(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $data = $this->validated($request, $plan);
        $plan = $this->catalog->save($plan, $data);
        $this->audit($request, 'PLAN_CATALOG_UPDATED', $plan);

        return response()->json(['message' => 'حُفظت الباقة']);
    }

    public function toggle(Request $request, SubscriptionPlan $plan): JsonResponse
    {
        $plan = $this->catalog->toggle($plan);
        $this->audit($request, $plan->is_active ? 'PLAN_CATALOG_ACTIVATED' : 'PLAN_CATALOG_DEACTIVATED', $plan);

        return response()->json(['message' => $plan->is_active ? 'فُعّلت الباقة' : 'أُوقفت الباقة — المشتركون فيها باقون حتى نهاية مددهم']);
    }

    private function validated(Request $request, ?SubscriptionPlan $plan): array
    {
        return $request->validate([
            'code' => $plan ? ['prohibited'] : ['required', 'string', 'max:32', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('subscription_plans', 'code')],
            'name_ar' => ['required', 'string', 'max:64'],
            'name_en' => ['required', 'string', 'max:64'],
            'description_ar' => ['nullable', 'string', 'max:255'],
            'base_tier' => [$plan ? 'sometimes' : 'required', Rule::in(A::ALL_PLANS)],
            'monthly_price' => ['required', 'numeric', 'min:0', 'max:999999'],
            'business_features' => ['present', 'array'],
            'business_features.*' => ['string', Rule::in(array_keys(R::FEATURES))],
            'limits.users' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'limits.products' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'durations' => ['required', 'array', 'min:1', 'max:6'],
            'durations.*.months' => ['required', 'integer', Rule::in([1, 3, 6, 12, 24])],
            'durations.*.discount_percent' => ['required', 'numeric', 'min:0', 'max:90'],
            'is_public' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
        ], [
            // لا ملفّ ترجمة عربيّ للتحقّق في المشروع بعد؛ فالرسائل هنا صريحة بالعربيّة.
            'code.required' => 'رمز الباقة مطلوب.',
            'code.regex' => 'الرمز بحروفٍ إنجليزيّة صغيرة وأرقام وشرطةٍ سفليّة، ويبدأ بحرف.',
            'code.unique' => 'هذا الرمز مستعملٌ لباقةٍ أخرى.',
            'code.prohibited' => 'رمز الباقة لا يتغيّر بعد إنشائها.',
            'name_ar.required' => 'الاسم بالعربيّة مطلوب.',
            'name_en.required' => 'الاسم بالإنجليزيّة مطلوب.',
            'base_tier.required' => 'اختر رتبة الباقة.',
            'base_tier.in' => 'رتبةٌ غير معروفة.',
            'monthly_price.required' => 'السعر الشهريّ مطلوب.',
            'monthly_price.numeric' => 'السعر رقم.',
            'monthly_price.min' => 'السعر لا يكون سالباً.',
            'business_features.present' => 'اختر ميزات الباقة (أو لا شيء).',
            'business_features.*.in' => 'ميزةٌ غير معروفة.',
            'limits.users.min' => 'حدّ المستخدمين واحدٌ على الأقل، أو فارغ لبلا حد.',
            'limits.products.min' => 'حدّ المنتجات واحدٌ على الأقل، أو فارغ لبلا حد.',
            'durations.required' => 'اختر مدّةً واحدةً على الأقل.',
            'durations.min' => 'اختر مدّةً واحدةً على الأقل.',
            'durations.*.months.in' => 'المدد المتاحة: 1 · 3 · 6 · 12 · 24 شهراً.',
            'durations.*.discount_percent.max' => 'الخصم لا يتجاوز 90%.',
            'durations.*.discount_percent.min' => 'الخصم لا يكون سالباً.',
        ]);
    }

    private function audit(Request $request, string $action, SubscriptionPlan $plan): void
    {
        try {
            app(\App\Services\AuditService::class)->record([
                'actor_type' => 'admin', 'actor_user_id' => $request->user()?->id,
                'subject_type' => 'subscription_plan', 'subject_id' => $plan->code, 'action' => $action,
                'decision_code' => 'OK', 'severity' => 'warning',
                'context' => ['monthly_price' => (string) $plan->monthly_price, 'features' => $plan->business_features,
                    'limits' => $plan->business_limits, 'durations' => $plan->durations, 'active' => $plan->is_active],
            ]);
        } catch (\Throwable) {
        }
    }
}
