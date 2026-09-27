<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Api\V1\Amial\CashierController;
use App\Http\Controllers\Api\V1\Amial\BarcodeLookupController;
use App\Http\Controllers\Api\V1\Amial\ProductCatalogController;
use App\Models\MerchantProduct;
use App\Models\Retail\MerchantBrand;
use App\Models\Retail\MerchantCategory;
use App\Models\Retail\MerchantUnit;
use App\Models\Retail\ProductBarcode;
use Illuminate\Support\Facades\Validator;
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

    /** Barcode lookups are forced to the owner's ACTUAL vertical. */
    public function lookupBarcode(Request $request): JsonResponse
    {
        $sector = $this->sector($request);
        if ($sector === A::BIZ_FUEL) return $this->unsupported($sector);
        $context = match ($sector) {
            A::BIZ_PHARMACY => 'pharmacy',
            A::BIZ_WHOLESALE => 'wholesale',
            A::BIZ_RETAIL, A::BIZ_QUICK_SALE, A::BIZ_RESTAURANT => 'retail',
            default => null,
        };
        if ($context === null) return $this->unsupported($sector);
        $request->query->set('context', $context);
        return app(BarcodeLookupController::class)->lookup($request);
    }

    /** Public shared catalogue provides suggestions, never competitors' prices. */
    public function catalogueLookup(Request $request): JsonResponse
    {
        if (!in_array($this->sector($request), [A::BIZ_RETAIL, A::BIZ_QUICK_SALE, A::BIZ_RESTAURANT], true))
            return $this->unsupported($this->sector($request));
        return app(ProductCatalogController::class)->lookup($request);
    }

    /** Web owner can edit an existing product without consuming another plan seat. */
    public function updateProduct(Request $request, int $id, EntitlementService $entitlements): JsonResponse
    {
        $sector = $this->sector($request);
        $cap = match ($sector) {
            A::BIZ_PHARMACY => A::F_PHARMACY_PRODUCTS,
            A::BIZ_RETAIL, A::BIZ_QUICK_SALE, A::BIZ_WHOLESALE, A::BIZ_RESTAURANT => A::F_PRODUCTS,
            default => null,
        };
        if ($cap === null) return $this->unsupported($sector);
        $gate = $entitlements->state($request->user('merchant_web'), $cap);
        if (in_array($gate['state'] ?? null, [
            EntitlementService::LOCKED_BY_PLAN, EntitlementService::NOT_APPLICABLE,
            EntitlementService::COMING_SOON,
        ], true)) {
            return response()->json(['success' => false, 'code' => 'PRODUCT_ACCESS_DENIED',
                'message' => 'تعديل أصناف هذا النشاط غير متاح لباقتك.', 'meta' => ['entitlement' => $gate]], 403);
        }
        $target = match ($sector) {
            A::BIZ_PHARMACY => [PharmacyController::class, 'updateProduct'],
            A::BIZ_WHOLESALE => [WholesaleController::class, 'updateProduct'],
            default => [CashierController::class, 'updateProduct'],
        };
        $response = app($target[0])->{$target[1]}($request, $id);
        if ($response->getStatusCode() >= 400) return $response;
        $body = $response->getData(true);
        return response()->json(['success' => true, 'code' => 'PRODUCT_UPDATED', 'message' => 'تم الحفظ',
            'meta' => ['sector' => $sector, 'result' => $body['meta'] ?? $body['data'] ?? []]]);
    }

    private function genericSector(?string $sector): bool
    {
        return in_array($sector, [A::BIZ_RETAIL, A::BIZ_QUICK_SALE, A::BIZ_RESTAURANT], true);
    }

    /** Category/brand/unit trees come from the existing retail engine. */
    public function catalogueOptions(Request $request): JsonResponse
    {
        if (!$this->genericSector($this->sector($request))) return $this->unsupported($this->sector($request));
        $id = (int) $request->user('merchant_web')->id;
        return response()->json(['success' => true, 'code' => 'CATALOGUE_OPTIONS',
            'meta' => [
                'categories' => MerchantCategory::where('merchant_user_id', $id)
                    ->orderBy('name')->get(['id', 'name', 'parent_id'])->all(),
                'brands' => MerchantBrand::where('merchant_user_id', $id)->where('is_active', true)
                    ->orderBy('name')->get(['id', 'name'])->all(),
                'units' => MerchantUnit::where('merchant_user_id', $id)->where('is_active', true)
                    ->orderBy('name')->get(['id', 'name', 'factor'])->all(),
            ]]);
    }

    public function addCatalogueOption(Request $request, string $kind): JsonResponse
    {
        $sector = $this->sector($request);
        if (!$this->genericSector($sector)) return $this->unsupported($sector);
        $valid = Validator::make($request->all(), ['name' => 'required|string|max:100']);
        if ($valid->fails()) return response()->json(['success' => false, 'code' => 'VALIDATION',
            'message' => $valid->errors()->first()], 422);
        $method = match ($kind) {
            'categories' => 'addCategory',
            'brands' => 'addBrand',
            'units' => 'addUnit',
            default => null,
        };
        if ($method === null) return $this->unsupported($sector);
        return $this->invoke([RetailVerticalController::class, $method], $request, $sector);
    }

    /** Alternative codes and carton pack sizes belong to an existing owner product. */
    public function addProductBarcode(Request $request, int $id): JsonResponse
    {
        $sector = $this->sector($request);
        if (!$this->genericSector($sector)) return $this->unsupported($sector);
        $valid = Validator::make($request->all(), [
            'barcode' => 'required|string|max:64',
            'pack_size' => 'required|integer|min:1|max:100000',
            'is_primary' => 'sometimes|boolean',
        ]);
        if ($valid->fails()) return response()->json(['success' => false,
            'code' => 'VALIDATION', 'message' => $valid->errors()->first()], 422);
        return $this->invoke([RetailVerticalController::class, 'addBarcode'], $request, $sector, $id);
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

    private function invoke(array $target, Request $request, ?string $sector, ?int $id = null): JsonResponse
    {
        $controller = app($target[0]);
        $response = $id === null ? $controller->{$target[1]}($request)
            : $controller->{$target[1]}($request, $id);
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
