<?php

namespace App\Http\Controllers\Merchant;

use App\Domain\Verticals\VerticalRegistry;
use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Services\Access\EntitlementService;
use App\Services\Access\PlanComparisonService;
use App\Services\UsageLimitService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * باقات الويب ليست محرك تسعير ثانياً: نفس السجل والمقارنة وعدادات التطبيق.
 * معاينة قطاع آخر تعليمية فقط؛ لا تُغيّر تصنيف حساب التاجر أو صلاحياته.
 */
class WebPlansController extends Controller
{
    public function show(
        Request $request,
        EntitlementService $entitlements,
        PlanComparisonService $comparison,
        UsageLimitService $usage,
    ): JsonResponse {
        $owner = $request->user('merchant_web');
        $profile = MerchantProfile::where('user_id', $owner->id)->firstOrFail();
        $actualSector = (string) ($profile->business_type ?? '');
        $availableSectors = VerticalRegistry::labels();

        $previewSector = $request->query('preview_sector', $actualSector);
        if (!is_string($previewSector) || !array_key_exists($previewSector, $availableSectors)) {
            return response()->json([
                'success' => false,
                'code' => 'UNKNOWN_SECTOR',
                'message' => 'نوع النشاط غير مسجّل في كتالوج المنصة.',
                'meta' => (object) [],
            ], 422);
        }

        // إظهار الباقة الفعّالة بعد انتهاء الاشتراك من المحرك الفعلي؛
        // لا تأخذ شاشة الويب باقة مدفوعة من سجل قديم منتهي الصلاحية.
        $manifest = $entitlements->manifestFor($owner);
        $effective = $manifest['plan']['code'] ?? A::PLAN_FREE;
        $expired = A::canonicalPlan($profile->subscription_plan) !== A::PLAN_FREE
            && $profile->subscription_expires_at !== null
            && $profile->subscription_expires_at->isPast();

        return response()->json([
            'success' => true, 'code' => 'OK', 'message' => '',
            'meta' => [
                'current_plan' => [
                    ...$manifest['plan'],
                    'is_expired' => $expired,
                    'effective_code' => $effective,
                    'price_annual' => A::PLAN_PRICES_SAR_ANNUAL[$effective] ?? 0,
                ],
                'actual_sector' => $actualSector,
                'actual_sector_name' => $availableSectors[$actualSector] ?? 'نوع النشاط غير معروف',
                'preview_sector' => $previewSector,
                'preview_only' => $previewSector !== $actualSector,
                'sectors' => collect($availableSectors)->map(fn ($label, $code) => [
                    'code' => $code, 'label' => $label, 'is_my_sector' => $code === $actualSector,
                ])->values()->all(),
                'comparison' => $comparison->catalogue($previewSector),
                'manifest' => $manifest,
                'usage' => $usage->usageSnapshot($owner),
                // خطط المشروع الحالية لا تخصم ذاتياً. لا نعرض زر تفعيل وهمياً.
                'upgrade' => ['method' => 'contact_support', 'automated' => false],
            ],
        ]);
    }
}
