<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Api\V1\Amial\CashierController;
use App\Http\Controllers\Api\V1\Amial\FuelStationController;
use App\Http\Controllers\Api\V1\Amial\PharmacyController;
use App\Http\Controllers\Api\V1\Amial\RestaurantController;
use App\Http\Controllers\Api\V1\Amial\RetailVerticalController;
use App\Http\Controllers\Api\V1\Amial\WholesaleController;
use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Services\Access\EntitlementService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * الويب يعيد استخدام محركات القطاعات الأصلية ويختار من الملف الحقيقي للمالك.
 * لا يقبل القطاع من العميل: يَمنع تاجر التجزئة من فتح بيانات صيدلية بالتلاعب.
 */
class WebSectorController extends Controller
{
    private function sector(Request $request): ?string
    {
        return MerchantProfile::where('user_id', $request->user('merchant_web')->id)
            ->value('business_type');
    }

    public function overview(Request $request): JsonResponse
    {
        $sector = $this->sector($request);
        $target = match ($sector) {
            A::BIZ_QUICK_SALE => [CashierController::class, 'report'],
            A::BIZ_RETAIL => [RetailVerticalController::class, 'operationsCenter'],
            A::BIZ_FUEL => [FuelStationController::class, 'dashboard'],
            A::BIZ_PHARMACY => [PharmacyController::class, 'dashboard'],
            A::BIZ_WHOLESALE => [WholesaleController::class, 'dashboard'],
            A::BIZ_RESTAURANT => [RestaurantController::class, 'tables'],
            default => null,
        };

        if (!$target) return $this->unsupported($sector);
        return $this->invoke($target, $request, $sector);
    }

    public function products(Request $request): JsonResponse
    {
        $sector = $this->sector($request);
        $target = match ($sector) {
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL, A::BIZ_RESTAURANT
                => [CashierController::class, 'products'],
            A::BIZ_FUEL => [FuelStationController::class, 'listProducts'],
            A::BIZ_PHARMACY => [PharmacyController::class, 'listProducts'],
            A::BIZ_WHOLESALE => [WholesaleController::class, 'listProducts'],
            default => null,
        };
        if (!$target) return $this->unsupported($sector);
        return $this->invoke($target, $request, $sector);
    }

    public function createProduct(Request $request, EntitlementService $entitlements): JsonResponse
    {
        $sector = $this->sector($request);
        $capability = match ($sector) {
            A::BIZ_FUEL => A::F_FUEL_PRODUCTS,
            A::BIZ_PHARMACY => A::F_PHARMACY_PRODUCTS,
            A::BIZ_WHOLESALE, A::BIZ_RETAIL, A::BIZ_QUICK_SALE, A::BIZ_RESTAURANT
                => A::F_PRODUCTS,
            default => null,
        };
        if ($capability === null) return $this->unsupported($sector);

        // الباقة والصلاحية والقطاع تُفحص قبل استدعاء محرّك إنشاء أي صنف.
        $gate = $entitlements->state($request->user('merchant_web'), $capability);
        if (($gate['state'] ?? null) !== EntitlementService::AVAILABLE) {
            $status = match ($gate['state'] ?? '') {
                EntitlementService::LOCKED_BY_PLAN, EntitlementService::LIMIT_REACHED => 402,
                EntitlementService::NOT_APPLICABLE => 404,
                EntitlementService::COMING_SOON => 503,
                default => 403,
            };
            return response()->json([
                'success' => false, 'code' => 'PRODUCT_ACCESS_DENIED',
                'message' => $status === 402
                    ? 'إدارة الأصناف غير متاحة ضمن حدود باقتك الحالية.'
                    : 'إدارة أصناف هذا النشاط غير متاحة لهذا الحساب.',
                'meta' => ['entitlement' => $gate],
            ], $status);
        }

        $target = match ($sector) {
            A::BIZ_FUEL => [FuelStationController::class, 'addProduct'],
            A::BIZ_PHARMACY => [PharmacyController::class, 'addProduct'],
            A::BIZ_WHOLESALE => [WholesaleController::class, 'addProduct'],
            default => [CashierController::class, 'addProduct'],
        };

        return $this->invoke($target, $request, $sector);
    }

    /** عناصر التشغيل تختلف: مضخّات، دفعات، فواتير جملة، تصنيفات أو طاولات. */
    public function operations(Request $request): JsonResponse
    {
        $sector = $this->sector($request);
        $target = match ($sector) {
            A::BIZ_FUEL => [FuelStationController::class, 'listPumps'],
            A::BIZ_PHARMACY => [PharmacyController::class, 'listAlerts'],
            A::BIZ_WHOLESALE => [WholesaleController::class, 'listInvoices'],
            A::BIZ_RETAIL => [RetailVerticalController::class, 'categories'],
            A::BIZ_RESTAURANT => [RestaurantController::class, 'tables'],
            A::BIZ_QUICK_SALE => [CashierController::class, 'heldIndex'],
            default => null,
        };
        if (!$target) return $this->unsupported($sector);
        return $this->invoke($target, $request, $sector);
    }

    private function invoke(array $target, Request $request, ?string $sector): JsonResponse
    {
        $controller = app($target[0]);
        $response = $controller->{$target[1]}($request);
        if ($response->getStatusCode() >= 400) return $response;
        $body = $response->getData(true);
        // يحفظ شكل استجابات المحرّك بدلاً من تخمين حقول قطاع آخر.
        return response()->json([
            'success' => true, 'code' => 'OK', 'message' => '',
            'meta' => [
                'sector' => $sector,
                'result' => $body['meta'] ?? $body['data'] ?? [],
            ],
        ]);
    }

    private function unsupported(?string $sector): JsonResponse
    {
        return response()->json([
            'success' => false, 'code' => 'SECTOR_NOT_IMPLEMENTED',
            'message' => 'هذا القطاع ليس له مسار تشغيل ويب مكتمل بعد.',
            'meta' => ['sector' => $sector],
        ], 501);
    }
}
