<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProduct;
use App\Models\Merchant\MerchantRole;
use App\Models\Retail\MerchantLocation;
use App\Models\Retail\ProductPriceVersion;
use App\Models\Retail\ProductStock;
use App\Models\Retail\SaleReturn;
use App\Models\Retail\StockCount;
use App\Models\Retail\StockTransfer;
use App\Models\Retail\StockWaste;
use App\Models\User;
use App\Services\Merchant\MerchantPermissionService;
use App\Services\Retail\ProductCatalogService;
use App\Services\Retail\ProductPriceService;
use App\Services\Retail\SaleReturnService;
use App\Services\Retail\StockCountService;
use App\Services\Retail\StockService;
use App\Services\Retail\StockTransferService;
use App\Services\Retail\StockWasteService;
use App\Support\Merchant\MerchantPermissions as P;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-RETAIL-VERTICAL-001 — نقاطُ نهاية المراحل ٢–٩.
 *
 * ══════════════════════════════════════════════════════════════════════
 * **وكلُّ فعلٍ خلفَه صلاحيّة.** إخفاءُ الزرّ في الواجهة ليس أماناً: من
 * يعرف المسار ينادي بلا زرّ.
 *
 * **والتاجرُ من الهويّة لا من الطلب** (القاعدة ٨): `merchantIdFor` يقرأ
 * أدوارَ المستخدم ثمّ نقطةَ بيعِه، ومعرّفٌ يأتي من المتصفّح يمكن تغييره.
 */
class RetailVerticalController extends Controller
{
    public function __construct(
        private readonly ProductCatalogService $catalog,
        private readonly StockService $stock,
        private readonly StockTransferService $transfers,
        private readonly StockCountService $counts,
        private readonly StockWasteService $wastes,
        private readonly SaleReturnService $returns,
        private readonly ProductPriceService $prices,
        private readonly MerchantPermissionService $perm,
    ) {
    }

    // ══════════════════════════════════════════════════════════════════
    //  أدوات
    // ══════════════════════════════════════════════════════════════════

    private function actor(Request $request): User
    {
        return $request->user();
    }

    private function merchant(Request $request): User
    {
        $id = $this->perm->merchantIdFor($this->actor($request));
        $merchant = User::find($id);

        if (! $merchant) {
            throw new DomainException('لا توجد منشأة مرتبطة بهذا الحساب');
        }

        return $merchant;
    }

    private function ok(array $data = [], string $message = ''): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data]);
    }

    private function fail(string $message, int $code = 422): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'data' => null], $code);
    }

    /** يلفّ كلَّ فعلٍ: يفحص الصلاحيّة ويحوّل خطأَ المجال إلى ردٍّ مفهوم. */
    private function guarded(
        Request $request, string $permission, callable $fn,
        ?string $amount = null, array $context = [],
    ): JsonResponse {
        try {
            $this->perm->assert($this->actor($request), $permission, $context, $amount);

            return $fn();
        } catch (DomainException|\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return $this->fail('تعذّر إتمام العملية — حاول مرة أخرى', 500);
        }
    }

    /** يُتأكّد أنّ السجلَّ يخصّ منشأةَ المنادي — **لا يُقبل معرّفٌ غريب**. */
    private function own(string $modelClass, Request $request, int $id)
    {
        $row = $modelClass::where('id', $id)
            ->where('merchant_user_id', $this->merchant($request)->id)->first();

        if (! $row) {
            throw new DomainException('السجل غير موجود في منشأتك');
        }

        return $row;
    }

    // ══════════════════════════════════════════════════════════════════
    //  مركزُ التجزئة — الحالةُ كلُّها في نداءٍ واحد
    // ══════════════════════════════════════════════════════════════════

    /**
     * **ولا يُبنى من ستّة نداءات**: شاشةٌ تفتح بستّة طلباتٍ تُظهر أرقامَها
     * واحداً بعد واحد، ويقرأ المستعملُ نصفَ الحقيقة ثانيتين.
     */
    public function operationsCenter(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_STOCK_VIEW, function () use ($request) {
            $merchant = $this->merchant($request);

            $locations = MerchantLocation::where('merchant_user_id', $merchant->id)
                ->where('is_active', true)->get();

            // ══════════════════════════════════════════════════════
            // **تنبيهُ النفاد مدفوعٌ من «البداية» — ومركزُ العمليّات ليس.**
            //
            // فلا يُحرَس المسارُ كلُّه (‏يُقفل المخزونَ على المجّانيّ)،
            // ولا يُرسَل القسمُ صفراً (‏«غير معروف» ليس صفراً — القاعدة
            // السابعة: صفرٌ يُقرأ «فحصنا فلم نجد»، والتاجرُ يظنّ مخزونَه
            // سليماً وهو لم يُفحص).
            //
            // **فيُقال إنّه مقفول، ويُقال بكم يُفتح.**
            $lowStockState = app(\App\Services\Access\EntitlementService::class)
                ->gate($request->user(), 'low_stock_alerts');

            $low = $lowStockState === null ? $this->stock->lowStock($merchant->id) : null;

            return $this->ok([
                'locations' => $locations->map(fn (MerchantLocation $l) => [
                    'id' => $l->id,
                    'name' => $l->name,
                    'kind' => $l->kind,
                    'is_default' => (bool) $l->is_default,
                    'products' => ProductStock::where('location_id', $l->id)->count(),
                    'never_counted' => ProductStock::where('location_id', $l->id)
                        ->whereNull('last_counted_at')->count(),
                ])->all(),

                'low_stock' => $low,

                // **القسمُ المقفولُ يُصرَّح به لا يُطوى** — فالشاشةُ تعرض
                // «ارفع الباقة» مكانَ قائمةٍ فارغةٍ تكذب.
                'low_stock_locked' => $lowStockState !== null ? [
                    'state' => $lowStockState['state'] ?? null,
                    'unlock' => $lowStockState['unlock'] ?? null,
                ] : null,

                // **البضاعةُ في الطريق حالةٌ تُقال** — خرجت ولم تصل.
                'in_transit' => $this->transfers->inTransit($merchant),

                'pending' => [
                    'transfers_to_approve' => StockTransfer::where('merchant_user_id', $merchant->id)
                        ->where('status', StockTransfer::REQUESTED)->count(),
                    'transfers_to_receive' => StockTransfer::where('merchant_user_id', $merchant->id)
                        ->where('status', StockTransfer::SHIPPED)->count(),
                    'counts_in_review' => StockCount::where('merchant_user_id', $merchant->id)
                        ->where('status', StockCount::REVIEW)->count(),
                    'wastes_pending' => StockWaste::where('merchant_user_id', $merchant->id)
                        ->where('status', 'pending')->count(),
                    'returns_pending' => SaleReturn::where('merchant_user_id', $merchant->id)
                        ->where('status', 'pending')->count(),
                    'prices_proposed' => ProductPriceVersion::where('merchant_user_id', $merchant->id)
                        ->where('status', ProductPriceVersion::PROPOSED)->count(),
                ],

                'waste_30d' => $this->wastes->report($merchant, 30),
            ]);
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  محرّكُ الأصناف — المرحلتان ٢ و٣
    // ══════════════════════════════════════════════════════════════════

    public function categories(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_PRODUCT_VIEW, fn () => $this->ok([
            'tree' => $this->catalog->categoryTree($this->merchant($request)),
        ]));
    }

    public function addCategory(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_CATALOG_MANAGE, function () use ($request) {
            $c = $this->catalog->addCategory($this->merchant($request), $request->all());

            return $this->ok(['id' => $c->id, 'code' => $c->code], 'أُضيف التصنيف');
        });
    }

    public function brands(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_PRODUCT_VIEW, fn () => $this->ok([
            'brands' => \App\Models\Retail\MerchantBrand::where(
                'merchant_user_id', $this->merchant($request)->id
            )->orderBy('name')->get(['id', 'name', 'code', 'country', 'is_active'])->all(),
        ]));
    }

    public function addBrand(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_CATALOG_MANAGE, function () use ($request) {
            $b = $this->catalog->addBrand($this->merchant($request), $request->all());

            return $this->ok(['id' => $b->id], 'أُضيفت العلامة');
        });
    }

    public function units(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_PRODUCT_VIEW, fn () => $this->ok([
            'units' => \App\Models\Retail\MerchantUnit::where(
                'merchant_user_id', $this->merchant($request)->id
            )->orderBy('name')->get(['id', 'name', 'code', 'decimals', 'base_unit_id', 'factor'])->all(),
        ]));
    }

    public function addUnit(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_CATALOG_MANAGE, function () use ($request) {
            $u = $this->catalog->addUnit($this->merchant($request), $request->all());

            return $this->ok(['id' => $u->id], 'أُضيفت الوحدة');
        });
    }

    public function addBarcode(Request $request, int $productId): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_PRODUCT_MANAGE, function () use ($request, $productId) {
            $b = $this->catalog->addBarcode($this->merchant($request), $productId, $request->all());

            return $this->ok(['id' => $b->id], 'أُضيف الباركود');
        });
    }

    /** المسحُ — **ويعيد كم حبّةً يعني هذا الرمز** (كرتونٌ أم حبّة). */
    public function scan(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_PRODUCT_VIEW, function () use ($request) {
            $hit = $this->catalog->scan(
                $this->merchant($request), (string) $request->query('barcode', ''));

            if (! $hit) {
                // **«غير موجود» تُقال صراحةً** ولا تُردّ قائمةً فارغة.
                return $this->ok(['found' => false], 'لا صنف بهذا الباركود');
            }

            /** @var MerchantProduct $p */
            $p = $hit['product'];

            return $this->ok([
                'found' => true,
                'product' => [
                    'id' => $p->id,
                    'name' => $p->displayName(),
                    'sku' => $p->sku,
                    'price' => (string) $p->effective_price,
                ],
                'pack_size' => $hit['pack_size'],
            ]);
        });
    }

    public function generateVariants(Request $request, int $productId): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_PRODUCT_MANAGE, function () use ($request, $productId) {
            $made = $this->catalog->generateVariants(
                $this->merchant($request), $productId, (array) $request->input('axes', []));

            return $this->ok(['created' => count($made)], count($made) . ' متغيّراً');
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  المخزون والمواقع
    // ══════════════════════════════════════════════════════════════════

    public function locations(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_STOCK_VIEW, fn () => $this->ok([
            'locations' => MerchantLocation::where(
                'merchant_user_id', $this->merchant($request)->id
            )->get(['id', 'name', 'code', 'kind', 'city', 'is_active', 'is_default'])->all(),
        ]));
    }

    public function addLocation(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_LOCATION_MANAGE, function () use ($request) {
            $l = $this->stock->addLocation($this->merchant($request), $request->all());

            return $this->ok(['id' => $l->id], 'أُضيف الموقع');
        });
    }

    /** مخزونُ صنفٍ في كلّ المواقع — **ومن لا موقعَ له يُقال لا يُخمَّن**. */
    public function productStock(Request $request, int $productId): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_STOCK_VIEW, function () use ($request, $productId) {
            $product = MerchantProduct::where('id', $productId)
                ->where('merchant_user_id', $this->merchant($request)->id)->firstOr(
                    fn () => throw new DomainException('الصنف غير موجود'));

            return $this->ok([
                'product' => ['id' => $product->id, 'name' => $product->displayName()],
                'locations' => $this->stock->acrossLocations($product),
            ]);
        });
    }

    public function movements(Request $request, int $productId): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_STOCK_VIEW, function () use ($request, $productId) {
            $merchant = $this->merchant($request);

            $rows = \App\Models\Retail\StockMovement::where('merchant_user_id', $merchant->id)
                ->where('product_id', $productId)
                ->orderByDesc('id')->limit(100)->get();

            return $this->ok([
                'movements' => $rows->map(fn ($m) => [
                    'reason' => $m->reason,
                    'reason_ar' => $m->reasonAr(),
                    'delta' => (string) $m->quantity_delta,
                    'balance_after' => (string) $m->balance_after,
                    'unit_cost' => $m->unit_cost !== null ? (string) $m->unit_cost : null,
                    'source' => $m->source_type,
                    'note' => $m->note,
                    'created_at' => $m->created_at?->toIso8601String(),
                ])->all(),
            ]);
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  التحويلات — المرحلة ٤
    // ══════════════════════════════════════════════════════════════════

    public function transfers(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_STOCK_VIEW, function () use ($request) {
            $rows = StockTransfer::where('merchant_user_id', $this->merchant($request)->id)
                ->with(['fromLocation:id,name', 'toLocation:id,name'])
                ->withCount('items')
                ->orderByDesc('id')->limit(50)->get();

            return $this->ok([
                'transfers' => $rows->map(fn (StockTransfer $t) => [
                    'id' => $t->id,
                    'code' => $t->code,
                    'from' => $t->fromLocation->name ?? '—',
                    'to' => $t->toLocation->name ?? '—',
                    'status' => $t->status,
                    'status_ar' => $t->statusAr(),
                    'lines' => $t->items_count,
                    'created_at' => $t->created_at?->toIso8601String(),
                ])->all(),
            ]);
        });
    }

    public function showTransfer(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_STOCK_VIEW, function () use ($request, $id) {
            /** @var StockTransfer $t */
            $t = $this->own(StockTransfer::class, $request, $id);
            $t->load(['items', 'fromLocation:id,name', 'toLocation:id,name']);

            return $this->ok([
                'transfer' => [
                    'id' => $t->id, 'code' => $t->code,
                    'from' => $t->fromLocation->name ?? '—',
                    'to' => $t->toLocation->name ?? '—',
                    'status' => $t->status, 'status_ar' => $t->statusAr(),
                    'note' => $t->note,
                    'shipped_at' => $t->shipped_at?->toIso8601String(),
                    'received_at' => $t->received_at?->toIso8601String(),
                ],
                'items' => $t->items->map(fn ($i) => [
                    'id' => $i->id,
                    'product_id' => $i->product_id,
                    'name' => $i->name,
                    'requested' => (string) $i->requested_quantity,
                    // **الفراغُ «لم يُرسَل/يُستلَم بعد»** لا صفر.
                    'shipped' => $i->shipped_quantity !== null ? (string) $i->shipped_quantity : null,
                    'received' => $i->received_quantity !== null ? (string) $i->received_quantity : null,
                    'shortage' => $i->shortage(),
                    'variance_reason' => $i->variance_reason,
                ])->all(),
            ]);
        });
    }

    public function requestTransfer(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_TRANSFER_REQUEST, function () use ($request) {
            $t = $this->transfers->request($this->merchant($request), array_merge(
                $request->all(), ['actor_id' => $this->actor($request)->id]));

            return $this->ok(['id' => $t->id, 'code' => $t->code], 'أُنشئ طلب التحويل');
        });
    }

    public function approveTransfer(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_TRANSFER_APPROVE, function () use ($request, $id) {
            $t = $this->transfers->approve(
                $this->actor($request), $this->own(StockTransfer::class, $request, $id));

            return $this->ok(['status' => $t->status], 'اعتُمد التحويل');
        });
    }

    public function shipTransfer(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_TRANSFER_SHIP, function () use ($request, $id) {
            $t = $this->transfers->ship(
                $this->actor($request),
                $this->own(StockTransfer::class, $request, $id),
                (array) $request->input('shipped', []),
            );

            return $this->ok(['status' => $t->status], 'أُرسل التحويل — البضاعة في الطريق');
        });
    }

    public function receiveTransfer(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_TRANSFER_RECEIVE, function () use ($request, $id) {
            $t = $this->transfers->receive(
                $this->actor($request),
                $this->own(StockTransfer::class, $request, $id),
                (array) $request->input('received', []),
            );

            return $this->ok(
                ['status' => $t->status, 'status_ar' => $t->statusAr()], 'استُلم التحويل');
        });
    }

    public function cancelTransfer(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_TRANSFER_APPROVE, function () use ($request, $id) {
            $this->transfers->cancel(
                $this->actor($request),
                $this->own(StockTransfer::class, $request, $id),
                (string) $request->input('reason', ''),
            );

            return $this->ok([], 'أُلغي التحويل');
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  الجرد — المرحلة ٥
    // ══════════════════════════════════════════════════════════════════

    public function counts(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_STOCK_VIEW, function () use ($request) {
            $rows = StockCount::where('merchant_user_id', $this->merchant($request)->id)
                ->with('location:id,name')->withCount('items')
                ->orderByDesc('id')->limit(50)->get();

            return $this->ok([
                'counts' => $rows->map(fn (StockCount $c) => [
                    'id' => $c->id, 'code' => $c->code,
                    'location' => $c->location->name ?? '—',
                    'kind' => $c->kind, 'kind_ar' => $c->kindAr(),
                    'status' => $c->status, 'status_ar' => $c->statusAr(),
                    'lines' => $c->items_count,
                    'started_at' => $c->started_at?->toIso8601String(),
                ])->all(),
            ]);
        });
    }

    public function openCount(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_COUNT_START, function () use ($request) {
            $c = $this->counts->open(
                merchant: $this->merchant($request),
                locationId: (int) $request->input('location_id'),
                kind: (string) $request->input('kind', 'full'),
                categoryId: $request->input('category_id') ? (int) $request->input('category_id') : null,
                productIds: (array) $request->input('product_ids', []),
                actorId: $this->actor($request)->id,
            );

            return $this->ok(['id' => $c->id, 'code' => $c->code], 'فُتح الجرد');
        });
    }

    public function countSheet(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_COUNT_ENTER, function () use ($request, $id) {
            /** @var StockCount $c */
            $c = $this->own(StockCount::class, $request, $id);

            return $this->ok([
                'count' => [
                    'id' => $c->id, 'code' => $c->code,
                    'status' => $c->status, 'status_ar' => $c->statusAr(),
                ],
                'items' => $c->items()->orderBy('name')->get()->map(fn ($i) => [
                    'product_id' => $i->product_id,
                    'name' => $i->name,
                    // **النظامُ يُخفى عن العادّ عمداً؟ لا** — يُعرض، لأنّ
                    // إخفاءه يُبطئ الجرد ولا يمنع التلاعب: من أراد نسخَه
                    // فتح شاشةَ المخزون. **والحارسُ هو الاعتمادُ لا الإخفاء.**
                    'system' => (string) $i->system_quantity,
                    'counted' => $i->counted_quantity !== null ? (string) $i->counted_quantity : null,
                    'variance' => $i->variance !== null ? (string) $i->variance : null,
                    'reason' => $i->variance_reason,
                ])->all(),
            ]);
        });
    }

    public function enterCount(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_COUNT_ENTER, function () use ($request, $id) {
            /** @var StockCount $c */
            $c = $this->own(StockCount::class, $request, $id);

            $this->counts->countLine(
                count: $c,
                productId: (int) $request->input('product_id'),
                quantity: (string) $request->input('quantity'),
                reason: $request->input('reason'),
                note: $request->input('note'),
                actorId: $this->actor($request)->id,
            );

            return $this->ok([], 'سُجّل العدّ');
        });
    }

    public function submitCount(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_COUNT_ENTER, function () use ($request, $id) {
            $this->counts->submit($this->own(StockCount::class, $request, $id));

            return $this->ok([], 'رُفع الجرد للمراجعة');
        });
    }

    public function countVariances(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_STOCK_VIEW, fn () => $this->ok(
            $this->counts->variances($this->own(StockCount::class, $request, $id))));
    }

    public function approveCount(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_COUNT_APPROVE, function () use ($request, $id) {
            $out = $this->counts->approve(
                $this->actor($request), $this->own(StockCount::class, $request, $id));

            return $this->ok($out, 'اعتُمد الجرد وسُوّيت الفروق');
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  الهالك — المرحلة ٦
    // ══════════════════════════════════════════════════════════════════

    public function wastes(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_STOCK_VIEW, function () use ($request) {
            $merchant = $this->merchant($request);

            return $this->ok([
                'report' => $this->wastes->report($merchant, (int) $request->query('days', 30)),
                'items' => StockWaste::where('merchant_user_id', $merchant->id)
                    ->orderByDesc('id')->limit(50)->get()
                    ->map(fn (StockWaste $w) => [
                        'id' => $w->id,
                        'name' => $w->name,
                        'quantity' => (string) $w->quantity,
                        'reason' => $w->reason, 'reason_ar' => $w->reasonAr(),
                        'total_cost' => $w->total_cost !== null ? (string) $w->total_cost : null,
                        'status' => $w->status,
                        'created_at' => $w->created_at?->toIso8601String(),
                    ])->all(),
            ]);
        });
    }

    public function recordWaste(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_WASTE_RECORD, function () use ($request) {
            $w = $this->wastes->record(
                $this->merchant($request), $request->all(), $this->actor($request)->id);

            return $this->ok(['id' => $w->id], 'سُجّل الهالك — ينتظر الاعتماد');
        });
    }

    public function approveWaste(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_WASTE_APPROVE, function () use ($request, $id) {
            $this->wastes->approve(
                $this->actor($request), $this->own(StockWaste::class, $request, $id));

            return $this->ok([], 'اعتُمد الهالك وخُصم من المخزون');
        });
    }

    public function rejectWaste(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_WASTE_APPROVE, function () use ($request, $id) {
            $this->wastes->reject(
                $this->actor($request),
                $this->own(StockWaste::class, $request, $id),
                (string) $request->input('reason', ''),
            );

            return $this->ok([], 'رُفض الهالك');
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  المرتجعات — المرحلة ٦
    // ══════════════════════════════════════════════════════════════════

    public function refundableLines(Request $request, string $saleUlid): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_RETURN_CREATE, fn () => $this->ok([
            'lines' => $this->returns->refundableLines($this->merchant($request), $saleUlid),
        ]));
    }

    public function createReturn(Request $request, string $saleUlid): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_RETURN_CREATE, function () use ($request, $saleUlid) {
            $r = $this->returns->create(
                $this->merchant($request), $saleUlid,
                (array) $request->input('lines', []),
                [
                    'location_id' => $request->input('location_id'),
                    'refund_method' => $request->input('refund_method', 'cash'),
                    'reason' => $request->input('reason'),
                    'actor_id' => $this->actor($request)->id,
                ],
            );

            return $this->ok(
                ['id' => $r->id, 'total' => (string) $r->total_amount],
                'أُنشئ المرتجع — ينتظر الاعتماد');
        });
    }

    public function approveReturn(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_RETURN_APPROVE, function () use ($request, $id) {
            $this->returns->approve(
                $this->actor($request), $this->own(SaleReturn::class, $request, $id));

            return $this->ok([], 'اعتُمد المرتجع');
        });
    }

    // ══════════════════════════════════════════════════════════════════
    //  الأسعار — المرحلة ٧
    // ══════════════════════════════════════════════════════════════════

    public function proposePrice(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_PRICE_PROPOSE, function () use ($request) {
            $v = $this->prices->propose(
                $this->merchant($request), $request->all(), $this->actor($request)->id);

            return $this->ok(['id' => $v->id], 'اقتُرح السعر — ينتظر الاعتماد');
        });
    }

    public function pendingPrices(Request $request): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_PRICE_VIEW, function () use ($request) {
            $rows = ProductPriceVersion::where('merchant_user_id', $this->merchant($request)->id)
                ->where('status', ProductPriceVersion::PROPOSED)
                ->with('product:id,name')->orderByDesc('id')->get();

            return $this->ok([
                'pending' => $rows->map(fn (ProductPriceVersion $v) => [
                    'id' => $v->id,
                    'product' => $v->product->name ?? '—',
                    'price' => (string) $v->price,
                    'offer_price' => $v->offer_price !== null ? (string) $v->offer_price : null,
                    'effective_from' => $v->effective_from?->toIso8601String(),
                    'reason' => $v->reason,
                ])->all(),
            ]);
        });
    }

    public function approvePrice(Request $request, int $id): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_PRICE_APPROVE, function () use ($request, $id) {
            $v = $this->prices->approve(
                $this->actor($request), $this->own(ProductPriceVersion::class, $request, $id));

            return $this->ok(['status' => $v->status], 'اعتُمد السعر');
        });
    }

    public function priceHistory(Request $request, int $productId): JsonResponse
    {
        return $this->guarded($request, P::RETAIL_PRICE_VIEW, fn () => $this->ok([
            'history' => $this->prices->history($this->merchant($request), $productId),
        ]));
    }

    // ══════════════════════════════════════════════════════════════════
    //  الأدوار — المرحلة ٨
    // ══════════════════════════════════════════════════════════════════

    /** **الشاشةُ تُبنى من هذه** لا من نوع النشاط (المرحلة ١٠). */
    public function myPermissions(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        return $this->ok([
            'is_owner' => $this->perm->isOwner($actor),
            'permissions' => $this->perm->effective($actor),
            'catalogue' => P::catalogue(),
        ]);
    }

    public function roles(Request $request): JsonResponse
    {
        return $this->guarded($request, P::ROLE_VIEW, function () use ($request) {
            $roles = MerchantRole::where('merchant_user_id', $this->merchant($request)->id)
                ->withCount('permissions')->get();

            return $this->ok([
                'roles' => $roles->map(fn ($r) => [
                    'id' => $r->id, 'code' => $r->code, 'name' => $r->name_ar,
                    'is_system' => (bool) $r->is_system,
                    'permissions' => $r->permissions_count,
                ])->all(),
            ]);
        });
    }

    public function seedRoles(Request $request): JsonResponse
    {
        return $this->guarded($request, P::ROLE_MANAGE, function () use ($request) {
            $made = $this->perm->seedRetailRoles($this->merchant($request));

            return $this->ok(['roles' => count($made)], 'جُهّزت أدوار التجزئة');
        });
    }
}
