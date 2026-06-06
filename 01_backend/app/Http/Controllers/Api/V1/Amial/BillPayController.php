<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\BillPaymentOrder;
use App\Models\BillProvider;
use App\Models\BillService;
use App\Models\BillServiceProduct;
use App\Services\BillPayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-BILL-PAY-001 (v0.9-C)
 */
class BillPayController extends Controller
{
    public function __construct(
        private readonly BillPayService $service,
    ) {}

    /** GET /api/v1/amial/bill-pay/providers — قائمة المزودين النشطين */
    public function listProviders(Request $request): JsonResponse
    {
        $providers = BillProvider::where('is_active', true)
            ->where('zone_code', 'SOUTH')
            ->with(['services' => fn($q) => $q->where('is_active', true)])
            ->get();

        return $this->ok(['providers' => $providers]);
    }

    /** GET /api/v1/amial/bill-pay/services/{service_id}/products */
    public function listProducts(Request $request, int $serviceId): JsonResponse
    {
        $service = BillService::find($serviceId);
        if (!$service || !$service->is_active) {
            return $this->error('SERVICE_NOT_FOUND', 'Service not found or inactive', 404);
        }

        $products = BillServiceProduct::where('service_id', $serviceId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return $this->ok(['products' => $products]);
    }

    /** POST /api/v1/amial/bill-pay/pay */
    public function pay(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'service_id' => 'required|integer|exists:bill_services,id',
            'product_id' => 'sometimes|nullable|integer|exists:bill_service_products,id',
            'subscriber_account' => 'required|string|min:3|max:100',
            'amount' => 'required|numeric|min:0.01',
            'subscriber_extra' => 'sometimes|array',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $service = BillService::find($request->input('service_id'));
        $product = $request->input('product_id') ? BillServiceProduct::find($request->input('product_id')) : null;
        $provider = $service->provider;

        if ($product && $product->amount_type === 'fixed' && (string)$product->fixed_amount !== (string)$request->input('amount')) {
            return $this->error('AMOUNT_MISMATCH', 'Amount does not match fixed product price', 422);
        }
        if ($product && $product->amount_type === 'variable') {
            $amt = (float)$request->input('amount');
            if ($product->min_amount && $amt < (float)$product->min_amount) {
                return $this->error('AMOUNT_TOO_LOW', 'Amount below minimum', 422);
            }
            if ($product->max_amount && $amt > (float)$product->max_amount) {
                return $this->error('AMOUNT_TOO_HIGH', 'Amount above maximum', 422);
            }
        }

        try {
            $order = $this->service->createAndExecute(
                user: $request->user(),
                provider: $provider,
                service: $service,
                product: $product,
                subscriberAccount: $request->input('subscriber_account'),
                amount: (string)$request->input('amount'),
                subscriberExtra: $request->input('subscriber_extra', []),
            );
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\RuntimeException $e) {
            return $this->error('BILL_PAY_FAILED', $e->getMessage(), 422);
        }

        $code = match ($order->status) {
            'success' => 'BILL_PAY_OK',
            'failed' => 'BILL_PAY_FAILED',
            'pending_provider_confirmation' => 'BILL_PAY_PENDING',
            default => 'BILL_PAY_UNKNOWN',
        };

        return $this->ok(['order' => $order], $code, $order->provider_message ?? 'Order processed');
    }

    public function showOrder(Request $request, string $ulid): JsonResponse
    {
        $order = BillPaymentOrder::where('order_ulid', $ulid)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$order) return $this->error('NOT_FOUND', 'Order not found', 404);

        return $this->ok(['order' => $order]);
    }

    public function listOrders(Request $request): JsonResponse
    {
        $orders = BillPaymentOrder::where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate(20);

        return $this->ok([
            'pagination' => [
                'total' => $orders->total(),
                'per_page' => $orders->perPage(),
                'current_page' => $orders->currentPage(),
            ],
            'items' => $orders->items(),
        ]);
    }

    // Helpers
    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse([
            'success' => true,
            'code' => $code,
            'message' => $message,
            'errors' => (object)[],
            'meta' => $meta,
        ], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => (object)[],
            'meta' => (object)[],
        ], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'code' => 'VALIDATION_FAILED',
            'message' => 'Invalid input',
            'errors' => $v->errors(),
            'meta' => (object)[],
        ], 422);
    }
}
