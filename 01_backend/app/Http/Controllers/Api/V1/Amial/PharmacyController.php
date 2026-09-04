<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Concerns\DeniesByPlan;
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
use App\Services\Merchant\MerchantPermissionService;
use App\Services\PharmacyAlertService;
use App\Services\PharmacySaleService;
use App\Services\PharmacyService;
use App\Support\Merchant\MerchantPermissions as P;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-PHARMACY-001 — Controller الصيدلية.
 *
 * ══════════════════════════════════════════════════════════════════════
 * AMIAL-VERTICAL-RBAC-001 — **وكلُّ فعلٍ خلفَه صلاحيّة.**
 *
 * وقِيس قبل هذا التغيير: **سبعةَ عشرَ فعلاً وصفرُ فحصِ صلاحيّة**. فكلُّ
 * موظّفٍ نشِطٍ في `pos_users` يبلغ كلَّ باب: يُعدّل الأصناف، ويستلم
 * التشغيلات، **ويُغلق تنبيهَ انتهاء صلاحيّة**، ويقرأ ويكتب السجلَّ
 * الطبّيّ للمريض — حساسيّاتِه وأمراضَه المزمنة وحملَه.
 *
 * ولا خطأَ في أيّ سجلّ: الطلبُ ينجح ويردّ ٢٠٠. **والقالبُ الذي كان
 * يُزرع للصيدليّة أدوارُ تجزئة** — «مدير متجر · موظّف مستودع · مندوب
 * مبيعات» — فيها أسماءُ وظائفَ لا وجودَ لها في صيدليّة، وصلاحيّاتٌ **لا
 * يقرؤها متحكّمٌ واحد**. فكان قالباً بلا أثر، وذاك أسوأ من غيابه:
 * **يُوهم بضبطٍ لا وجود له.**
 *
 * وإخفاءُ الزرّ في الواجهة ليس أماناً: من يعرف المسار ينادي بلا زرّ.
 */
class PharmacyController extends Controller
{
    use DeniesByPlan;

    public function __construct(
        private readonly PharmacyService $svc,
        private readonly PharmacySaleService $saleSvc,
        private readonly PharmacyAlertService $alerts,
        private readonly MerchantPermissionService $perm,
    ) {}

    /**
     * يفحص الصلاحيّة — ويردّ ٤٠٣ برسالةٍ تقول لماذا، أو `null` فيمضي.
     *
     * **والرسالةُ من المحرّك لا مخترَعة**: «خارج نطاقك» غير «يتجاوز حدّك»
     * غير «لا تملك الصلاحيّة»، ورفضٌ لا يقول سببَه يُرسل الموظّفَ إلى
     * الدعم بلا معلومة.
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

        if ($deny = $this->guard($request, P::SETTINGS_MANAGE)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::PHARMACY_PRODUCT_VIEW)) {
            return $deny;
        }
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
        // **«المنخفض فقط» مرشِّحٌ مدفوع** — والقائمةُ نفسُها مجّانيّة.
        // فالحارسُ على الفعل لا على العنوان، كما في تصدير Excel.
        if ($request->boolean('low_stock_only')
            && ($deny = $this->denyUnless($request, 'low_stock_alerts')) !== null) {
            return $deny;
        }

        if ($request->boolean('low_stock_only')) {
            $q->whereColumn('current_stock', '<=', 'low_stock_threshold');
        }

        $products = $q->with('category')->orderBy('trade_name')->limit(100)->get();
        return $this->ok(['products' => $products]);
    }

    /** التصنيفات بيانات حقيقية للصيدلية، لا قوائم ثابتة في الهاتف. */
    public function listCategories(Request $request): JsonResponse
    {
        if ($deny = $this->guard($request, P::PHARMACY_PRODUCT_VIEW)) return $deny;
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        return $this->ok(['categories' => PharmacyCategory::where('pharmacy_id', $pharmacy->id)
            ->orderBy('sort_order')->orderBy('name')->get()]);
    }

    /** حتى خمسة أصناف محلية متشابهة لمنع تكرار الدواء باسمٍ آخر. */
    public function similarProducts(Request $request): JsonResponse
    {
        if ($deny = $this->guard($request, P::PHARMACY_PRODUCT_VIEW)) return $deny;
        $v = Validator::make($request->all(), [
            'query' => 'required|string|min:2|max:200',
            'category_id' => 'sometimes|nullable|integer',
        ]);
        if ($v->fails()) return $this->validationError($v);
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $query = trim($request->string('query')->toString());
        $items = PharmacyProduct::where('pharmacy_id', $pharmacy->id)->where('is_active', true)
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->where(fn ($q) => $q->where('trade_name', 'like', "%{$query}%")
                ->orWhere('generic_name', 'like', "%{$query}%"))
            ->with('category')->orderBy('trade_name')->limit(5)->get();
        return $this->ok(['products' => $items]);
    }

    /** بدائل يراجعها الصيدلي؛ لا توجد وصفة علاجية أو استبدال تلقائي. */
    public function alternatives(Request $request, int $id): JsonResponse
    {
        if (($deny = $this->denyUnless($request, 'pharmacy_substitutions')) !== null) return $deny;
        if ($deny = $this->guard($request, P::PHARMACY_PRODUCT_VIEW)) return $deny;
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $product = PharmacyProduct::where('id', $id)->where('pharmacy_id', $pharmacy->id)->first();
        if (!$product) return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);
        try {
            $alternatives = $this->svc->alternativesFor($product);
        } catch (\InvalidArgumentException $e) {
            return $this->error('ALTERNATIVES_NOT_READY', $e->getMessage(), 422);
        }
        return $this->ok(['product' => $product, 'alternatives' => $alternatives]);
    }

    public function addProduct(Request $request): JsonResponse
    {

        if ($deny = $this->guard($request, P::PHARMACY_PRODUCT_MANAGE)) {
            return $deny;
        }
        // الدفعة الأولى استلام مخزون فعلي، فلا تتجاوز صلاحية استلام الدفعات
        // لمجرد أنها أُرسلت مع نموذج المنتج.
        if ($request->filled('initial_batch')
            && ($deny = $this->guard($request, P::PHARMACY_BATCH_RECORD)) !== null) {
            return $deny;
        }
        // ══════════════════════════════════════════════════════════════
        // AMIAL-PHARMACY-RX-001 — **إدارةُ الوصفات مبنيّةٌ ولم تكن موصولة.**
        //
        // `PharmacySaleService` يفرضها فعلاً منذ مدّة: صنفٌ عليه
        // `requires_prescription` يُوقف البيعَ إن غاب رقمُ الوصفة. لكنّ
        // القدرةَ `pharmacy_prescriptions` كانت **تُباع في «تاجر محترف»
        // ولا يحرسها شيء** — فكلُّ صيدليّةٍ تستعملها مجّاناً.
        //
        // والحارسُ **على وسم الصنف وعلى حقول الوصفة في البيعة**، لا على
        // الصيدليّة كلِّها: صيدليّةٌ لا تشتري الميزةَ تبيع كأيّ متجر،
        // ولا يُقفَل عليها البابُ الأساسيّ.
        if ($request->boolean('requires_prescription')
            && ($deny = $this->denyUnless($request, 'pharmacy_prescriptions')) !== null) {
            return $deny;
        }

        $v = Validator::make($request->all(), [
            'trade_name' => 'required|string|max:200',
            'generic_name' => 'sometimes|nullable|string|max:200',
            'active_ingredient' => 'sometimes|nullable|string|max:200',
            'strength' => 'sometimes|nullable|string|max:80',
            'dosage_form' => 'sometimes|nullable|string|max:80',
            'manufacturer' => 'sometimes|nullable|string|max:120',
            'unit' => 'sometimes|nullable|string|max:32',
            'sku' => 'sometimes|nullable|string|max:64',
            'barcode' => 'sometimes|nullable|string|max:64',
            'sale_price' => 'required|numeric|min:0.01',
            'cost_price' => 'sometimes|nullable|numeric|min:0',
            'category_id' => 'sometimes|nullable|integer',
            'category_name' => 'sometimes|nullable|string|max:80',
            'requires_prescription' => 'sometimes|boolean',
            'low_stock_threshold' => 'sometimes|integer|min:0',
            'description' => 'sometimes|nullable|string',
            'dosage_instructions' => 'sometimes|nullable|string',
            'initial_batch' => 'sometimes|array',
            'initial_batch.batch_number' => 'required_with:initial_batch|string|max:64',
            'initial_batch.expiry_date' => 'required_with:initial_batch|date|after:today',
            'initial_batch.manufactured_at' => 'sometimes|nullable|date|before:initial_batch.expiry_date',
            'initial_batch.quantity_received' => 'required_with:initial_batch|numeric|min:0.001',
            'initial_batch.cost_per_unit' => 'sometimes|nullable|numeric|min:0',
            'initial_batch.supplier_name' => 'sometimes|nullable|string|max:200',
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

        if ($deny = $this->guard($request, P::PHARMACY_PRODUCT_MANAGE)) {
            return $deny;
        }
        // **والتعديلُ بابٌ ثانٍ للفعل نفسِه** — يُنشأ الصنفُ عاديّاً
        // ثمّ يُعلَّم بالتعديل. فيُحرس البابان.
        if ($request->boolean('requires_prescription')
            && ($deny = $this->denyUnless($request, 'pharmacy_prescriptions')) !== null) {
            return $deny;
        }

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

        if ($deny = $this->guard($request, P::PHARMACY_BATCH_VIEW)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::PHARMACY_BATCH_RECORD)) {
            return $deny;
        }
        $v = Validator::make($request->all(), [
            'batch_number' => 'required|string|max:64',
            'expiry_date' => 'required|date|after:today',
            'received_date' => 'sometimes|nullable|date',
            'manufactured_at' => 'sometimes|nullable|date|before:expiry_date',
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

    public function recallBatch(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->guard($request, P::PHARMACY_BATCH_RECALL)) return $deny;
        $v = Validator::make($request->all(), ['reason' => 'required|string|max:1000']);
        if ($v->fails()) return $this->validationError($v);
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $batch = PharmacyBatch::whereHas('product', fn ($q) => $q->where('pharmacy_id', $pharmacy->id))->find($id);
        if (!$batch) return $this->error('NOT_FOUND', 'التشغيلة غير موجودة', 404);
        try { $recalled = $this->svc->recallBatch($batch, $request->user(), $request->input('reason')); }
        catch (\InvalidArgumentException $e) { return $this->error('INVALID', $e->getMessage(), 422); }
        return $this->ok(['batch' => $recalled], 'RECALLED', 'تم سحب التشغيلة ومنع بيعها');
    }

    public function disposeBatch(Request $request, int $id): JsonResponse
    {
        if (($deny = $this->denyUnless($request, 'pharmacy_batch_disposition')) !== null) return $deny;
        if ($deny = $this->guard($request, P::PHARMACY_BATCH_DISPOSE)) return $deny;
        $v = Validator::make($request->all(), [
            'type' => 'required|in:return_to_supplier,destroyed',
            'reason' => 'required|string|max:1000',
        ]);
        if ($v->fails()) return $this->validationError($v);
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;
        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $batch = PharmacyBatch::whereHas('product', fn ($q) => $q->where('pharmacy_id', $pharmacy->id))->find($id);
        if (!$batch) return $this->error('NOT_FOUND', 'التشغيلة غير موجودة', 404);
        try {
            $disposed = $this->svc->disposeBatch($batch, $request->user(), $request->string('type')->toString(), $request->string('reason')->toString());
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID', $e->getMessage(), 422);
        }
        return $this->ok(['batch' => $disposed], 'BATCH_DISPOSED', 'تم توثيق إخراج التشغيلة من المخزون');
    }

    // ============ Customers ============

    public function listCustomers(Request $request): JsonResponse
    {

        if ($deny = $this->guard($request, P::PHARMACY_PATIENT_VIEW)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::PHARMACY_PATIENT_VIEW)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::PHARMACY_PATIENT_MANAGE)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::PHARMACY_PATIENT_MANAGE)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::PHARMACY_SALE_CREATE)) {
            return $deny;
        }

        // ══════════════════════════════════════════════════════════════
        // **وقيدان مستقلّان على الوصفة، ولا يُغني أحدُهما عن الآخر:**
        //
        //   · الباقة  — `pharmacy_prescriptions` تُشترى (أدناه)
        //   · الدور   — `pharmacy.prescription.record` تُمنح لمن يوثّق
        //
        // فصيدليّةٌ اشترت الميزةَ لا يعني أنّ كاشيرَها يوثّق وصفة، وصيدليٌّ
        // يملك الصلاحيّةَ لا يوثّق في صيدليّةٍ لم تشترِها. **وخلطُهما
        // يجعل شراءَ الميزة منحاً لكلّ الموظّفين.**
        if (($request->filled('prescription_number') || $request->filled('prescribing_doctor'))
            && ($deny = $this->guard($request, P::PHARMACY_PRESCRIPTION_RECORD))) {
            return $deny;
        }

        // **وتسجيلُ بيانات الوصفة على البيعة هو الفعلُ المدفوع** — والبيعُ
        // نفسُه مجّانيّ. فمن لم يشترِ الميزةَ يبيع ولا يوثّق وصفة.
        if (($request->filled('prescription_number') || $request->filled('prescribing_doctor'))
            && ($deny = $this->denyUnless($request, 'pharmacy_prescriptions')) !== null) {
            return $deny;
        }

        $v = Validator::make($request->all(), [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer',
            'items.*.quantity' => 'required|numeric|min:0.001',
            'customer_id' => 'sometimes|nullable|integer',
            // البيع الآجل لا يتطلب أن يكون المريض مسجلاً مسبقاً في ملف
            // الصيدلية، لكنه لا يتم بلا هوية العميل المالية الموحدة.
            'customer_phone' => 'sometimes|nullable|string|max:32',
            'customer_name' => 'sometimes|nullable|string|max:120',
            'payment_method' => 'required|in:cash,amial_pay,credit',
            'paid_transaction_id' => 'sometimes|nullable|string|max:64',
            'due_date' => 'sometimes|nullable|date',
            'prescription_number' => 'sometimes|nullable|string|max:64',
            'prescribing_doctor' => 'sometimes|nullable|string|max:200',
            'prescription_date' => 'sometimes|nullable|date',
            'discount_amount' => 'sometimes|nullable|numeric|min:0',
            // AMIAL-CASH-TENDERED-001 — ما استلمه البائعُ نقداً من الزبون.
            'amount_received' => 'sometimes|nullable|numeric|min:0',
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
                $request->user()->id,
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

        if ($deny = $this->guard($request, P::PHARMACY_SALE_VIEW_ALL)) {
            return $deny;
        }
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

    /**
     * تفصيل بيع الصيدلية من مصدره الحقيقي، بما فيه التشغيلات والوصفة.
     *
     * لا يُعاد استعمال تفصيل الكاشير العام هنا: ذلك السجل لا يملك دفعات
     * الدواء ولا رقم الوصفة، فيتحول زر «التفاصيل» إلى إيصال ناقص.
     */
    public function showSale(Request $request, string $ulid): JsonResponse
    {
        if ($deny = $this->guard($request, P::PHARMACY_SALE_VIEW_ALL)) {
            return $deny;
        }
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $sale = PharmacySale::where('sale_ulid', $ulid)
            ->where('merchant_user_id', $merchant->id)
            ->with(['items.batch', 'customer'])
            ->first();
        if (! $sale) return $this->error('NOT_FOUND', 'عملية البيع غير موجودة', 404);

        return $this->ok(['sale' => $sale]);
    }

    /**
     * الفاتورة تُبنى من `pharmacy_sales` و`pharmacy_sale_items`، لا من
     * الكاشير العام؛ وبذلك تظهر التشغيلة والانتهاء والوصفة الصحيحة.
     */
    public function downloadInvoice(Request $request, string $ulid)
    {
        if ($deny = $this->guard($request, P::PHARMACY_SALE_CREATE)) {
            return $deny;
        }
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $sale = PharmacySale::where('sale_ulid', $ulid)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$sale) return $this->error('NOT_FOUND', 'الفاتورة غير موجودة', 404);

        try {
            // AMIAL-PDF-CACHE-002 — **بيعٌ تمّ لا يتغيّر.** ومسارُ الجوّال
            // هو موضعُ العلّة: القطعُ في منتصف التصيير على شبكةٍ متقطّعة.
            $pdfSvc = app(\App\Services\PharmacySaleInvoicePdfService::class);

            $pdf = app(\App\Services\PdfCacheService::class)->remember(
                "pharmacy_invoice_{$sale->sale_ulid}",
                fn () => $pdfSvc->generate($sale),
            );
            return response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Length' => (string) strlen($pdf),
                'Content-Disposition' => 'attachment; filename="'
                    . $pdfSvc->suggestedFilename($sale) . '"',
                'Cache-Control' => 'private, max-age=900',
                'Content-Encoding' => 'identity',
            ]);
        } catch (\Throwable $e) {
            \Log::error('Pharmacy invoice PDF generation failed', [
                'sale_ulid' => $sale->sale_ulid,
                'merchant_user_id' => $merchant->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error('PDF_GEN_FAILED', 'تعذّر توليد فاتورة الصيدلية', 500);
        }
    }

    // ============ Alerts ============

    public function listAlerts(Request $request): JsonResponse
    {

        if ($deny = $this->guard($request, P::PHARMACY_ALERT_VIEW)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::PHARMACY_ALERT_VIEW)) {
            return $deny;
        }
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $pharmacy = $this->svc->getOrCreatePharmacy($merchant);
        $results = $this->alerts->scanExpiringBatches($pharmacy);
        return $this->ok(['scan' => $results]);
    }

    public function dismissAlert(Request $request, int $id): JsonResponse
    {

        if ($deny = $this->guard($request, P::PHARMACY_ALERT_DISMISS)) {
            return $deny;
        }
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

        if ($deny = $this->guard($request, P::REPORT_SALES)) {
            return $deny;
        }
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

        $merchant = null;
        $posUserId = null;

        if ($pos) {
            $merchant = User::find($pos->merchant_user_id);
            if (!$merchant) return $this->error('MERCHANT_NOT_FOUND', 'التاجر غير موجود', 404);
            $posUserId = $pos->id;
        }

        // الموظفُ حسابٌ بصلاحيّات، وليس بالضرورة حسابَ POS. فربط الدور
        // في merchant_user_roles هو مصدرُ انتمائه إلى المنشأة، ويجب أن
        // يمرّ في المسار نفسه بعد أن يتحقق guard من الفعل المسموح.
        if ($merchant === null) {
            $merchantId = DB::table('merchant_user_roles')
                ->where('user_id', $authUser->id)
                ->where('is_active', true)
                ->value('merchant_user_id');
            if ($merchantId) {
                $merchant = User::find($merchantId);
                if (!$merchant) return $this->error('MERCHANT_NOT_FOUND', 'التاجر غير موجود', 404);
            }
        }

        if ($merchant === null) {
            $merchant = $authUser;
        }

        // حارس القطاع على نقطة الدخول نفسها: لا يكفي إخفاء شاشة الصيدلية.
        // فحساب تاجر التجزئة أو جهاز POS التابع له يستطيع استدعاء API مباشرة
        // إن لم يُتحقّق من هوية المنشأة المالكة قبل إنشاء أي بيانات صيدلية.
        $profile = MerchantProfile::where('user_id', $merchant->id)->first();
        if (!$profile) {
            return $this->error('NOT_A_MERCHANT', 'متاح للتجار وموظفي الصيدلية فقط', 403);
        }
        if ($profile->business_type !== \App\Support\Access\AccessConstants::BIZ_PHARMACY) {
            return $this->error('PHARMACY_ONLY', 'هذه العملية متاحة لمنشآت الصيدلية فقط', 403);
        }

        return [$merchant, $posUserId];
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
