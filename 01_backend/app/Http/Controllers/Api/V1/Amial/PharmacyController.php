<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\Pharmacy;
use App\Models\PharmacyBatch;
use App\Models\PharmacyCategory;
use App\Models\PharmacyCustomer;
use App\Models\PharmacyProduct;
use App\Models\PharmacySale;
use App\Models\PharmacyStockAlert;
use App\Models\PosUser;
use App\Models\User;
use App\Services\PharmacyAlertService;
use App\Services\PharmacySaleService;
use App\Services\PharmacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-PHARMACY-001 — Controller الصيدلية.
 */
class PharmacyController extends Controller
{
    public function __construct(
        private readonly PharmacyService $svc,
        private readonly PharmacySaleService $saleSvc,
        private readonly PharmacyAlertService $alerts,
    ) {}

    // ============ Pharmacy ============

    public function getPharmacy(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        return $this->ok(['pharmacy' => $pharmacy]);
    }

    public function upsertPharmacy(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'pharmacy_name' => 'required|string|max:200',
            'license_number' => 'sometimes|nullable|string|max:64',
            'pharmacist_name' => 'sometimes|nullable|string|max:120',
            'pharmacist_license' => 'sometimes|nullable|string|max:64',
            'city' => 'sometimes|nullable|string|max:80',
            'address' => 'sometimes|nullable|string',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant, $request->all());
        return $this->ok(['pharmacy' => $pharmacy], 'SAVED', 'تم الحفظ');
    }

    // ============ Products ============

    public function listProducts(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $q = PharmacyProduct::where('pharmacy_id', $pharmacy->id)
            ->where('is_active', true);

        if ($request->filled('search')) {
            $s = $request->query('search');
            $q->where(function ($q) use ($s) {
                $q->where('trade_name', 'like', "%{$s}%")
                  ->orWhere('generic_name', 'like', "%{$s}%")
                  ->orWhere('barcode', $s)
                  ->orWhere('sku', $s);
            });
        }
        if ($request->filled('category_id')) {
            $q->where('category_id', $request->query('category_id'));
        }
        if ($request->boolean('low_stock_only')) {
            $q->whereColumn('current_stock', '<=', 'low_stock_threshold');
        }

        $products = $q->orderBy('trade_name')->limit(100)->get();
        return $this->ok(['products' => $products]);
    }

    public function addProduct(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'trade_name' => 'required|string|max:200',
            'generic_name' => 'sometimes|nullable|string|max:200',
            'manufacturer' => 'sometimes|nullable|string|max:120',
            'unit' => 'sometimes|nullable|string|max:32',
            'sku' => 'sometimes|nullable|string|max:64',
            'barcode' => 'sometimes|nullable|string|max:64',
            'sale_price' => 'required|numeric|min:0.01',
            'cost_price' => 'sometimes|nullable|numeric|min:0',
            'category_id' => 'sometimes|nullable|integer',
            'requires_prescription' => 'sometimes|boolean',
            'low_stock_threshold' => 'sometimes|integer|min:0',
            'description' => 'sometimes|nullable|string',
            'dosage_instructions' => 'sometimes|nullable|string',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        try {
            $product = $this->svc->addProduct($pharmacy, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['product' => $product], 'ADDED', 'تم الإضافة', 201);
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $product = PharmacyProduct::where('id', $id)
            ->where('pharmacy_id', $pharmacy->id)->first();
        if (!$product) return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);

        $updated = $this->svc->updateProduct($product, $request->all());
        return $this->ok(['product' => $updated], 'UPDATED', 'تم التحديث');
    }

    // ============ Batches ============

    public function listBatches(Request $request, int $productId): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $product = PharmacyProduct::where('id', $productId)
            ->where('pharmacy_id', $pharmacy->id)->first();
        if (!$product) return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);

        $batches = PharmacyBatch::where('product_id', $product->id)
            ->orderBy('expiry_date')
            ->get();

        return $this->ok([
            'product' => $product,
            'batches' => $batches,
        ]);
    }

    public function addBatch(Request $request, int $productId): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'batch_number' => 'required|string|max:64',
            'expiry_date' => 'required|date|after:today',
            'received_date' => 'sometimes|nullable|date',
            'quantity_received' => 'required|numeric|min:0.001',
            'cost_per_unit' => 'sometimes|nullable|numeric|min:0',
            'supplier_name' => 'sometimes|nullable|string|max:200',
            'supplier_invoice' => 'sometimes|nullable|string|max:64',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $product = PharmacyProduct::where('id', $productId)
            ->where('pharmacy_id', $pharmacy->id)->first();
        if (!$product) return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);

        try {
            $batch = $this->svc->addBatch($product, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['batch' => $batch], 'ADDED', 'تم إضافة الـ Batch', 201);
    }

    // ============ Customers ============

    public function listCustomers(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $q = PharmacyCustomer::where('pharmacy_id', $pharmacy->id)->where('is_active', true);
        if ($request->filled('search')) {
            $s = $request->query('search');
            $q->where(function ($q) use ($s) {
                $q->where('full_name', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%");
            });
        }
        return $this->ok(['customers' => $q->orderBy('full_name')->limit(50)->get()]);
    }

    public function findCustomerByPhone(Request $request): JsonResponse
    {
        $phone = $request->query('phone');
        if (!$phone) return $this->error('INVALID', 'الهاتف مطلوب', 422);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $customer = $this->svc->findCustomerByPhone($pharmacy, $phone);
        return $this->ok(['customer' => $customer]);
    }

    public function addCustomer(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'full_name' => 'required|string|max:200',
            'phone' => 'sometimes|nullable|string|max:32',
            'date_of_birth' => 'sometimes|nullable|date',
            'gender' => 'sometimes|nullable|in:male,female',
            'is_pregnant' => 'sometimes|boolean',
            'is_breastfeeding' => 'sometimes|boolean',
            'allergies' => 'sometimes|nullable|array',
            'chronic_conditions' => 'sometimes|nullable|array',
            'regular_medications' => 'sometimes|nullable|array',
            'notes' => 'sometimes|nullable|string',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        try {
            $customer = $this->svc->addCustomer($pharmacy, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['customer' => $customer], 'ADDED', 'تم الإضافة', 201);
    }

    public function updateCustomer(Request $request, int $id): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $customer = PharmacyCustomer::where('id', $id)
            ->where('pharmacy_id', $pharmacy->id)->first();
        if (!$customer) return $this->error('NOT_FOUND', 'العميل غير موجود', 404);

        $updated = $this->svc->updateCustomer($customer, $request->all());
        return $this->ok(['customer' => $updated], 'UPDATED', 'تم التحديث');
    }

    // ============ Sales (الجوهر) ============

    public function recordSale(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'customer_id' => 'sometimes|nullable|integer',
            'payment_method' => 'required|in:cash,amial_pay,credit',
            'paid_transaction_id' => 'sometimes|nullable|string|max:64',
            'prescription_number' => 'sometimes|nullable|string|max:64',
            'prescribing_doctor' => 'sometimes|nullable|string|max:200',
            'prescription_date' => 'sometimes|nullable|date',
            'discount_amount' => 'sometimes|nullable|numeric|min:0',
            'warnings_acknowledged' => 'sometimes|nullable|array',
            'notes' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posUserId] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        try {
            $sale = $this->saleSvc->recordSale(
                $merchant, $pharmacy, $posUserId,
                $request->input('items'),
                $request->all(),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('SALE_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['sale' => $sale], 'SALE_RECORDED', 'تم البيع', 201);
    }

    public function listSales(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $sales = PharmacySale::where('pharmacy_id', $pharmacy->id)
            ->with(['items', 'customer'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();
        return $this->ok(['sales' => $sales]);
    }

    // ============ Alerts ============

    public function listAlerts(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $alerts = PharmacyStockAlert::where('pharmacy_id', $pharmacy->id)
            ->where('status', 'active')
            ->with(['product', 'batch'])
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        return $this->ok([
            'alerts' => $alerts,
            'summary' => $this->alerts->summary($pharmacy),
        ]);
    }

    public function scanExpiringBatches(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $results = $this->alerts->scanExpiringBatches($pharmacy);
        return $this->ok(['scan' => $results]);
    }

    public function dismissAlert(Request $request, int $id): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $alert = PharmacyStockAlert::where('id', $id)
            ->where('pharmacy_id', $pharmacy->id)->first();
        if (!$alert) return $this->error('NOT_FOUND', 'التنبيه غير موجود', 404);

        $dismissed = $this->alerts->dismissAlert($alert);
        return $this->ok(['alert' => $dismissed], 'DISMISSED', 'تم الإغلاق');
    }

    // ============ Dashboard ============

    public function dashboard(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $today = now()->startOfDay();

        $todaySales = PharmacySale::where('pharmacy_id', $pharmacy->id)
            ->where('created_at', '>=', $today)
            ->where('status', 'completed');

        return $this->ok([
            'today' => [
                'sales_count' => (clone $todaySales)->count(),
                'total_amount' => (string)(clone $todaySales)->sum('total_amount'),
            ],
            'products_count' => PharmacyProduct::where('pharmacy_id', $pharmacy->id)
                ->where('is_active', true)->count(),
            'customers_count' => PharmacyCustomer::where('pharmacy_id', $pharmacy->id)
                ->where('is_active', true)->count(),
            'alerts_summary' => $this->alerts->summary($pharmacy),
        ]);
    }

    // ============ Helpers ============

    private function resolveMerchant(Request $request): array|JsonResponse
    {
        $authUser = $request->user();
        $pos = PosUser::where('user_id', $authUser->id)->where('is_active', true)->first();

        if ($pos) {
            $merchant = User::find($pos->merchant_user_id);
            if (!$merchant) return $this->error('MERCHANT_NOT_FOUND', 'التاجر غير موجود', 404);
            return [$merchant, $pos->id];
        }
        if (!MerchantProfile::where('user_id', $authUser->id)->exists()) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجار وموظفي الصيدلية فقط', 403);
        }
        return [$authUser, null];
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => (object)[],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'code' => 'VALIDATION_FAILED',
            'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[],
        ], 422);
    }
}
