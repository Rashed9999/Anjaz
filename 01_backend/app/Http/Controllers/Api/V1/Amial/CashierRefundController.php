<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Models\MerchantRefund;
use App\Models\MerchantSale;
use App\Models\PosUser;
use App\Models\User;
use App\Services\MerchantSaleRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-CASHIER-REFUND-001 — مرتجعات بيوع الكاشير.
 *
 *   POST /api/v1/amial/merchant/cashier/sales/{ulid}/refund   (إنشاء مرتجع)
 *   GET  /api/v1/amial/merchant/cashier/refunds               (قائمة مرتجعات التاجر)
 *   GET  /api/v1/amial/merchant/cashier/refunds/{id}          (تفاصيل مرتجع)
 *   GET  /api/v1/amial/merchant/cashier/sales/{ulid}/refundable (الكمية المتبقّية للاسترداد)
 */
class CashierRefundController extends Controller
{
    public function __construct(
        private readonly MerchantSaleRefundService $refundSvc,
    ) {}

    /** إنشاء مرتجع جديد. */
    public function create(Request $request, string $saleUlid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'refund_method' => 'required|string|in:cash,wallet,credit_account',
            'items' => 'sometimes|array',
            'items.*.name' => 'required_with:items|string|max:120',
            'items.*.qty' => 'required_with:items|numeric|min:0.01',
            'items.*.price' => 'required_with:items|numeric|min:0',
            'reason' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant, $posUserId] = $ctx;

        try {
            $refund = $this->refundSvc->refund(
                merchant: $merchant,
                originalSaleUlid: $saleUlid,
                refundAmount: (string) $request->input('amount'),
                refundMethod: $request->input('refund_method'),
                items: $request->input('items', []),
                reason: $request->input('reason'),
                posUserId: $posUserId,
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->error('REFUND_FAILED', $e->getMessage(), 422);
        }

        $code = $refund->status === 'pending_approval' ? 'PENDING_APPROVAL' : 'REFUNDED';
        $msg = $refund->status === 'pending_approval'
            ? 'المرتجع بانتظار موافقة الإدارة'
            : 'تم تسجيل المرتجع بنجاح';

        return $this->ok(['refund' => $refund], $code, $msg, $refund->status === 'pending_approval' ? 202 : 201);
    }

    /** قائمة مرتجعات التاجر (مع pagination). */
    public function index(Request $request): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $page = MerchantRefund::where('merchant_user_id', $merchant->id)
            ->orderByDesc('id')
            ->paginate(20);

        return $this->ok([
            'refunds' => $page->items(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** تفاصيل مرتجع. */
    public function show(Request $request, int $id): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $refund = MerchantRefund::where('id', $id)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$refund) return $this->error('NOT_FOUND', 'المرتجع غير موجود', 404);

        return $this->ok(['refund' => $refund]);
    }

    /**
     * كم متبقّي للاسترداد من عملية محدّدة.
     * مفيد للواجهة قبل عرض شاشة المرتجع.
     */
    public function refundable(Request $request, string $saleUlid): JsonResponse
    {
        $ctx = $this->resolveMerchant($request);
        if ($ctx instanceof JsonResponse) return $ctx;
        [$merchant] = $ctx;

        $sale = MerchantSale::where('sale_ulid', $saleUlid)
            ->where('merchant_user_id', $merchant->id)
            ->first();
        if (!$sale) return $this->error('NOT_FOUND', 'العملية غير موجودة', 404);

        $refundedSoFar = (string) MerchantRefund::where('original_sale_ulid', $saleUlid)
            ->where('status', '!=', 'rejected')
            ->sum('refund_amount');
        $refundedSoFar = $refundedSoFar ?: '0';

        $remaining = \App\Services\MoneyService::sub((string)$sale->total_amount, $refundedSoFar);

        // طرق الاسترداد المتاحة حسب البيع الأصلي
        $availableMethods = ['cash']; // النقد دائماً متاح
        if ($sale->payment_method === 'credit') {
            $availableMethods[] = 'credit_account';
        }
        if (!empty($sale->customer_phone) && User::where('phone', $sale->customer_phone)->exists()) {
            $availableMethods[] = 'wallet';
        }

        return $this->ok([
            'sale' => $sale,
            'refunded_so_far' => \App\Services\MoneyService::normalize($refundedSoFar),
            'remaining' => \App\Services\MoneyService::normalize($remaining),
            'fully_refunded' => \App\Services\MoneyService::compare($remaining, '0') <= 0,
            'available_methods' => $availableMethods,
        ]);
    }

    // ---- helpers ----

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
            return $this->error('NOT_A_MERCHANT', 'متاح للتجار وموظفي نقاط البيع فقط', 403);
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
