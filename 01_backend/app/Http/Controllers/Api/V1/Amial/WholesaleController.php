<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\PosUser;
use App\Models\User;
use App\Models\WholesaleCollection;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleInvoice;
use App\Models\WholesalePriceTier;
use App\Models\WholesaleProduct;
use App\Models\WholesaleProductPrice;
use App\Models\WholesaleSalesRep;
use App\Services\WholesaleCollectionService;
use App\Services\WholesaleInvoicePdfService;
use App\Services\WholesaleInvoiceService;
use App\Services\WholesaleReportsService;
use App\Services\WholesaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-WHOLESALE-001 — Controller الجملة.
 */
class WholesaleController extends Controller
{
    public function __construct(
        private readonly WholesaleService $svc,
        private readonly WholesaleInvoiceService $invSvc,
        private readonly WholesaleCollectionService $colSvc,
        private readonly WholesaleReportsService $reportsSvc,
        private readonly WholesaleInvoicePdfService $pdfSvc,
    ) {}

    // ============ Business ============

    public function getBusiness(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $biz = $this->svc->getOrCreateBusiness($merchant);
        $biz->load('priceTiers');
        return $this->ok(['business' => $biz]);
    }

    public function upsertBusiness(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'business_name' => 'required|string|max:200',
            'commercial_register' => 'sometimes|nullable|string|max:64',
            'tax_number' => 'sometimes|nullable|string|max:64',
            'city' => 'sometimes|nullable|string|max:80',
            'address' => 'sometimes|nullable|string',
            'phone' => 'sometimes|nullable|string|max:32',
            'email' => 'sometimes|nullable|email|max:120',
            'default_tax_rate' => 'sometimes|numeric|min:0|max:100',
            'invoice_prefix' => 'sometimes|nullable|string|max:16',
            'default_payment_terms_days' => 'sometimes|integer|min:0|max:365',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $biz = $this->svc->getOrCreateBusiness($merchant, $request->all());
        return $this->ok(['business' => $biz->load('priceTiers')], 'SAVED', 'تم الحفظ');
    }

    public function dashboard(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $biz = $this->svc->getOrCreateBusiness($merchant);
        $today = now()->startOfDay();

        $todayInvoices = WholesaleInvoice::where('business_id', $biz->id)
            ->where('created_at', '>=', $today)
            ->where('status', '!=', 'voided');

        $overdueQ = WholesaleInvoice::where('business_id', $biz->id)
            ->whereIn('status', ['issued', 'partial_paid'])
            ->where('due_date', '<', now()->toDateString())
            ->where('balance_due', '>', 0);

        $totalReceivable = WholesaleInvoice::where('business_id', $biz->id)
            ->whereIn('status', ['issued', 'partial_paid', 'overdue'])
            ->sum('balance_due');

        return $this->ok([
            'today' => [
                'invoices_count' => (clone $todayInvoices)->count(),
                'total_amount' => (string)(clone $todayInvoices)->sum('total_amount'),
            ],
            'products_count' => WholesaleProduct::where('business_id', $biz->id)
                ->where('is_active', true)->count(),
            'customers_count' => WholesaleCustomer::where('business_id', $biz->id)
                ->where('is_active', true)->count(),
            'sales_reps_count' => WholesaleSalesRep::where('business_id', $biz->id)
                ->where('is_active', true)->count(),
            'overdue_count' => (clone $overdueQ)->count(),
            'overdue_amount' => (string)(clone $overdueQ)->sum('balance_due'),
            'total_receivable' => (string)$totalReceivable,
        ]);
    }

    // ============ Price Tiers ============

    public function addPriceTier(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'code' => 'required|string|max:32',
            'name' => 'required|string|max:80',
            'sort_order' => 'sometimes|integer',
            'is_default' => 'sometimes|boolean',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        try {
            $tier = $this->svc->addPriceTier($biz, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['tier' => $tier], 'ADDED', 'تم الإضافة', 201);
    }

    // ============ Products ============

    public function listProducts(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $q = WholesaleProduct::where('business_id', $biz->id)->where('is_active', true);
        if ($request->filled('search')) {
            $s = $request->query('search');
            $q->where(fn($q) => $q->where('name', 'like', "%{$s}%")
                ->orWhere('barcode', $s)->orWhere('sku', $s));
        }
        if ($request->boolean('low_stock_only')) {
            $q->whereColumn('current_stock', '<=', 'low_stock_threshold');
        }

        return $this->ok(['products' => $q->orderBy('name')->limit(100)->get()]);
    }

    public function addProduct(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:200',
            'sku' => 'sometimes|nullable|string|max:64',
            'barcode' => 'sometimes|nullable|string|max:64',
            'manufacturer' => 'sometimes|nullable|string|max:120',
            'unit' => 'sometimes|nullable|string|max:32',
            'base_price' => 'required|numeric|min:0.01',
            'cost_price' => 'sometimes|nullable|numeric|min:0',
            'low_stock_threshold' => 'sometimes|integer|min:0',
            'initial_stock' => 'sometimes|numeric|min:0',
            'description' => 'sometimes|nullable|string',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        try {
            $p = $this->svc->addProduct($biz, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['product' => $p], 'ADDED', 'تم الإضافة', 201);
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $product = WholesaleProduct::where('id', $id)
            ->where('business_id', $biz->id)->first();
        if (!$product) return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);

        $updated = $this->svc->updateProduct($product, $request->all());
        return $this->ok(['product' => $updated], 'UPDATED', 'تم التحديث');
    }

    public function adjustStock(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'new_stock' => 'required|numeric|min:0',
            'reason' => 'required|string|max:200',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $product = WholesaleProduct::where('id', $id)
            ->where('business_id', $biz->id)->first();
        if (!$product) return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);

        try {
            $updated = $this->svc->adjustStock($product,
                (float)$request->input('new_stock'),
                $request->input('reason'));
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['product' => $updated], 'ADJUSTED', 'تم تعديل المخزون');
    }

    // ============ Multi-Pricing ============

    public function listProductPrices(Request $request, int $productId): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $product = WholesaleProduct::where('id', $productId)
            ->where('business_id', $biz->id)->first();
        if (!$product) return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);

        $prices = WholesaleProductPrice::where('product_id', $product->id)
            ->with('tier:id,code,name')
            ->orderBy('tier_id')->orderBy('min_quantity')->get();

        return $this->ok([
            'product' => $product,
            'prices' => $prices,
            'tiers' => WholesalePriceTier::where('business_id', $biz->id)
                ->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }

    public function setProductPrice(Request $request, int $productId): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'tier_id' => 'required|integer',
            'price' => 'required|numeric|min:0.01',
            'min_quantity' => 'sometimes|numeric|min:0.001',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $product = WholesaleProduct::where('id', $productId)
            ->where('business_id', $biz->id)->first();
        if (!$product) return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);

        try {
            $price = $this->svc->setProductPrice(
                $product,
                (int)$request->input('tier_id'),
                (float)$request->input('price'),
                (float)$request->input('min_quantity', 1),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['price' => $price], 'SAVED', 'تم الحفظ');
    }

    // ============ Customers ============

    public function listCustomers(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $q = WholesaleCustomer::where('business_id', $biz->id)->where('is_active', true);
        if ($request->filled('search')) {
            $s = $request->query('search');
            $q->where(fn($q) => $q->where('full_name', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")
                ->orWhere('company_name', 'like', "%{$s}%"));
        }
        if ($request->boolean('with_balance_only')) {
            $q->where('current_balance', '>', 0);
        }

        return $this->ok(['customers' => $q->orderBy('full_name')->limit(100)->get()]);
    }

    public function addCustomer(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'full_name' => 'required|string|max:200',
            'company_name' => 'sometimes|nullable|string|max:200',
            'phone' => 'sometimes|nullable|string|max:32',
            'email' => 'sometimes|nullable|email|max:120',
            'city' => 'sometimes|nullable|string|max:80',
            'address' => 'sometimes|nullable|string',
            'tax_number' => 'sometimes|nullable|string|max:64',
            'default_tier_id' => 'sometimes|nullable|integer',
            'credit_limit' => 'sometimes|numeric|min:0',
            'payment_terms_days' => 'sometimes|integer|min:0|max:365',
            'notes' => 'sometimes|nullable|string',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        try {
            $c = $this->svc->addCustomer($biz, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['customer' => $c], 'ADDED', 'تم الإضافة', 201);
    }

    public function updateCustomer(Request $request, int $id): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $c = WholesaleCustomer::where('id', $id)->where('business_id', $biz->id)->first();
        if (!$c) return $this->error('NOT_FOUND', 'العميل غير موجود', 404);

        $updated = $this->svc->updateCustomer($c, $request->all());
        return $this->ok(['customer' => $updated], 'UPDATED', 'تم التحديث');
    }

    // ============ Invoices ============

    public function listInvoices(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $q = WholesaleInvoice::where('business_id', $biz->id)->with('customer:id,full_name,company_name');
        if ($request->filled('customer_id')) $q->where('customer_id', $request->query('customer_id'));
        if ($request->filled('status')) $q->where('status', $request->query('status'));
        if ($request->boolean('overdue_only')) {
            $q->whereIn('status', ['issued', 'partial_paid'])
              ->where('due_date', '<', now()->toDateString())
              ->where('balance_due', '>', 0);
        }

        return $this->ok(['invoices' => $q->orderByDesc('id')->limit(50)->get()]);
    }

    public function showInvoice(Request $request, int $id): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $inv = WholesaleInvoice::where('id', $id)->where('business_id', $biz->id)
            ->with(['items', 'customer', 'salesRep', 'collections'])->first();
        if (!$inv) return $this->error('NOT_FOUND', 'الفاتورة غير موجودة', 404);

        return $this->ok(['invoice' => $inv]);
    }

    public function createInvoice(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'customer_id' => 'required|integer',
            'payment_type' => 'required|in:cash,credit',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'items.*.discount_per_unit' => 'sometimes|numeric|min:0',
            'sales_rep_id' => 'sometimes|nullable|integer',
            'discount_amount' => 'sometimes|numeric|min:0',
            'tax_rate' => 'sometimes|numeric|min:0|max:100',
            'due_date' => 'sometimes|nullable|date',
            'notes' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        // P1-BRANCHES — حلّ الفرع النشط تلقائياً
        $branchId = app(\App\Services\BranchResolverService::class)
            ->resolveBranchId($request, $merchant);

        try {
            $inv = $this->invSvc->createInvoice(
                $merchant, $biz, $request->input('items'),
                array_merge($request->all(), ['branch_id' => $branchId]),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['invoice' => $inv], 'CREATED', 'تم إنشاء الفاتورة', 201);
    }

    public function voidInvoice(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $inv = WholesaleInvoice::where('id', $id)->where('business_id', $biz->id)->first();
        if (!$inv) return $this->error('NOT_FOUND', 'الفاتورة غير موجودة', 404);

        try {
            $voided = $this->invSvc->voidInvoice($inv, $request->input('reason'));
        } catch (\RuntimeException $e) {
            return $this->error('FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['invoice' => $voided], 'VOIDED', 'تم الإبطال');
    }

    // ============ Collections ============

    public function recordCollection(Request $request, int $invoiceId): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,amial_pay,check',
            'collection_date' => 'sometimes|nullable|date',
            'reference_number' => 'sometimes|nullable|string|max:64',
            'paid_transaction_id' => 'sometimes|nullable|string|max:64',
            'notes' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $inv = WholesaleInvoice::where('id', $invoiceId)->where('business_id', $biz->id)->first();
        if (!$inv) return $this->error('NOT_FOUND', 'الفاتورة غير موجودة', 404);

        try {
            $col = $this->colSvc->recordCollection($merchant, $inv, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['collection' => $col], 'RECORDED', 'تم تسجيل التحصيل', 201);
    }

    public function listCollections(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $q = WholesaleCollection::where('business_id', $biz->id)
            ->with(['invoice:id,invoice_number,total_amount', 'customer:id,full_name']);
        if ($request->filled('customer_id')) $q->where('customer_id', $request->query('customer_id'));
        if ($request->filled('invoice_id')) $q->where('invoice_id', $request->query('invoice_id'));

        return $this->ok(['collections' => $q->orderByDesc('id')->limit(50)->get()]);
    }

    // ============ Sales Reps ============

    public function listSalesReps(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        return $this->ok([
            'sales_reps' => WholesaleSalesRep::where('business_id', $biz->id)
                ->where('is_active', true)->orderBy('full_name')->get(),
        ]);
    }

    public function addSalesRep(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'full_name' => 'required|string|max:200',
            'phone' => 'sometimes|nullable|string|max:32',
            'default_commission_rate' => 'sometimes|numeric|min:0|max:100',
            'user_id' => 'sometimes|nullable|integer',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        try {
            $rep = $this->svc->addSalesRep($biz, $request->all());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['sales_rep' => $rep], 'ADDED', 'تم الإضافة', 201);
    }

    // ============ PDF Generation ============

    /**
     * توليد + تحميل PDF لفاتورة محدّدة.
     * يرجع Response بـ binary PDF + headers صحيحة للتحميل.
     */
    public function downloadInvoicePdf(Request $request, int $id)
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $inv = WholesaleInvoice::where('id', $id)
            ->where('business_id', $biz->id)
            ->with(['items', 'customer', 'salesRep', 'collections'])
            ->first();
        if (!$inv) return $this->error('NOT_FOUND', 'الفاتورة غير موجودة', 404);

        try {
            $pdfBytes = $this->pdfSvc->generate($inv);
        } catch (\Throwable $e) {
            \Log::error('Wholesale PDF gen failed', [
                'invoice_id' => $inv->id, 'error' => $e->getMessage(),
            ]);
            return $this->error('PDF_GEN_FAILED', 'تعذّر توليد الـ PDF', 500);
        }

        $filename = $this->pdfSvc->suggestedFilename($inv);
        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($pdfBytes),
            'Cache-Control' => 'no-store',
        ]);
    }

    // ============ Reports ============

    public function agingReport(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        return $this->ok(['report' => $this->reportsSvc->agingReport($biz)]);
    }

    public function customerStatement(Request $request, int $customerId): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $customer = WholesaleCustomer::where('id', $customerId)
            ->where('business_id', $biz->id)->first();
        if (!$customer) return $this->error('NOT_FOUND', 'العميل غير موجود', 404);

        $from = $request->filled('from') ? \Carbon\Carbon::parse($request->query('from')) : null;
        $to = $request->filled('to') ? \Carbon\Carbon::parse($request->query('to')) : null;

        return $this->ok(['statement' => $this->reportsSvc->customerStatement($customer, $from, $to)]);
    }

    public function salesRepsPerformance(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        $from = $request->filled('from') ? \Carbon\Carbon::parse($request->query('from')) : null;
        $to = $request->filled('to') ? \Carbon\Carbon::parse($request->query('to')) : null;

        return $this->ok(['report' => $this->reportsSvc->salesRepsPerformance($biz, $from, $to)]);
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
            return $this->error('NOT_A_MERCHANT', 'متاح للتجار وموظفي الجملة فقط', 403);
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
