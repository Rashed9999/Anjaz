<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Access\MerchantCapabilityOverride;
use App\Models\Access\PlanCapability;
use App\Models\MerchantProfile;
use App\Models\User;
use App\Services\Access\EntitlementService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\CapabilityRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-ENTITLEMENTS-001 — **مركزُ الباقات والقدرات في اللوحة**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **والتسعيرُ يُجرَّب أكثر من مرّةٍ في الشهور الأولى.** وكلُّ تجربةٍ
 * بنشرةٍ تعني أنّك لن تُجرّب — فصارت المصفوفةُ محرَّرةً من هنا، والقرارُ
 * يُخزَّن في `plan_capabilities` فوق افتراضيّ الشيفرة.
 *
 * **وثلاثةُ حرّاسٍ داخل هذه الشاشة:**
 *
 * ① **الأساسيّةُ لا تُقفَل.** ما يمنع رقماً كاذباً ليس ميزةً تُسعَّر،
 *   والزرُّ عليها معطَّلٌ ويقول لماذا.
 * ② **كلُّ تغييرٍ بسببٍ مكتوب.** تجربةُ تسعيرٍ بلا سببٍ لا تُراجَع بعد
 *   شهرين ولا يُعرف أتُثبَّت أم تُعاد.
 * ③ **منحةُ تاجرٍ بأجل.** ومنحةٌ بلا أجلٍ تصير باقةً دائمةً مجّانيّة.
 */
class EntitlementCenterController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function page()
    {
        return view('admin-views.amial.entitlements.index');
    }

    private function ok(array $data, string $message = ''): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    private function fail(string $message, int $code = 422): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], $code);
    }

    /** المصفوفة: باقة × قدرة، مع مصدر كلّ خانة. */
    public function matrix(): JsonResponse
    {
        $decisions = PlanCapability::allDecisions();
        $plans = array_keys(CapabilityRegistry::PLAN_ORDER);

        $rows = [];
        foreach (CapabilityRegistry::all() as $cap) {
            $cells = [];

            foreach ($plans as $plan) {
                $decided = $decisions[$plan][$cap->code] ?? null;

                $byDefault = $cap->isCore() || $cap->minimumPlan() === null
                    || CapabilityRegistry::planRank($plan)
                        >= CapabilityRegistry::planRank($cap->minimumPlan());

                $cells[$plan] = [
                    'enabled' => $decided ?? $byDefault,
                    // **مصدرُ الخانة يُقال** — فمن يرى «مفتوحة» لا يعرف
                    // أهي افتراضيّةٌ أم قرارُ إدارةٍ سابق، فيغيّر ما لا يقصد.
                    'source' => $decided !== null ? 'admin' : 'default',
                ];
            }

            $rows[] = [
                'code' => $cap->code,
                'name' => $cap->name(),
                'description' => $cap->description(),
                'group' => $cap->groupName(),
                'is_core' => $cap->isCore(),
                'min_plan' => $cap->minimumPlan(),
                'business_types' => $cap->appliesToAllBusinessTypes()
                    ? [] : $cap->toArray()['business_types'],
                'has_screen' => $cap->screenRoute() !== null,
                'routes' => $cap->routePrefixes(),
                'cells' => $cells,
            ];
        }

        return $this->ok([
            'plans' => array_map(fn ($p) => [
                'code' => $p,
                'name' => A::PLAN_LABELS[$p] ?? $p,
                'price' => A::PLAN_PRICES_SAR[$p] ?? 0,
            ], $plans),
            'groups' => CapabilityRegistry::groups(),
            'capabilities' => $rows,
        ]);
    }

    /** POST /matrix — قلبُ خانة. */
    public function setCell(Request $request): JsonResponse
    {
        $plan = (string) $request->input('plan');
        $code = (string) $request->input('capability_code');
        $enabled = (bool) $request->input('is_enabled');
        $reason = trim((string) $request->input('reason', ''));

        if (! array_key_exists($plan, CapabilityRegistry::PLAN_ORDER)) {
            return $this->fail('باقة غير معروفة');
        }

        $cap = CapabilityRegistry::find($code);
        if (! $cap) {
            return $this->fail('قدرة غير معروفة');
        }

        // ① **الأساسيّة لا تُقفَل** — ولا حتّى من اللوحة.
        if ($cap->isCore() && ! $enabled) {
            return $this->fail(
                '«' . $cap->name() . '» قدرة أساسية تمنع أرقاماً خاطئة — '
                . 'وإقفالها يبيع أرقاماً غلط لمن دفع أقلّ');
        }

        // ② السببُ إلزاميّ.
        if ($reason === '') {
            return $this->fail('اكتب سبب التغيير — تجربة تسعير بلا سبب لا تُراجَع لاحقاً');
        }

        PlanCapability::updateOrCreate(
            ['plan' => $plan, 'capability_code' => $code],
            ['is_enabled' => $enabled, 'reason' => $reason,
                'changed_by' => auth()->id()],
        );

        $this->entitlements->forget();

        return $this->ok([], $enabled ? 'فُتحت في هذه الباقة' : 'أُقفلت في هذه الباقة');
    }

    /** DELETE /matrix — إعادةُ خانةٍ إلى الافتراضيّ. */
    public function resetCell(Request $request): JsonResponse
    {
        PlanCapability::where('plan', (string) $request->input('plan'))
            ->where('capability_code', (string) $request->input('capability_code'))
            ->delete();

        $this->entitlements->forget();

        return $this->ok([], 'أُعيدت إلى الافتراضي');
    }

    /** ملفُّ خدمات تاجرٍ بعينه — **كما يراه هو بالضبط**. */
    public function merchant(Request $request, int $id): JsonResponse
    {
        $merchant = User::find($id);
        if (! $merchant) {
            return $this->fail('التاجر غير موجود', 404);
        }

        $profile = MerchantProfile::where('user_id', $id)->first();

        return $this->ok([
            'merchant' => [
                'id' => $merchant->id,
                'name' => trim(($merchant->f_name ?? '') . ' ' . ($merchant->l_name ?? '')) ?: '—',
                'phone' => $merchant->phone,
                'plan' => $profile->subscription_plan ?? A::PLAN_FREE,
                'plan_name' => A::PLAN_LABELS[$profile->subscription_plan ?? A::PLAN_FREE] ?? '—',
                'business_type' => $profile->business_type,
                'expires_at' => optional($profile->subscription_expires_at ?? null)->format('Y-m-d'),
            ],
            'manifest' => $this->entitlements->manifestFor($merchant),
            'overrides' => MerchantCapabilityOverride::where('merchant_user_id', $id)
                ->orderByDesc('id')->get()
                ->map(fn (MerchantCapabilityOverride $o) => [
                    'id' => $o->id,
                    'capability' => CapabilityRegistry::find($o->capability_code)?->name()
                        ?? $o->capability_code,
                    'code' => $o->capability_code,
                    'effect' => $o->effect,
                    // **«بلا أجل» تُقال صراحةً** ولا تُترك فراغاً يُقرأ سهواً.
                    'expires_at' => $o->expires_at?->format('Y-m-d') ?? 'دائم',
                    'expired' => $o->isExpired(),
                    'reason' => $o->reason,
                ])->all(),
        ]);
    }

    /** منحةٌ أو منعٌ لتاجرٍ بعينه. */
    public function setOverride(Request $request, int $id): JsonResponse
    {
        $code = (string) $request->input('capability_code');
        $effect = (string) $request->input('effect');
        $reason = trim((string) $request->input('reason', ''));
        $days = (int) $request->input('days', 0);

        $cap = CapabilityRegistry::find($code);
        if (! $cap) {
            return $this->fail('قدرة غير معروفة');
        }
        if (! in_array($effect, [MerchantCapabilityOverride::GRANT,
            MerchantCapabilityOverride::REVOKE], true)) {
            return $this->fail('نوع القرار غير صحيح');
        }
        if ($cap->isCore() && $effect === MerchantCapabilityOverride::REVOKE) {
            return $this->fail('«' . $cap->name() . '» قدرة أساسية لا تُقفَل');
        }
        if ($reason === '') {
            return $this->fail('اكتب سبب المنح أو المنع');
        }

        MerchantCapabilityOverride::updateOrCreate(
            ['merchant_user_id' => $id, 'capability_code' => $code],
            [
                'effect' => $effect,
                'expires_at' => $days > 0 ? now()->addDays($days) : null,
                'reason' => $reason,
                'granted_by' => auth()->id(),
            ],
        );

        $this->entitlements->forget($id);

        return $this->ok([], $effect === 'grant' ? 'فُتحت لهذا التاجر' : 'أُقفلت لهذا التاجر');
    }

    public function removeOverride(Request $request, int $id, int $overrideId): JsonResponse
    {
        MerchantCapabilityOverride::where('id', $overrideId)
            ->where('merchant_user_id', $id)->delete();

        $this->entitlements->forget($id);

        return $this->ok([], 'أُزيل الاستثناء');
    }

    /**
     * **صحّةُ السجلّ** — وهذا ما يمنع «مبنيٌّ ولا يُوصَل إليه».
     *
     * ثلاثةُ أسئلةٍ تُسأل عن السجلّ نفسِه، لا عن تاجر:
     * قدرةٌ بلا شاشة · قدرةٌ بلا مسار · مسارُ تاجرٍ بلا قدرةٍ تحرسه.
     */
    public function health(): JsonResponse
    {
        $noScreen = [];
        $noRoute = [];

        foreach (CapabilityRegistry::all() as $cap) {
            if ($cap->screenRoute() === null && ! $cap->isCore()) {
                $noScreen[] = ['code' => $cap->code, 'name' => $cap->name()];
            }
            if ($cap->routePrefixes() === [] && ! $cap->isCore()) {
                $noRoute[] = ['code' => $cap->code, 'name' => $cap->name()];
            }
        }

        $unguarded = [];
        foreach (app('router')->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/amial/merchant/')) {
                continue;
            }
            $tail = substr($uri, strlen('api/v1/amial/merchant/'));

            $hasMiddleware = collect($route->gatherMiddleware())
                ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'capability:'));

            if (! $hasMiddleware && CapabilityRegistry::forRoute($tail) === []) {
                $unguarded[] = $uri;
            }
        }

        return $this->ok([
            'capabilities' => count(CapabilityRegistry::all()),
            'without_screen' => $noScreen,
            'without_route' => $noRoute,
            'unguarded_routes' => array_values(array_unique($unguarded)),
        ]);
    }
}
