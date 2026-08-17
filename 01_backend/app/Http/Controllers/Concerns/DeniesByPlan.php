<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Access\EntitlementService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-ENTITLEMENTS-006 — **رفضُ الباقة بصيغةٍ واحدة، أينما وقع.**
 *
 * ══════════════════════════════════════════════════════════════════════
 * **لماذا سِمةٌ لا نسخةٌ في كلّ متحكّم:**
 *
 * أكثرُ القدرات تُحرَس بـ`capability:` على المسار، فالصيغةُ من
 * `EnsureCapability` وحدَه. **لكنّ بعضَها لا يُحرَس بالمسار** لأنّ الفرقَ
 * في **الفعل لا في العنوان**:
 *
 *   مرتجعٌ كامل     → مجّانيٌّ (تصحيحُ مالٍ لا ميزةُ باقة)
 *   مرتجعٌ سطراً سطراً → مدفوع    — **والمسارُ واحد**
 *
 *   تقريرٌ عاديّ     → مجّانيّ
 *   تصديرُ Excel     → مدفوع    — **والمسارُ واحد**
 *
 * فالحارسُ ينزل إلى المتحكّم. **وحينئذٍ يبدأ الخطر**: كلُّ متحكّمٍ يكتب
 * صيغةَ رفضٍ من عنده، فيقرأ التطبيقُ أربعَ صيغٍ لمعنىً واحد — ويكسر
 * `meta.unlock` الذي تعتمد عليه شاشةُ الترقية، **فيظهر رفضٌ بلا زرّ
 * ترقية**. وهذا نمطُ العطل الأكثر تكراراً في هذا المشروع: مصدرا حقيقةٍ
 * لشيءٍ واحدٍ يفترقان بهدوء.
 *
 * **فالصيغةُ هنا وحدَها، ومطابقةٌ لِما يُخرجه `EnsureCapability`.**
 */
trait DeniesByPlan
{
    /**
     * يفحص قدرةً، ويُعيد **ردَّ رفضٍ** إن لم تكن متاحة — و`null` إن كانت.
     *
     * ```php
     * if ($deny = $this->denyUnless($request, 'retail.returns.by_line')) {
     *     return $deny;
     * }
     * ```
     *
     * **ويحترم الوضعَ الصامتَ وتجاوزَ الأدمن** لأنّه يمرّ بـ`gate()` —
     * وهي نقطةُ القرار الوحيدة. ولو سأل `state()` مباشرةً لصار قراراً
     * ثانياً يخالف الأوّل.
     */
    protected function denyUnless(Request $request, string $capability): ?JsonResponse
    {
        $user = $request->user();

        if ($user === null) {
            return null;   // المصادقةُ شأنُ `auth:api` — ولا يُخترع رفضٌ هنا
        }

        $state = app(EntitlementService::class)->gate($user, $capability);

        return $state === null ? null : $this->planDenial($state);
    }

    /** الصيغةُ — ولا تُكتب ثانيةً في أيّ متحكّم. */
    protected function planDenial(array $r): JsonResponse
    {
        $limit = $r['state'] === EntitlementService::LIMIT_REACHED;

        return new JsonResponse([
            'success' => false,
            'code' => $limit ? 'PLAN_LIMIT_REACHED' : 'PLAN_UPGRADE_REQUIRED',
            'message' => sprintf('«%s» متاحة في باقة %s (%s %s شهرياً)',
                $r['capability']['name'] ?? '—',
                $r['unlock']['plan_name'] ?? '—',
                $r['unlock']['price_monthly'] ?? '—',
                A::PLAN_PRICE_CURRENCY),
            'errors' => (object) [],
            'meta' => [
                'capability' => $r['capability'] ?? null,
                'state' => $r['state'] ?? null,
                'unlock' => $r['unlock'] ?? null,
                'usage' => $r['usage'] ?? null,
            ],
        ], 402);
    }
}
