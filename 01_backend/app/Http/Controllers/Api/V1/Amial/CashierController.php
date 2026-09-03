<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Concerns\DeniesByPlan;
use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\PosUser;
use App\Models\User;
use App\Services\CashierService;
use App\Services\CashierSaleInvoicePdfService;
use App\Services\Merchant\MerchantPermissionService;
use App\Support\Merchant\MerchantPermissions as P;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-CASHIER-001 — كاشير التاجر.
 *
 *   GET  /api/v1/amial/merchant/cashier/products
 *   POST /api/v1/amial/merchant/cashier/products
 *   PUT  /api/v1/amial/merchant/cashier/products/{id}
 *   POST /api/v1/amial/merchant/cashier/sales              (نقد/أجل/أميال باي)
 *   POST /api/v1/amial/merchant/cashier/sales/{id}/settle  (تسوية أجل)
 *   GET  /api/v1/amial/merchant/cashier/report             (تقرير يومي)
 */
class CashierController extends AmialApiController // AMIAL-FIX-007
{
    // AMIAL-OFFLINE-POS-003 — **استيرادُ الصنف ليس استعمالَ التِّرَيت.**
    //
    // كان `use App\Http\Controllers\Concerns\DeniesByPlan;` في رأس
    // الملفّ (استيرادُ نطاق) **ولا سطرَ له داخل الصنف**. فـ`$this->
    // denyUnless(...)` يرمي `Method ... does not exist` — **انهيارٌ فادحٌ
    // في مسار البيع نفسِه**، يراه الكاشيرُ شريطاً أحمرَ عند تأكيد الدفع.
    //
    // ولا يمسكه مُحلِّلٌ ولا اختبار: الاستيرادُ مستعمَلٌ نحويّاً (يظهر في
    // التعليقات والتوثيق)، و`php -l` لا يفحص وجودَ دالّةٍ في وقت التشغيل.
    use \App\Http\Controllers\Concerns\DeniesByPlan;

    public function __construct(
        private readonly CashierService $cashier,
        private readonly MerchantPermissionService $perm,
    ) {}

    public function products(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        return $this->ok(['products' => $this->cashier->listProducts($merchant, $request->query('search'))]);
    }

    /**
     * AMIAL-CASHIER-BARCODE-001 — بحث منتج الكاشير بالباركود (لمسح الكاشير).
     * يعيد المنتج لإضافته للسلّة، أو 404 ليعرض التطبيق خيار «إنشاء منتج بهذا الباركود».
     */
    public function lookupBarcode(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), ['barcode' => 'required|string|max:64']);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $product = $this->cashier->findByBarcode($merchant, (string) $request->query('barcode', $request->input('barcode')));
        if (!$product) {
            return $this->error('NOT_FOUND', 'لا يوجد منتج بهذا الباركود', 404);
        }

        return $this->ok(['product' => $product]);
    }

    public function addProduct(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name' => 'required|string|max:160',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'sometimes|nullable|numeric|min:0',
            'offer_price' => 'sometimes|nullable|numeric|min:0',
            'quantity' => 'sometimes|nullable|numeric|min:0',
            'production_date' => 'sometimes|nullable|date',
            'expiry_date' => 'sometimes|nullable|date',
            'category' => 'sometimes|nullable|string|max:80',
            'barcode' => 'sometimes|nullable|string|max:64',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $product = $this->cashier->addProduct($merchant, $v->validated());

        // AMIAL-CATALOG-001 — **ما أدخله التاجر يُفيد من بعده.**
        //
        // ولا يُوقف الحفظَ أبداً: الكتالوجُ خدمةٌ جانبيّة، ومن ربط بيعَ
        // متجرٍ بنجاح ميزةٍ مساعدةٍ أوقف المتجرَ لأجل اسمٍ في جدول.
        // فالخدمةُ تبتلع أخطاءَها وتُرجع سبباً للعرض لا استثناءً.
        $catalogNote = null;

        if (!empty($v->validated()['barcode'])) {
            $catalogNote = app(\App\Services\Catalog\ProductCatalogService::class)->suggest(
                (string) $v->validated()['barcode'],
                (string) $v->validated()['name'],
                $merchant,
                $v->validated()['category'] ?? null,
            );
        }

        return $this->ok([
            'product' => $product,
            'catalog_note' => $catalogNote,
        ], 'PRODUCT_ADDED', 'تم إضافة المنتج');
    }

    /**
     * AMIAL-CATALOG-ADOPT-001 — **تبنّي صنفٍ من الكتالوج العامّ.**
     *
     * POST /api/v1/amial/merchant/cashier/products/adopt
     *
     * الطلب : { barcode: "6281…", price: 500, cost_price?: 400, quantity?: 10 }
     * الردّ  : 201 { product, adopted_from: { name, category, unit } }
     * الأخطاء: NO_BARCODE(422) · ADOPT_FAILED(422) · PLAN_LIMIT_REACHED(402)
     *
     * والحدُّ يُفرض على المسار كإخوته — **فبابُ التبنّي بابُ إنشاءٍ أيضاً**،
     * ولو تُرك بلا حدٍّ لصار طريقاً يلتفّ على الباقة بمسحِ باركود.
     */
    public function adoptFromCatalog(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'barcode' => 'required|string|max:64',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'sometimes|nullable|numeric|min:0',
            'quantity' => 'sometimes|nullable|numeric|min:0',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $catalog = app(\App\Services\Catalog\ProductCatalogService::class);

        try {
            $product = $catalog->adopt($merchant, (string) $v->validated()['barcode'], $v->validated());
        } catch (\DomainException $e) {
            return $this->error('ADOPT_FAILED', $e->getMessage(), 422);
        }

        $entry = $catalog->find((string) $v->validated()['barcode']);

        return $this->ok([
            'product' => $product,
            // **يُقال من أين جاء** — فالتاجرُ يرى اسماً لم يكتبه ويستحقّ
            // أن يعرف مصدرَه قبل أن يظنّه خطأً.
            'adopted_from' => $entry ? [
                'name' => $entry->name,
                'category' => $entry->category,
                'unit' => $entry->unit,
            ] : null,
        ], 'ADOPTED', 'أُضيف الصنف من الكتالوج', 201);
    }

    public function updateProduct(Request $request, int $id): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:160',
            'price' => 'sometimes|numeric|min:0',
            'cost_price' => 'sometimes|nullable|numeric|min:0',
            'offer_price' => 'sometimes|nullable|numeric|min:0',
            'quantity' => 'sometimes|nullable|numeric|min:0',
            'production_date' => 'sometimes|nullable|date',
            'expiry_date' => 'sometimes|nullable|date',
            'category' => 'sometimes|nullable|string|max:80',
            'barcode' => 'sometimes|nullable|string|max:64',
            'is_active' => 'sometimes|boolean',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        try {
            $product = $this->cashier->updateProduct($merchant, $id, $v->validated());
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('NOT_FOUND', 'المنتج غير موجود', 404);
        }
        return $this->ok(['product' => $product], 'PRODUCT_UPDATED', 'تم التحديث');
    }

    public function recordSale(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'total' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,credit,amial_pay,corporate,mixed',
            'cash_amount' => 'sometimes|nullable|numeric|min:0',
            'wallet_amount' => 'sometimes|nullable|numeric|min:0',
            'items' => 'sometimes|array',
            'items.*.name' => 'sometimes|string|max:160',
            'items.*.qty' => 'sometimes|numeric|min:0',
            // المطعمُ وغيرُه يرسلون `quantity` — والمفتاحان مقبولان عند
            // العتبة ويُطبَّعان إلى شكلٍ واحد في `SaleLineService`.
            'items.*.quantity' => 'sometimes|numeric|min:0',
            'items.*.price' => 'sometimes|numeric|min:0',
            'items.*.discount' => 'sometimes|numeric|min:0',
            'items.*.product_id' => 'sometimes|nullable|integer',
            'customer.name' => 'sometimes|nullable|string|max:120',
            'customer.phone' => 'sometimes|nullable|string|max:32',
            'paid_transaction_id' => 'sometimes|nullable|string|max:40',
            'credit_due_date' => 'sometimes|nullable|date_format:Y-m-d',
            'corporate_account_id' => 'sometimes|nullable|integer',
            'corporate_member_id' => 'sometimes|nullable|integer',
            'discount_amount' => 'sometimes|nullable|numeric|min:0',
            'promotion_id' => 'sometimes|nullable|integer',
            'client_uuid' => 'sometimes|nullable|string|max:64',
        ]);
        if ($v->fails()) return $this->validationError($v);

        // ══════════════════════════════════════════════════════════════
        // AMIAL-OFFLINE-POS-002 — **البيعُ دون اتصالٍ قدرةٌ مدفوعة.**
        //
        // والبيعُ المتّصلُ مجّانيّ. والفارقُ يُقرأ من الطلب نفسِه:
        // **`client_uuid` لا يُرسَل إلّا من طابور المزامنة** — فهو مفتاحُ
        // منع التكرار الذي يولّده التطبيقُ حين يبيع والشبكةُ مقطوعة.
        //
        // فالحارسُ على الفعل لا على العنوان: مسارُ البيع واحدٌ للحالتين،
        // ووضعُ `capability:` عليه يُقفل الكاشيرَ على كلّ تاجرٍ مجّانيّ.
        // ══════════════════════════════════════════════════════════════
        // AMIAL-OFFLINE-POS-003 — **والإشارةُ كانت خاطئةً واقعاً.**
        //
        // قال التعليقُ أعلاه: «`client_uuid` لا يُرسَل إلّا من طابور
        // المزامنة». **وقِيس في التطبيق فإذا هو يُرسَل في كلّ بيع** —
        // سطرٌ غيرُ مشروط في `cashier_controller.dart` يولّده مفتاحاً
        // لمنع التكرار (‏AMIAL-OFFLINE-POS-001)، متّصلاً كان أو غيرَ متّصل.
        //
        // فالحارسُ كان يقع على **كلّ** بيع. ولو أُضيف التِّرَيتُ وحدَه
        // لتحوّل الانهيارُ إلى ٤٠٢ على البيع النقديِّ الأساسيِّ لكلّ تاجرٍ
        // مجّانيّ — **وذاك أسوأُ من الانهيار**: قفلٌ مدفوعٌ على ما هو مجّانيّ
        // بالتصميم، ولا يقول لأحدٍ لماذا.
        //
        // **والفارقُ الحقيقيُّ موجودٌ في الحمولة**: طابورُ المزامنة يضيف
        // `_offline_queued_at` عند الحفظ المحلّيّ ويرسله مع البيع، والبيعُ
        // المتّصلُ لا يرسله قطّ. فصار الحارسُ على أثرِ الطابور لا على مفتاح
        // منع التكرار. (القاعدة الثامنة: الحدُّ على ما لا يُنتحَل.)
        // ══════════════════════════════════════════════════════════════
        if (($request->filled('_offline_queued_at') || $request->filled('_offline_state'))
            && ($deny = $this->denyUnless($request, 'offline_pos')) !== null) {
            return $deny;
        }

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posUserId] = $ctx;

        // AMIAL-CORPORATE-ACCOUNTS-001: البيع على حساب شركة يتطلّب الباقة المؤسسية
        if ($request->input('payment_method') === 'corporate'
            && !app(\App\Services\FeatureAccessService::class)->hasFeature($merchant, \App\Support\Access\AccessConstants::F_CORPORATE_ACCOUNTS)) {
            return $this->error('FEATURE_LOCKED', 'حسابات الشركات متاحة في الباقة المؤسسية', 402);
        }

        try {
            $sale = $this->cashier->recordSale(
                merchant: $merchant,
                total: (string)$request->input('total'),
                paymentMethod: $request->input('payment_method'),
                items: $request->input('items', []),
                posUserId: $posUserId,
                customer: $request->input('customer'),
                paidTransactionId: $request->input('paid_transaction_id'),
                creditDueDate: $request->input('credit_due_date'),
                corporateAccountId: $request->input('corporate_account_id'),
                corporateMemberId: $request->input('corporate_member_id'),
                discountAmount: $request->input('discount_amount'),
                promotionId: $request->input('promotion_id'),
                cashAmount: $request->input('cash_amount'),
                walletAmount: $request->input('wallet_amount'),
                clientUuid: $request->input('client_uuid'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('SALE_INVALID', $e->getMessage(), 422);
        } catch (\RuntimeException $e) {
            return $this->error('SALE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['sale' => $sale], 'SALE_RECORDED', 'تم تسجيل البيع');
    }

    /**
     * AMIAL-CASHIER-INVOICE-001 — PDF رسمي من سجلّ البيع المخزّن.
     *
     * المسار لا يقرأ شيئاً من Flutter: التاجر وPOS لا يملكان إلا فاتورتهما.
     */
    public function downloadInvoice(Request $request, string $ulid)
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $sale = MerchantSale::where('sale_ulid', $ulid)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$sale) return $this->error('NOT_FOUND', 'الفاتورة غير موجودة', 404);

        try {
            // AMIAL-PDF-CACHE-002 — **بيعٌ تمّ لا يتغيّر، فالتصيير مرّةً
            // يكفي أبداً.** وكلُّ تصييرٍ داخل الطلب يعرّض الاتّصالَ
            // للقطع على شبكة جوّال (`Connection closed while receiving
            // data`) — **وهو عطلٌ وقع فعلاً**، وهذا مسارُ الجوّال نفسُه.
            $pdfSvc = app(CashierSaleInvoicePdfService::class);

            $pdf = app(\App\Services\PdfCacheService::class)->remember(
                "cashier_invoice_{$sale->sale_ulid}",
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
            \Log::error('Cashier invoice PDF generation failed', [
                'sale_ulid' => $ulid,
                'merchant_user_id' => $merchant->id,
                'error' => $e->getMessage(),
            ]);
            return $this->error('PDF_GEN_FAILED', 'تعذّر توليد الفاتورة', 500);
        }
    }

    public function settleCredit(Request $request, int $id): JsonResponse
    {
        try {
            $this->perm->assert($request->user(), P::CASH_MOVE);
        } catch (DomainException $e) {
            return $this->error('FORBIDDEN', $e->getMessage(), 403);
        }
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        try {
            $sale = $this->cashier->settleCredit($merchant, $id, $request->input('paid_transaction_id'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('NOT_FOUND', 'العملية غير موجودة', 404);
        } catch (\RuntimeException $e) {
            return $this->error('SETTLE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['sale' => $sale], 'CREDIT_SETTLED', 'تمت تسوية الأجل');
    }

    /** AMIAL-CASHIER-REFUND-001 — GET /sales?date=Y-m-d — قائمة مبيعات اليوم بمعرّفاتها (مدخل الاسترجاع). */
    public function listSales(Request $request): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'date' => 'sometimes|date_format:Y-m-d',
            'limit' => 'sometimes|integer|min:1|max:200',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        return $this->ok($this->cashier->listSales(
            $merchant,
            $request->query('date'),
            (int) $request->query('limit', 100)
        ));
    }

    /**
     * AMIAL-RETAIL-VERTICAL-001 · المرحلة ١ — GET /cashier/sales/{ulid}
     * تفصيلُ بيعةٍ **سطراً سطراً** بالتكلفة الملتقَطة والهامش لكلّ سطر.
     *
     * **ولولا بابٌ كهذا لبقيت الأسطر جدولاً لا يُقرأ** — والقاعدةُ ١٢:
     * ما لا يُوصل إليه ليس مبنيّاً.
     */
    public function showSale(Request $request, string $ulid): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $sale = \App\Models\MerchantSale::where('sale_ulid', $ulid)
            ->where('merchant_user_id', $merchant->id)
            ->with('lines')
            ->first();
        if (! $sale) return $this->error('NOT_FOUND', 'العملية غير موجودة', 404);

        $summary = app(\App\Services\Retail\SaleLineService::class)->costOf($sale->lines);
        $revenue = (string) $sale->total_amount;
        $profit = \App\Services\MoneyService::sub($revenue, $summary['cost']);

        return $this->ok([
            'sale' => $sale->only([
                'id', 'sale_ulid', 'total_amount', 'discount_amount', 'payment_method',
                'status', 'customer_name', 'customer_phone', 'created_at',
            ]),
            'lines' => $sale->lines->map(fn ($l) => [
                'id' => $l->id,
                'product_id' => $l->product_id,
                'name' => $l->name,
                'quantity' => (string) $l->quantity,
                'unit_price' => (string) $l->unit_price,
                'line_discount' => (string) $l->line_discount,
                'line_total' => (string) $l->line_total,
                // **الفراغُ يُرسَل فراغاً** — والتطبيقُ يعرضه «غير معروفة»
                // ولا يعرضه صفراً (القاعدة ٧).
                'unit_cost' => $l->unit_cost !== null ? (string) $l->unit_cost : null,
                'line_cost' => $l->line_cost !== null ? (string) $l->line_cost : null,
                'line_profit' => $l->line_cost !== null
                    ? bcsub((string) $l->line_total, (string) $l->line_cost, 4)
                    : null,
                'cost_source' => $l->cost_source,
                'cost_source_ar' => $l->costSourceAr(),
                'returned_quantity' => (string) $l->returned_quantity,
                'refundable_quantity' => $l->refundableQuantity(),
            ])->values()->all(),
            'totals' => [
                'revenue' => $revenue,
                'known_cost' => $summary['cost'],
                'gross_profit' => $profit,
                'unknown_cost_lines' => $summary['unknown_lines'],
                'unknown_cost_revenue' => $summary['unknown_revenue'],
            ],
        ]);
    }

    public function report(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        return $this->ok($this->cashier->dailyReport($merchant, $request->query('date')));
    }

    /** AMIAL-PROFIT-001 — GET /profit-report?days=7|30|90 */
    public function profitReport(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $days = (int) $request->query('days', 7);
        return $this->ok($this->cashier->profitReport($merchant, $days));
    }

    // ---- helpers ----

    /** يرجّع [merchant(User), posUserId(?int)] أو JsonResponse عند الخطأ. */
    private function resolveMerchantPos(Request $request): array|JsonResponse
    {
        $authUser = $request->user();
        $pos = PosUser::where('user_id', $authUser->id)->where('is_active', true)->first();

        if ($pos) {
            $merchant = User::find($pos->merchant_user_id);
            if (!$merchant) return $this->error('MERCHANT_NOT_FOUND', 'التاجر غير موجود', 404);
            if ($this->isPharmacyMerchant($merchant)) {
                return $this->error(
                    'PHARMACY_CASHIER_ONLY',
                    'هذه المنشأة صيدلية. استخدم كاشير الصيدلية لتبقى الوصفات والتشغيلات والصلاحية في الفاتورة.',
                    403,
                );
            }
            return [$merchant, $pos->id];
        }

        $profile = MerchantProfile::where('user_id', $authUser->id)->first();
        if (!$profile) {
            return $this->error('NOT_A_MERCHANT', 'الكاشير متاح للتجار وموظفي نقاط البيع فقط', 403);
        }
        if ($profile->business_type === 'pharmacy') {
            return $this->error(
                'PHARMACY_CASHIER_ONLY',
                'هذه المنشأة صيدلية. استخدم كاشير الصيدلية لتبقى الوصفات والتشغيلات والصلاحية في الفاتورة.',
                403,
            );
        }
        return [$authUser, null];
    }

    /** الكاشير العام يخص البيع السريع والتجزئة؛ الصيدلية لها مصدر بيع مستقل. */
    private function isPharmacyMerchant(User $merchant): bool
    {
        return MerchantProfile::where('user_id', $merchant->id)
            ->value('business_type') === 'pharmacy';
    }
}
