<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Services\Access\EntitlementService;
use App\Support\Access\AccessConstants as A;
use App\Support\Access\CapabilityRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-ENTITLEMENTS-001 — **ملفُّ خدمات التاجر**.
 *
 * ══════════════════════════════════════════════════════════════════════
 * نداءٌ واحدٌ يردّ **كلَّ ما في المنصّة** بحالة كلٍّ لهذا الحساب. ومنه
 * تُرسم شاشةُ «خدماتي» في التطبيق: **صفرُ قوائمَ مكتوبةٍ في Dart**، فقدرةٌ
 * جديدةٌ تظهر للتجّار بلا نشرةِ تطبيق.
 *
 * **والمقفلُ يُرسَل ولا يُحذف** — وما يُخفى لا يُشترى. تُعرض البطاقةُ
 * بقفلها وسعرِ فتحها.
 */
class EntitlementController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** GET /me/entitlements — ملفّ الخدمات كاملاً. */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $this->entitlements->manifestFor($request->user()),
        ]);
    }

    /** GET /me/entitlements/{code} — حالةُ قدرةٍ واحدة (لفحصٍ قبل فتح شاشة). */
    public function show(Request $request, string $code): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => '',
            'data' => $this->entitlements->state($request->user(), $code),
        ]);
    }

    /**
     * GET /plans — جدولُ المقارنة **مولَّداً من السجلّ**.
     *
     * **ولا يُكتب يدويّاً**: صفحةُ تسعيرٍ مكتوبةٌ بيدٍ تعِد بما لا يفتحه
     * النظام، فيدفع التاجرُ ثمّ لا يجد.
     */
    public function plans(Request $request): JsonResponse
    {
        $decisions = \App\Models\Access\PlanCapability::allDecisions();
        $businessType = $request->query('business_type');

        $plans = [];
        foreach (array_keys(CapabilityRegistry::PLAN_ORDER) as $plan) {
            $opens = [];

            foreach (CapabilityRegistry::all() as $cap) {
                if ($businessType !== null && ! $cap->appliesTo($businessType)) {
                    continue;
                }

                $decided = $decisions[$plan][$cap->code] ?? null;
                $open = $decided !== null
                    ? $decided
                    : ($cap->isCore() || $cap->minimumPlan() === null
                        || CapabilityRegistry::planRank($plan)
                            >= CapabilityRegistry::planRank($cap->minimumPlan()));

                if ($open) {
                    $opens[] = ['code' => $cap->code, 'name' => $cap->name(),
                        'group' => $cap->groupName()];
                }
            }

            $plans[] = [
                'code' => $plan,
                'name' => A::PLAN_LABELS[$plan] ?? $plan,
                'price_monthly' => A::PLAN_PRICES_SAR[$plan] ?? 0,
                'price_annual' => A::PLAN_PRICES_SAR_ANNUAL[$plan] ?? 0,
                'limits' => A::PLAN_LIMITS[$plan] ?? [],
                'capabilities_count' => count($opens),
                'capabilities' => $opens,
            ];
        }

        return response()->json([
            'success' => true, 'message' => '',
            'data' => ['plans' => $plans, 'groups' => CapabilityRegistry::groups()],
        ]);
    }
}
