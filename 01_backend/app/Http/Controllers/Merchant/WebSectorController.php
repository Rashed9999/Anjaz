<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Api\V1\Amial\CashierController;
use App\Http\Controllers\Api\V1\Amial\CustomerCreditController;
use App\Http\Controllers\Api\V1\Amial\BarcodeLookupController;
use App\Http\Controllers\Api\V1\Amial\ProductCatalogController;
use App\Models\MerchantProduct;
use App\Models\Retail\MerchantBrand;
use App\Models\Retail\MerchantCategory;
use App\Models\Retail\MerchantUnit;
use App\Models\Retail\ProductBarcode;
use App\Domain\Verticals\VerticalRegistry as VR;
use App\Services\FeatureAccessService;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Api\V1\Amial\FuelStationController;
use App\Http\Controllers\Api\V1\Amial\PharmacyController;
use App\Http\Controllers\Api\V1\Amial\RestaurantController;
use App\Http\Controllers\Api\V1\Amial\RetailVerticalController;
use App\Http\Controllers\Api\V1\Amial\WholesaleController;
use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\RestaurantOrder;
use App\Services\Access\EntitlementService;
use App\Support\Access\AccessConstants as A;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * الويب يعيد استخدام محركات القطاعات الأصلية ويختار من الملف الحقيقي للمالك.
 * لا يقبل القطاع من العميل: يَمنع تاجر التجزئة من فتح بيانات صيدلية بالتلاعب.
 */
class WebSectorController extends Controller
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    private function sector(Request $request): ?string
    {
        return MerchantProfile::where('user_id', $request->user('merchant_web')->id)
            ->value('business_type');
    }

    public function overview(Request $request): JsonResponse
    {
        $sector = $this->sector($request);
        if ($deny = $this->requireCapability($request, $this->workspaceCapability($sector))) return $deny;
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

    /** Owner onboarding stays on the web after the merchant app became POS-only. */
    public function businessTypes(): JsonResponse
    {
        $types = [];
        foreach (VR::current() as $code => $vertical) {
            $types[] = [
                'code' => $code,
                'label' => VR::labels()[$code] ?? $vertical->nameAr(),
                'hint' => $vertical instanceof \App\Domain\Verticals\DbVertical
                    ? $vertical->hint() : null,
            ];
        }

        return response()->json(['success' => true, 'code' => 'BUSINESS_TYPES',
            'meta' => ['business_types' => $types]]);
    }

    /** The merchant owner chooses the vertical in the same portal they operate. */
    public function updateBusinessType(Request $request, FeatureAccessService $access): JsonResponse
    {
        $valid = Validator::make($request->all(), [
            'business_type' => 'required|in:' . implode(',', VR::codes()),
        ]);
        if ($valid->fails()) return response()->json(['success' => false, 'code' => 'VALIDATION',
            'message' => $valid->errors()->first()], 422);

        $profile = MerchantProfile::where('user_id', $request->user('merchant_web')->id)->firstOrFail();
        $updated = $access->updateBusinessType($profile, $valid->validated()['business_type']);

        return response()->json(['success' => true, 'code' => 'BUSINESS_TYPE_UPDATED',
            'message' => 'تم حفظ نوع النشاط. ستُحدّث البوابة الآن.',
            'meta' => [
                'business_type' => $updated->business_type,
                'business_type_label' => VR::labels()[$updated->business_type] ?? $updated->business_type,
            ]]);
    }

    public function products(Request $request): JsonResponse
    {
        $sector = $this->sector($request);
        if ($deny = $this->requireCapability($request, $this->productCapability($sector))) return $deny;
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
        if ($deny = $this->requireCapability($request, A::F_BARCODE)) return $deny;
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
        if ($deny = $this->requireCapability($request, 'retail.catalog')) return $deny;
        return app(ProductCatalogController::class)->lookup($request);
    }

    /** Web owner can edit an existing product without consuming another plan seat. */
    public function updateProduct(Request $request, int $id, EntitlementService $entitlements): JsonResponse
    {
        $sector = $this->sector($request);
        $cap = match ($sector) {
            A::BIZ_FUEL => A::F_FUEL_PRODUCTS,
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
            // الوقود يملك سياسة تسعير وسجل تغييرات مستقل؛ بوابة المالك
            // تستعمله بدلاً من تعديل صف الوقود مباشرة.
            A::BIZ_FUEL => [FuelStationController::class, 'updateProductPrice'],
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
        if ($deny = $this->requireCapability($request, 'retail.catalog')) return $deny;
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
        if ($deny = $this->requireCapability($request, 'retail.catalog')) return $deny;
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
        if ($deny = $this->requireCapability($request, A::F_BARCODE)) return $deny;
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
        if ($deny = $this->requireCapability($request, $this->workspaceCapability($sector))) return $deny;
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

    /**
     * AMIAL-MERCHANT-CUSTOMERS-001 — قاعدة العملاء من محرك القطاع نفسه.
     *
     * التجزئة/البيع السريع/المطعم تستعمل دفتر العميل الموحد الذي يغذي
     * الآجل أيضاً، بينما الصيدلية والجملة تحتفظان بملف عميل متخصص.
     * الوقود له حسابات شركات مستقلة، لذلك لا نعرض له قاعدة عملاء مزيفة.
     */
    public function customers(Request $request): JsonResponse
    {
        $sector = $this->sector($request);
        if ($deny = $this->requireCapability($request, A::F_CUSTOMERS)) return $deny;

        $target = match ($sector) {
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL, A::BIZ_RESTAURANT
                => [CustomerCreditController::class, 'listCustomers'],
            A::BIZ_PHARMACY => [PharmacyController::class, 'listCustomers'],
            A::BIZ_WHOLESALE => [WholesaleController::class, 'listCustomers'],
            default => null,
        };

        if (!$target) return $this->unsupported($sector);
        return $this->invoke($target, $request, $sector);
    }

    public function createCustomer(Request $request): JsonResponse
    {
        $sector = $this->sector($request);
        if ($deny = $this->requireCapability($request, A::F_CUSTOMERS)) return $deny;

        $target = match ($sector) {
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL, A::BIZ_RESTAURANT
                => [CustomerCreditController::class, 'upsertCustomer'],
            A::BIZ_PHARMACY => [PharmacyController::class, 'addCustomer'],
            A::BIZ_WHOLESALE => [WholesaleController::class, 'addCustomer'],
            default => null,
        };

        if (!$target) return $this->unsupported($sector);
        return $this->invoke($target, $request, $sector);
    }

    public function updateCustomer(Request $request, int $id): JsonResponse
    {
        $sector = $this->sector($request);
        if ($deny = $this->requireCapability($request, A::F_CUSTOMERS)) return $deny;

        // دفتر العملاء الموحد في التجزئة يُحدّث عبر upsert بالهاتف، ولا
        // نقبل id لا يستعمله المصدر. الصيدلية والجملة لديهما هوية صف.
        $target = match ($sector) {
            A::BIZ_PHARMACY => [PharmacyController::class, 'updateCustomer'],
            A::BIZ_WHOLESALE => [WholesaleController::class, 'updateCustomer'],
            default => null,
        };

        if (!$target) {
            return response()->json([
                'success' => false, 'code' => 'CUSTOMER_UPDATE_BY_ID_NOT_SUPPORTED',
                'message' => 'تحديث هذا النوع من العملاء يتم بالحفظ برقم الهاتف.',
                'meta' => ['sector' => $sector],
            ], 422);
        }

        return $this->invoke($target, $request, $sector, $id);
    }

    /**
     * سجل المالك القطاعي. لا يُعاد استخدام سجل `merchant_sales` للوقود
     * والصيدلية والجملة والمطعم، لأن لكل منها عملية مصدر وحالة دفع مختلفة.
     */
    public function sales(Request $request): JsonResponse
    {
        $sector = $this->sector($request);
        if ($deny = $this->requireCapability($request, $this->salesCapability($sector))) return $deny;

        $target = match ($sector) {
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL => [CashierController::class, 'listSales'],
            A::BIZ_FUEL => [FuelStationController::class, 'listSales'],
            A::BIZ_PHARMACY => [PharmacyController::class, 'listSales'],
            A::BIZ_WHOLESALE => [WholesaleController::class, 'listInvoices'],
            A::BIZ_RESTAURANT => [RestaurantController::class, 'salesHistory'],
            default => null,
        };

        if (!$target) return $this->unsupported($sector);
        return $this->invoke($target, $request, $sector);
    }

    /** تفصيل السجل نفسه الذي ظهر في قائمة القطاع، وبنطاق مالك الجلسة فقط. */
    public function sale(Request $request, string $id): JsonResponse
    {
        $sector = $this->sector($request);
        if ($deny = $this->requireCapability($request, $this->salesCapability($sector))) return $deny;

        $target = match ($sector) {
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL => [CashierController::class, 'showSale'],
            A::BIZ_FUEL => [FuelStationController::class, 'showSale'],
            A::BIZ_PHARMACY => [PharmacyController::class, 'showSale'],
            A::BIZ_WHOLESALE => [WholesaleController::class, 'showInvoice'],
            A::BIZ_RESTAURANT => [RestaurantController::class, 'showOrder'],
            default => null,
        };

        if (!$target) return $this->unsupported($sector);
        return $this->invoke($target, $request, $sector, $id);
    }

    /**
     * لا تتوفر فاتورة PDF إلا حيث يوجد مولّد رسمي في محرك القطاع.
     * إبقاء هذا الباب صريحاً يمنع توليد PDF عام ناقص للصيدلية أو للوقود.
     */
    public function saleInvoice(Request $request, string $id): Response|JsonResponse
    {
        $sector = $this->sector($request);
        if ($deny = $this->requireCapability($request, $this->salesCapability($sector))) return $deny;

        // كل قطاع يستعمل مولّدَه الرسمي القائم؛ لا PDF عام يغيّر
        // معنى الفاتورة. المطعم يغلق إلى MerchantSale، لذلك فاتورته هي
        // فاتورة البيع المرتبطة بالطلب نفسه.
        if ($sector === A::BIZ_RESTAURANT) {
            $owner = $request->user('merchant_web');
            $order = RestaurantOrder::where('merchant_user_id', $owner->id)
                ->whereKey((int) $id)
                ->first();

            if (!$order || !$order->sale_ulid) {
                return response()->json([
                    'success' => false, 'code' => 'INVOICE_NOT_READY',
                    'message' => 'هذا الطلب لم يُغلق إلى فاتورة بيع بعد.',
                    'meta' => ['sector' => $sector],
                ], 409);
            }

            return app(CashierController::class)->downloadInvoice($request, $order->sale_ulid);
        }

        $target = match ($sector) {
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL => [CashierController::class, 'downloadInvoice'],
            A::BIZ_PHARMACY => [PharmacyController::class, 'downloadInvoice'],
            A::BIZ_FUEL => [FuelStationController::class, 'downloadReceipt'],
            A::BIZ_WHOLESALE => [WholesaleController::class, 'downloadInvoicePdf'],
            default => null,
        };
        if (!$target) return $this->unsupported($sector);

        return app($target[0])->{$target[1]}($request, $id);
    }

    private function invoke(array $target, Request $request, ?string $sector, int|string|null $id = null): JsonResponse
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

    private function workspaceCapability(?string $sector): ?string
    {
        return match ($sector) {
            A::BIZ_QUICK_SALE => A::F_QUICK_SALE,
            A::BIZ_RETAIL => A::F_INVENTORY,
            A::BIZ_FUEL => A::F_FUEL_PUMPS,
            A::BIZ_PHARMACY => A::F_PHARMACY_ALERTS,
            A::BIZ_WHOLESALE => A::F_WHOLESALE_INVOICES,
            A::BIZ_RESTAURANT => A::F_RESTAURANT_TABLES,
            default => null,
        };
    }

    private function productCapability(?string $sector): ?string
    {
        return match ($sector) {
            A::BIZ_FUEL => A::F_FUEL_PRODUCTS,
            A::BIZ_PHARMACY => A::F_PHARMACY_PRODUCTS,
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL, A::BIZ_WHOLESALE, A::BIZ_RESTAURANT => A::F_PRODUCTS,
            default => null,
        };
    }

    private function salesCapability(?string $sector): ?string
    {
        return match ($sector) {
            A::BIZ_QUICK_SALE, A::BIZ_RETAIL => A::F_QUICK_SALE,
            A::BIZ_FUEL => A::F_FUEL_POS,
            A::BIZ_PHARMACY => A::F_PHARMACY_POS,
            A::BIZ_WHOLESALE => A::F_WHOLESALE_INVOICES,
            A::BIZ_RESTAURANT => A::F_RESTAURANT_ORDERS,
            default => null,
        };
    }

    private function requireCapability(Request $request, ?string $capability): ?JsonResponse
    {
        if ($capability === null) return $this->unsupported($this->sector($request));

        $gate = $this->entitlements->state($request->user('merchant_web'), $capability);
        if (($gate['state'] ?? null) === EntitlementService::AVAILABLE) return null;

        $status = match ($gate['state'] ?? '') {
            EntitlementService::LOCKED_BY_PLAN, EntitlementService::LIMIT_REACHED => 402,
            EntitlementService::COMING_SOON => 503,
            EntitlementService::NOT_APPLICABLE => 404,
            default => 403,
        };

        return response()->json([
            'success' => false,
            'code' => 'SECTOR_CAPABILITY_DENIED',
            'message' => 'هذه المساحة غير متاحة للقطاع أو الباقة الحالية.',
            'meta' => ['entitlement' => $gate],
        ], $status);
    }
}
