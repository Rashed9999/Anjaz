<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Concerns\DeniesByPlan;
use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\PaymentRequest;
use App\Models\PosUser;
use App\Models\User;
use App\Models\WholesaleCollection;
use App\Models\WholesaleCustomer;
use App\Models\WholesaleInvoice;
use App\Models\WholesalePriceTier;
use App\Models\WholesaleProduct;
use App\Models\WholesaleProductLot;
use App\Models\WholesaleProductPrice;
use App\Models\WholesaleSalesRep;
use App\Models\WholesaleReturn;
use App\Services\Merchant\MerchantPermissionService;
use App\Services\MoneyService;
use App\Services\WholesaleCollectionService;
use App\Services\WholesaleInvoicePdfService;
use App\Services\WholesaleInvoiceService;
use App\Services\PaymentRequestService;
use App\Services\WholesaleReportsService;
use App\Services\WholesaleReturnService;
use App\Services\WholesaleService;
use App\Support\Merchant\MerchantPermissions as P;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-WHOLESALE-001 — Controller الجملة.
 *
 * ══════════════════════════════════════════════════════════════════════
 * AMIAL-VERTICAL-RBAC-001 — **والجملةُ ديونٌ قبل أن تكون بضاعة.**
 *
 * وقِيس قبل هذا التغيير: **خمسةٌ وعشرون فعلاً وصفرُ فحصِ صلاحيّة**.
 * فمندوبُ مبيعاتٍ يُبطل فاتورةَ مليونٍ بسببٍ يكتبه هو، ويُسجّل تحصيلاً لم
 * يُقبَض، ويُغيّر سعرَ صنفٍ لشريحة، ويُعدّل المخزونَ مباشرة. **ولا يمرّ
 * ذلك بأحد.**
 *
 * والفصلُ الحاكم هنا: **من يبيع لا يُحصّل، ومن يُحصّل لا يُبطل**.
 * وثلاثتُها في يدٍ واحدةٍ تُخفي اختلاساً بلا أثر: يُسجَّل بيعٌ، ويُقبَض
 * نقداً، ثمّ تُبطَل الفاتورة — فيختفي الدَّينُ والمقبوض معاً، ويتوازن
 * الدفترُ على نقصٍ لا يظهر.
 */
class WholesaleController extends Controller
{
    use DeniesByPlan;

    public function __construct(
        private readonly WholesaleService $svc,
        private readonly WholesaleInvoiceService $invSvc,
        private readonly WholesaleCollectionService $colSvc,
        private readonly WholesaleReportsService $reportsSvc,
        private readonly WholesaleReturnService $returnSvc,
        private readonly WholesaleInvoicePdfService $pdfSvc,
        private readonly PaymentRequestService $paymentRequestSvc,
        private readonly MerchantPermissionService $perm,
    ) {}

    /**
     * يفحص الصلاحيّة — ويردّ ٤٠٣ برسالةِ المحرّك، أو `null` فيمضي.
     *
     * **و`$amount` ليس زينة**: حدُّ الفاتورة وحدُّ التحصيل يُقاسان عليه
     * في `merchant_role_permissions.max_amount`. وتمريرُ `null` حيث يوجد
     * مبلغٌ يُلغي الحدَّ صامتاً — **حارسٌ يمرّ والعطلُ قائم.**
     */
    private function guard(Request $request, string $permission, ?string $amount = null): ?JsonResponse
    {
        try {
            $this->perm->assert($request->user(), $permission, [], $amount);

            return null;
        } catch (DomainException $e) {
            return $this->error('FORBIDDEN', $e->getMessage(), 403);
        }
    }

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

        if ($deny = $this->guard($request, P::SETTINGS_MANAGE)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::REPORT_SALES)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_TIER_MANAGE)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_PRODUCT_VIEW)) {
            return $deny;
        }
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
        // **«المنخفض فقط» مرشِّحٌ مدفوع** — والقائمةُ نفسُها مجّانيّة.
        // فالحارسُ على الفعل لا على العنوان، كما في تصدير Excel.
        if ($request->boolean('low_stock_only')
            && ($deny = $this->denyUnless($request, 'low_stock_alerts')) !== null) {
            return $deny;
        }

        if ($request->boolean('low_stock_only')) {
            $q->whereColumn('current_stock', '<=', 'low_stock_threshold');
        }

        return $this->ok(['products' => $q->orderBy('name')->limit(100)->get()]);
    }

    public function addProduct(Request $request): JsonResponse
    {

        if ($deny = $this->guard($request, P::WHOLESALE_PRODUCT_MANAGE)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_PRODUCT_MANAGE)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_STOCK_ADJUST)) {
            return $deny;
        }
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

    // ============ Units & Lots (server-owned wholesale stock truth) ============

    public function listProductUnits(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->guard($request, P::WHOLESALE_PRODUCT_VIEW)) return $deny;
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);
        $product = WholesaleProduct::where('business_id', $biz->id)->find($id);
        if (!$product) return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);
        return $this->ok(['product' => $product, 'units' => $this->svc->listUnits($product)]);
    }

    public function saveProductUnit(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->guard($request, P::WHOLESALE_PRODUCT_MANAGE)) return $deny;
        $v = Validator::make($request->all(), [
            'code' => 'required|string|max:32', 'name' => 'required|string|max:64',
            'factor_to_base' => 'required|numeric|gt:0', 'is_base' => 'sometimes|boolean',
        ]);
        if ($v->fails()) return $this->validationError($v);
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $product = WholesaleProduct::where('business_id', $this->svc->getOrCreateBusiness($merchant)->id)->find($id);
        if (!$product) return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);
        try { $unit = $this->svc->saveUnit($product, $request->all()); }
        catch (\InvalidArgumentException $e) { return $this->error('INVALID', $e->getMessage(), 422); }
        return $this->ok(['unit' => $unit], 'SAVED', 'تم حفظ وحدة التحويل');
    }

    public function listProductLots(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->guard($request, P::WHOLESALE_PRODUCT_VIEW)) return $deny;
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $product = WholesaleProduct::where('business_id', $this->svc->getOrCreateBusiness($merchant)->id)->find($id);
        if (!$product) return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);
        return $this->ok(['product' => $product, 'lots' => WholesaleProductLot::where('product_id', $product->id)
            ->orderByRaw('expiry_date is null')->orderBy('expiry_date')->orderBy('received_at')->get()]);
    }

    public function receiveProductLot(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->guard($request, P::WHOLESALE_STOCK_ADJUST)) return $deny;
        $v = Validator::make($request->all(), [
            'lot_number' => 'required|string|max:80', 'quantity' => 'required|numeric|gt:0',
            'unit_id' => 'sometimes|nullable|integer', 'location' => 'sometimes|nullable|string|max:120',
            'received_at' => 'sometimes|date', 'expiry_date' => 'sometimes|nullable|date',
            'cost_per_unit' => 'sometimes|nullable|numeric|min:0',
            'supplier_reference' => 'sometimes|nullable|string|max:120',
        ]);
        if ($v->fails()) return $this->validationError($v);
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $product = WholesaleProduct::where('business_id', $this->svc->getOrCreateBusiness($merchant)->id)->find($id);
        if (!$product) return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);
        try { $lot = $this->svc->receiveLot($product, $request->all()); }
        catch (\Throwable $e) { return $this->error('INVALID', $e->getMessage(), 422); }
        return $this->ok(['lot' => $lot, 'product' => $product->fresh()], 'RECEIVED', 'تم استلام الدفعة وإضافتها للمخزون', 201);
    }

    // ============ Multi-Pricing ============

    public function listProductPrices(Request $request, int $productId): JsonResponse
    {

        if ($deny = $this->guard($request, P::WHOLESALE_PRICE_VIEW)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_PRICE_SET)) {
            return $deny;
        }
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

    /**
     * معاينة السعر حسب شريحة العميل والكمية.
     * هذه للعرض فقط؛ createInvoice يعيد التسعير تحت القفل ولا يثق بها.
     */
    public function quoteProduct(Request $request, int $productId): JsonResponse
    {
        if ($deny = $this->guard($request, P::WHOLESALE_PRODUCT_VIEW)) {
            return $deny;
        }
        $v = Validator::make($request->query(), [
            'customer_id' => 'required|integer',
            'quantity' => 'required|numeric|min:0.001',
            'unit_id' => 'sometimes|nullable|integer',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);
        $customer = WholesaleCustomer::where('id', $request->query('customer_id'))
            ->where('business_id', $biz->id)->where('is_active', true)->first();
        $product = WholesaleProduct::where('id', $productId)
            ->where('business_id', $biz->id)->where('is_active', true)->first();
        if (!$customer || !$product) return $this->error('NOT_FOUND', 'العميل أو المنتج غير موجود', 404);

        $tierId = $customer->default_tier_id ?? WholesalePriceTier::where('business_id', $biz->id)
            ->where('is_default', true)->where('is_active', true)->value('id');
        if (!$tierId) return $this->error('PRICE_TIER_MISSING', 'لا توجد شريحة سعر افتراضية', 422);
        try {
            $unit = $this->svc->unitFor($product, $request->query('unit_id'));
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_UNIT', $e->getMessage(), 422);
        }
        $quantity = \App\Services\MoneyService::normalize((string) $request->query('quantity'));
        $baseQuantity = \App\Services\MoneyService::mul($quantity, (string) $unit->factor_to_base);
        $basePrice = \App\Services\MoneyService::normalize((string) $product->priceFor((int) $tierId, (float) $baseQuantity));
        $unitPrice = \App\Services\MoneyService::mul($basePrice, (string) $unit->factor_to_base);

        return $this->ok(['quote' => [
            'product_id' => $product->id,
            'customer_id' => $customer->id,
            'tier_id' => (int) $tierId,
            'unit_id' => $unit->id,
            'unit' => $unit->name,
            'quantity' => $quantity,
            'base_quantity' => $baseQuantity,
            'unit_price' => $unitPrice,
            'line_total' => \App\Services\MoneyService::mul($unitPrice, $quantity),
        ]]);
    }

    // ============ Customers ============

    public function listCustomers(Request $request): JsonResponse
    {

        if ($deny = $this->guard($request, P::WHOLESALE_CUSTOMER_VIEW)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_CUSTOMER_MANAGE)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_CUSTOMER_MANAGE)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_INVOICE_VIEW)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_INVOICE_VIEW)) {
            return $deny;
        }
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
        if ($deny = $this->guard($request, P::WHOLESALE_INVOICE_CREATE)) {
            return $deny;
        }

        $v = Validator::make($request->all(), [
            'customer_id' => 'required|integer',
            'payment_type' => 'required|in:cash,amial_pay,credit',
            'paid_transaction_id' => 'required_if:payment_type,amial_pay|nullable|string|max:64',
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
        [$merchant, $posUserId] = $ctx + [1 => null];

        // ══════════════════════════════════════════════════════════════
        // AMIAL-SHIFT-GATE-002 — **فاتورةُ الجملة النقديّة نقدٌ في الدرج.**
        //
        // أرسل صاحبُ المشروع: «حساب تاجر جملة أنشأ فاتورةً دون أن يطلب
        // فتحَ ورديّة، إذن النظامُ غائبٌ عنه». وقِيس فكان محقّاً:
        // `wholesale/invoices` بلا حارس، **و`payment_type` يقبل `cash`**
        // — أي نقدٌ يُقبَض في اللحظة.
        //
        // **والوسيطُ لا يُركَّب على المسار** لأنّه لا يقرأ جسمَ الطلب:
        // `credit` دينٌ لا يمسّ الدرج، فطلبُ ورديّةٍ له حاجزٌ يشلّ عملاً
        // سليماً — وهو أسوأ من ثغرة. فالحكمُ على الشرط، **من المصدر
        // نفسِه** (`EnsureOpenShift::refusalFor`) لا برسالةٍ ثانية:
        // رمزان مختلفان يجعلان الشاشةَ تفتح نافذةَ الورديّة في بابٍ
        // وتعرض عطلاً في الآخر.
        //
        // (والتحصيلُ `collect` محروسٌ سلفاً بالوسيط — وهو البابُ الثاني
        // لنقد الجملة. فصار البابان تحت حكمٍ واحد.)
        // ══════════════════════════════════════════════════════════════
        if ($request->input('payment_type') === 'cash') {
            $refusal = app(\App\Http\Middleware\EnsureOpenShift::class)
                ->refusalFor($merchant, $posUserId);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        $biz = $this->svc->getOrCreateBusiness($merchant);

        // P1-BRANCHES — حلّ الفرع النشط تلقائياً
        $branchId = app(\App\Services\BranchResolverService::class)
            ->resolveBranchId($request, $merchant);

        // ══════════════════════════════════════════════════════════════
        // **وحدُّ الفاتورة يُقاس على إجماليّها لا على ما أُرسل.**
        //
        // الإجماليُّ لا يعرفه المنادي: الأسعارُ من شريحة العميل والضريبةُ
        // من إعدادات المؤسّسة، والخدمةُ هي التي تحسبه. **ففحصُ الحدّ قبل
        // الحساب فحصٌ على رقمٍ لا وجود له** — يمرّ دائماً.
        //
        // فيُبنى القيدُ ثمّ يُقاس داخل معاملةٍ واحدة: إن تجاوز الحدَّ رُدّ
        // الاستثناءُ فتُلغى المعاملةُ كلُّها، **فلا تبقى فاتورةٌ فوق
        // الحدّ ولا رقمٌ متسلسلٌ محروق.**
        try {
            $inv = \Illuminate\Support\Facades\DB::transaction(function () use (
                $request, $merchant, $biz, $branchId,
            ) {
                $inv = $this->invSvc->createInvoice(
                    $merchant, $biz, $request->input('items'),
                    array_merge($request->all(), [
                        'branch_id' => $branchId,
                        // المحاسبة تعرف مالك المتجر، والمراجعة تعرف الموظف
                        // أو مالك الحساب الذي أصدر الفاتورة فعلاً.
                        'created_by_user_id' => $request->user()->id,
                    ]),
                );

                $this->perm->assert($request->user(), P::WHOLESALE_INVOICE_CREATE,
                    [], (string) $inv->total_amount);

                return $inv;
            });
        } catch (DomainException $e) {
            return $this->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['invoice' => $inv], 'CREATED', 'تم إنشاء الفاتورة', 201);
    }

    /**
     * ينشئ QR تحصيل جملة باسم محفظة مالك التاجر دائماً. لا نستخدم endpoint
     * طلب المال العام هنا، لأن جلسة POS كانت ستنشئ الطلب باسم الموظف فتذهب
     * الحصيلة إلى محفظته بدلاً من محفظة التاجر.
     */
    public function createInvoicePaymentRequest(Request $request): JsonResponse
    {
        if ($deny = $this->guard($request, P::WHOLESALE_INVOICE_CREATE)) return $deny;

        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01|lt:100000000',
            'note' => 'sometimes|nullable|string|max:255',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        try {
            $paymentRequest = $this->paymentRequestSvc->create(
                requester: $merchant,
                amount: (string) $request->input('amount'),
                note: $request->input('note') ?: 'تحصيل فاتورة جملة',
                shareMethod: 'qr',
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_PAYMENT_REQUEST', $e->getMessage(), 422);
        }

        return $this->ok([
            'request' => $paymentRequest,
            'short_code' => $paymentRequest->short_code,
        ], 'PAYMENT_REQUEST_CREATED', 'تم إنشاء طلب تحصيل أميال باي', 201);
    }

    /** ينشئ تحصيل QR لدين قائم بعد التأكد من أن المبلغ لا يتجاوز المتبقي. */
    public function createCollectionPaymentRequest(Request $request, int $invoiceId): JsonResponse
    {
        if ($deny = $this->guard($request, P::WHOLESALE_COLLECTION_RECORD,
            $request->filled('amount') ? (string) $request->input('amount') : null)) return $deny;

        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01|lt:100000000',
            'note' => 'sometimes|nullable|string|max:255',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);
        $invoice = WholesaleInvoice::where('id', $invoiceId)->where('business_id', $biz->id)->first();
        if (!$invoice) return $this->error('NOT_FOUND', 'الفاتورة غير موجودة', 404);
        if ($invoice->status === 'voided' || MoneyService::compare((string) $invoice->balance_due, '0') <= 0) {
            return $this->error('INVOICE_NOT_COLLECTABLE', 'لا يوجد رصيد قابل للتحصيل في هذه الفاتورة', 422);
        }
        if (MoneyService::compare((string) $request->input('amount'), (string) $invoice->balance_due) > 0) {
            return $this->error('AMOUNT_EXCEEDS_BALANCE', 'مبلغ التحصيل يتجاوز المتبقي في الفاتورة', 422);
        }

        try {
            $paymentRequest = $this->paymentRequestSvc->create(
                requester: $merchant,
                amount: (string) $request->input('amount'),
                note: $request->input('note') ?: "تحصيل فاتورة جملة {$invoice->invoice_number}",
                shareMethod: 'qr',
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_PAYMENT_REQUEST', $e->getMessage(), 422);
        }
        return $this->ok([
            'request' => $paymentRequest,
            'short_code' => $paymentRequest->short_code,
        ], 'PAYMENT_REQUEST_CREATED', 'تم إنشاء طلب تحصيل أميال باي', 201);
    }

    /** إلغاء QR جملة أنشئ باسم المالك عندما تكون الجلسة لموظف نقطة بيع. */
    public function cancelWholesalePaymentRequest(Request $request, int $requestId): JsonResponse
    {
        // ══════════════════════════════════════════════════════════════
        // **السياسةُ تُعلن الصلاحيّةَ لهذا الفعل والنقطةُ لا تسألها.**
        //
        // `WholesaleAccessPolicyService` فيها `payment_request.cancel`
        // مربوطاً بـ`WHOLESALE_INVOICE_CREATE` — ومع ذلك كانت هذه
        // الدالّةُ تلغي **طلبَ تحصيلِ مال** بلا فحصٍ واحد.
        //
        // وإخفاءُ الزرّ في الواجهة ليس أماناً: من يعرف المسارَ يناديه بلا
        // زرّ. (القاعدةُ الثامنة.) وموظّفُ مبيعاتٍ لا يملك إنشاءَ فاتورة
        // كان يستطيع إلغاءَ تحصيلِ غيره.
        //
        // والصلاحيّةُ **تُؤخَذ من السياسة لا تُخترَع** — فقيمتان لفعلٍ
        // واحدٍ في موضعين تفترقان مع أوّل تعديل.
        // ══════════════════════════════════════════════════════════════
        if ($deny = $this->guard($request, P::WHOLESALE_INVOICE_CREATE)) return $deny;

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $paymentRequest = PaymentRequest::where('id', $requestId)
            ->where('requester_user_id', $merchant->id)
            ->first();
        if (!$paymentRequest) return $this->error('NOT_FOUND', 'طلب التحصيل غير موجود', 404);
        try {
            $cancelled = $this->paymentRequestSvc->cancel($merchant, $paymentRequest);
        } catch (\InvalidArgumentException $e) {
            return $this->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (\RuntimeException $e) {
            return $this->error('CANCEL_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['request' => $cancelled], 'CANCELLED', 'تم إلغاء طلب التحصيل');
    }

    public function voidInvoice(Request $request, int $id): JsonResponse
    {

        if ($deny = $this->guard($request, P::WHOLESALE_INVOICE_VOID)) {
            return $deny;
        }
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

    // ============ Returns ============

    public function listReturns(Request $request): JsonResponse
    {
        if ($deny = $this->guard($request, P::WHOLESALE_RETURN_VIEW)) return $deny;
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);
        $q = WholesaleReturn::where('business_id', $biz->id)
            ->with(['invoice:id,invoice_number', 'customer:id,full_name', 'items']);
        if ($request->filled('status') && in_array($request->query('status'), WholesaleReturn::STATUSES, true)) {
            $q->where('status', $request->query('status'));
        }
        return $this->ok(['returns' => $q->orderByDesc('id')->limit(100)->get()]);
    }

    public function requestReturn(Request $request, int $invoiceId): JsonResponse
    {
        if ($deny = $this->guard($request, P::WHOLESALE_RETURN_REQUEST)) return $deny;
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.invoice_item_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.001',
        ]);
        if ($v->fails()) return $this->validationError($v);
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);
        $invoice = WholesaleInvoice::where('id', $invoiceId)->where('business_id', $biz->id)->first();
        if (!$invoice) return $this->error('NOT_FOUND', 'الفاتورة غير موجودة', 404);
        try {
            $return = $this->returnSvc->request($request->user(), $invoice, $request->input('items'), $request->input('reason'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error('INVALID_RETURN', $e->getMessage(), 422);
        }
        return $this->ok(['return' => $return], 'RETURN_REQUESTED', 'تم إرسال طلب المرتجع للمراجعة', 201);
    }

    public function resolveReturn(Request $request, int $returnId): JsonResponse
    {
        if ($deny = $this->guard($request, P::WHOLESALE_RETURN_APPROVE)) return $deny;
        $v = Validator::make($request->all(), [
            'approve' => 'required|boolean',
            'decision_note' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);
        $return = WholesaleReturn::where('id', $returnId)->where('business_id', $biz->id)->first();
        if (!$return) return $this->error('NOT_FOUND', 'طلب المرتجع غير موجود', 404);
        try {
            $resolved = $this->returnSvc->resolve($request->user(), $return, $request->boolean('approve'), $request->input('decision_note'));
        } catch (\RuntimeException $e) {
            return $this->error('INVALID_RETURN', $e->getMessage(), 422);
        }
        return $this->ok(['return' => $resolved], 'RETURN_RESOLVED', 'تم حفظ قرار المرتجع');
    }

    // ============ Collections ============

    public function recordCollection(Request $request, int $invoiceId): JsonResponse
    {
        // **والمبلغُ هنا في الطلب** — بخلاف الفاتورة. فيُقاس عليه الحدُّ
        // قبل أن يُلمَس شيء.
        if ($deny = $this->guard($request, P::WHOLESALE_COLLECTION_RECORD,
            $request->filled('amount') ? (string) $request->input('amount') : null)) {
            return $deny;
        }

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
            // المحفظة والحساب المالي للمنشأة، لكن أثر التحصيل يجب أن يحمل
            // الموظف الذي استلم المال فعلاً؛ لا نأخذه من جسم طلب العميل.
            $data = $request->all();
            $data['received_by_user_id'] = $request->user()->id;
            $col = $this->colSvc->recordCollection($merchant, $inv, $data);
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['collection' => $col], 'RECORDED', 'تم تسجيل التحصيل', 201);
    }

    public function listCollections(Request $request): JsonResponse
    {

        if ($deny = $this->guard($request, P::WHOLESALE_COLLECTION_VIEW)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_REP_VIEW)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_REP_MANAGE)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_INVOICE_VIEW)) {
            return $deny;
        }
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
            // AMIAL-PDF-CACHE-001: كانت تُصيَّر في كل طلب ولا تُخزَّن — فاتورة
            // يفتحها التاجر عشر مرّات تُصيَّر عشراً، وكلّ تصيير يعرّض الاتصال
            // للقطع على شبكة جوّال. updated_at في المفتاح: تعديل الفاتورة
            // يُنتج مفتاحاً جديداً، فلا تُخدَم نسخةٌ قديمة بأرقام قديمة.
            $pdfBytes = app(\App\Services\PdfCacheService::class)->remember(
                "wholesale_invoice_{$inv->id}_{$inv->updated_at?->timestamp}",
                fn () => $this->pdfSvc->generate($inv),
            );
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
            // خاصّ لا عامّ: الفاتورة تخصّ تاجراً بعينه فلا تُخزَّن في وسيط
            // مشترك. لكن منعُ تخزينها على الجهاز كان يُجبر على تنزيلها كاملةً
            // في كل مرّة بلا داعٍ.
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    // ============ Reports ============

    public function agingReport(Request $request): JsonResponse
    {

        if ($deny = $this->guard($request, P::WHOLESALE_REPORT_VIEW)) {
            return $deny;
        }
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $biz = $this->svc->getOrCreateBusiness($merchant);

        return $this->ok(['report' => $this->reportsSvc->agingReport($biz)]);
    }

    public function customerStatement(Request $request, int $customerId): JsonResponse
    {

        if ($deny = $this->guard($request, P::WHOLESALE_REPORT_VIEW)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::WHOLESALE_REPORT_VIEW)) {
            return $deny;
        }
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

        // موظف الجملة ليس بالضرورة حساب POS. أدوار المبيعات والتحصيل
        // والمحاسبة تسكن merchant_user_roles وتَرِث المنشأة والباقة من
        // مالكها، ثم تُقيَّد لاحقاً بـ MerchantPermissionService. رفضها هنا
        // لأن لا MerchantProfile باسم الموظف يجعل شاشة صحيحة تنتهي 403.
        $assignment = \Illuminate\Support\Facades\DB::table('merchant_user_roles')
            ->where('user_id', $authUser->id)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();
        if ($assignment !== null) {
            $merchant = User::find($assignment->merchant_user_id);
            if (!$merchant) return $this->error('MERCHANT_NOT_FOUND', 'التاجر غير موجود', 404);
            return [$merchant, null];
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
