<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\MerchantSale;
use App\Models\PosUser;
use App\Models\User;
use App\Services\CashierService;
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
    public function __construct(
        private readonly CashierService $cashier,
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
        return $this->ok(['product' => $product], 'PRODUCT_ADDED', 'تم إضافة المنتج');
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
            'payment_method' => 'required|in:cash,credit,amial_pay',
            'items' => 'sometimes|array',
            'items.*.name' => 'sometimes|string|max:160',
            'items.*.qty' => 'sometimes|numeric|min:0',
            'items.*.price' => 'sometimes|numeric|min:0',
            'items.*.product_id' => 'sometimes|nullable|integer',
            'customer.name' => 'sometimes|nullable|string|max:120',
            'customer.phone' => 'sometimes|nullable|string|max:32',
            'paid_transaction_id' => 'sometimes|nullable|string|max:40',
            'credit_due_date' => 'sometimes|nullable|date_format:Y-m-d',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchantPos($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posUserId] = $ctx;

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
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('SALE_INVALID', $e->getMessage(), 422);
        }

        return $this->ok(['sale' => $sale], 'SALE_RECORDED', 'تم تسجيل البيع');
    }

    public function settleCredit(Request $request, int $id): JsonResponse
    {
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
            return [$merchant, $pos->id];
        }

        if (!MerchantProfile::where('user_id', $authUser->id)->exists()) {
            return $this->error('NOT_A_MERCHANT', 'الكاشير متاح للتجار وموظفي نقاط البيع فقط', 403);
        }
        return [$authUser, null];
    }
}
