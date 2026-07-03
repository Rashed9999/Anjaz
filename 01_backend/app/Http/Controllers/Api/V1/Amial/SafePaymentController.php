<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\SafePayment;
use App\Models\User;
use App\Services\SafePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-SAFE-PAYMENT-001 (v1.1) — customer endpoints
 */
class SafePaymentController extends AmialApiController // AMIAL-FIX-007
{
    public function __construct(
        private readonly SafePaymentService $service,
    ) {}

    /** GET /api/v1/amial/safe-payments — قائمة (as buyer or seller) */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $request->query('role', 'all'); // all|buyer|seller
        $status = $request->query('status'); // optional filter

        $query = SafePayment::query()->orderByDesc('id');
        if ($role === 'buyer') {
            $query->asBuyer($user->id);
        } elseif ($role === 'seller') {
            $query->asSeller($user->id);
        } else {
            $query->forUser($user->id);
        }
        if ($status) {
            $query->where('status', $status);
        }

        $items = $query->with(['buyer:id,f_name,l_name,phone', 'seller:id,f_name,l_name,phone'])
            ->paginate(20);

        return $this->ok([
            'pagination' => [
                'total' => $items->total(),
                'per_page' => $items->perPage(),
                'current_page' => $items->currentPage(),
            ],
            'items' => $items->items(),
        ]);
    }

    /** GET /api/v1/amial/safe-payments/{ulid} — تفاصيل */
    public function show(Request $request, string $ulid): JsonResponse
    {
        $payment = SafePayment::where('payment_ulid', $ulid)->first();
        if (!$payment) return $this->error('NOT_FOUND', 'Safe payment not found', 404);

        $user = $request->user();
        if ($payment->buyer_user_id !== $user->id && $payment->seller_user_id !== $user->id) {
            return $this->error('FORBIDDEN', 'You are not party to this payment', 403);
        }

        $payment->load([
            'buyer:id,f_name,l_name,phone',
            'seller:id,f_name,l_name,phone',
            'events' => fn($q) => $q->orderBy('created_at'),
        ]);

        return $this->ok([
            'payment' => $payment,
            'your_role' => $payment->buyer_user_id === $user->id ? 'buyer' : 'seller',
            'can_actions' => $this->resolveAvailableActions($payment, $user),
        ]);
    }

    /** POST /api/v1/amial/safe-payments — إنشاء + دفع */
    public function create(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'seller_phone' => 'required|string|min:6|max:20',
            'title' => 'required|string|min:3|max:200',
            'description' => 'required|string|min:10|max:5000',
            'amount' => 'required|numeric|min:1',
            'delivery_terms' => 'sometimes|nullable|string|max:5000',
            'attachments' => 'sometimes|nullable|array|max:5',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $seller = User::where('phone', $request->input('seller_phone'))->first();
        if (!$seller) return $this->error('SELLER_NOT_FOUND', 'البائع غير مسجل في النظام', 422);

        try {
            $payment = $this->service->createAndFund(
                buyer: $request->user(),
                seller: $seller,
                title: $request->input('title'),
                description: $request->input('description'),
                amount: (string)$request->input('amount'),
                deliveryTerms: $request->input('delivery_terms'),
                attachments: $request->input('attachments'),
            );
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\RuntimeException $e) {
            return $this->error('CREATE_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['payment' => $payment], 'SAFE_PAYMENT_CREATED', 'تم إنشاء الطلب وحجز المبلغ', 201);
    }

    /** POST /api/v1/amial/safe-payments/{ulid}/seller-accept */
    public function sellerAccept(Request $request, string $ulid): JsonResponse
    {
        return $this->execAction($ulid, $request->user(), 'seller',
            fn($p, $u) => $this->service->sellerAccept($p, $u, $request->input('note')),
            'SAFE_PAYMENT_ACCEPTED', 'تم قبول الطلب'
        );
    }

    public function sellerReject(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => 'required|string|min:5|max:500']);
        if ($v->fails()) return $this->validationError($v);

        return $this->execAction($ulid, $request->user(), 'seller',
            fn($p, $u) => $this->service->sellerReject($p, $u, $request->input('reason')),
            'SAFE_PAYMENT_REJECTED', 'تم رفض الطلب، استرداد المشتري'
        );
    }

    public function sellerMarkInDelivery(Request $request, string $ulid): JsonResponse
    {
        return $this->execAction($ulid, $request->user(), 'seller',
            fn($p, $u) => $this->service->sellerMarkInDelivery($p, $u, $request->input('note')),
            'SAFE_PAYMENT_IN_DELIVERY', 'تم تأكيد بدء التسليم'
        );
    }

    public function sellerMarkDelivered(Request $request, string $ulid): JsonResponse
    {
        return $this->execAction($ulid, $request->user(), 'seller',
            fn($p, $u) => $this->service->sellerMarkDelivered($p, $u, $request->input('note')),
            'SAFE_PAYMENT_DELIVERED', 'تم تأكيد التسليم — في انتظار تأكيد المشتري'
        );
    }

    public function buyerConfirm(Request $request, string $ulid): JsonResponse
    {
        return $this->execAction($ulid, $request->user(), 'buyer',
            fn($p, $u) => $this->service->buyerConfirm($p, $u),
            'SAFE_PAYMENT_RELEASED', 'تم تأكيد الاستلام وإفراج المبلغ للبائع'
        );
    }

    public function buyerCancel(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => 'required|string|min:5|max:500']);
        if ($v->fails()) return $this->validationError($v);

        return $this->execAction($ulid, $request->user(), 'buyer',
            fn($p, $u) => $this->service->buyerCancel($p, $u, $request->input('reason')),
            'SAFE_PAYMENT_CANCELLED', 'تم إلغاء الطلب واسترداد المبلغ'
        );
    }

    public function buyerDispute(Request $request, string $ulid): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:5000',
            'attachments' => 'sometimes|nullable|array|max:5',
        ]);
        if ($v->fails()) return $this->validationError($v);

        return $this->execAction($ulid, $request->user(), 'buyer',
            fn($p, $u) => $this->service->buyerDispute($p, $u, $request->input('reason'), $request->input('attachments')),
            'SAFE_PAYMENT_DISPUTED', 'تم فتح نزاع — ستراجع الإدارة الطلب'
        );
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function execAction(
        string $ulid, User $user, string $role,
        \Closure $action, string $okCode, string $okMessage,
    ): JsonResponse {
        $payment = SafePayment::where('payment_ulid', $ulid)->first();
        if (!$payment) return $this->error('NOT_FOUND', 'Safe payment not found', 404);

        $partyId = $role === 'buyer' ? $payment->buyer_user_id : $payment->seller_user_id;
        if ($partyId !== $user->id) {
            return $this->error('FORBIDDEN', "Action allowed only for the {$role}", 403);
        }

        try {
            $updated = $action($payment, $user);
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\RuntimeException $e) {
            return $this->error('ACTION_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['payment' => $updated], $okCode, $okMessage);
    }

    private function resolveAvailableActions(SafePayment $payment, User $user): array
    {
        $isBuyer = $payment->buyer_user_id === $user->id;
        $isSeller = $payment->seller_user_id === $user->id;

        return [
            'seller_accept' => $isSeller && $payment->canSellerRespond(),
            'seller_reject' => $isSeller && $payment->canSellerRespond(),
            'seller_mark_in_delivery' => $isSeller && $payment->canSellerMarkInDelivery(),
            'seller_mark_delivered' => $isSeller && $payment->canSellerMarkDelivered(),
            'buyer_confirm' => $isBuyer && $payment->canBuyerConfirm(),
            'buyer_cancel' => $isBuyer && $payment->canBuyerCancel(),
            'buyer_dispute' => $isBuyer && $payment->canBuyerDispute(),
        ];
    }
}
