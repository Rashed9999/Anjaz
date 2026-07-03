<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\PaymentRequest;
use App\Services\PaymentRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-PAYMENT-REQUESTS-001 — Controller طلبات الدفع.
 *
 *   POST /api/v1/amial/payment-requests              (إنشاء)
 *   GET  /api/v1/amial/payment-requests              (?direction=outgoing|incoming&status=...)
 *   GET  /api/v1/amial/payment-requests/code/{code}  (تفاصيل بالرمز — للدافع)
 *   POST /api/v1/amial/payment-requests/code/{code}/pay  (دفع الطلب)
 *   POST /api/v1/amial/payment-requests/{id}/cancel  (إلغاء)
 */
class PaymentRequestController extends AmialApiController // AMIAL-FIX-007
{
    public function __construct(
        private readonly PaymentRequestService $service,
    ) {}

    public function create(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'recipient_phone' => 'sometimes|nullable|string|max:32',
            'recipient_name' => 'sometimes|nullable|string|max:120',
            'note' => 'sometimes|nullable|string|max:255',
            'share_method' => 'sometimes|nullable|in:link,qr',
            'is_recurring' => 'sometimes|nullable|boolean',
            'recurring_period' => 'sometimes|nullable|in:daily,weekly,monthly',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $req = $this->service->create(
                requester: $request->user(),
                amount: (string)$request->input('amount'),
                recipientPhone: $request->input('recipient_phone'),
                recipientName: $request->input('recipient_name'),
                note: $request->input('note'),
                shareMethod: $request->input('share_method', 'link'),
                isRecurring: $request->boolean('is_recurring', false),
                recurringPeriod: $request->input('recurring_period'),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->error('INVALID_REQUEST', $e->getMessage(), 422);
        }

        return $this->ok([
            'request' => $req,
            'short_code' => $req->short_code,
            'public_url' => $req->publicUrl(),
        ], 'REQUEST_CREATED', 'تم إنشاء الطلب', 201);
    }

    public function list(Request $request): JsonResponse
    {
        $direction = $request->query('direction', 'outgoing');
        $status = $request->query('status');
        $page = (int)$request->query('page', 1);

        $paginated = $this->service->listForUser(
            $request->user(), $direction, $status, $page
        );

        return $this->ok([
            'requests' => $paginated->items(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /** يعيد تفاصيل الطلب للدافع. authenticated فقط لمنع تسريب البيانات. */
    public function showByCode(Request $request, string $code): JsonResponse
    {
        $req = $this->service->findByCode($code);
        if (!$req) return $this->error('NOT_FOUND', 'الطلب غير موجود', 404);

        // إن كان مستلم محدّد، تأكّد المستخدم هو المستلم
        if ($req->recipient_user_id && $req->recipient_user_id !== $request->user()->id) {
            return $this->error('FORBIDDEN', 'هذا الطلب موجّه لشخص آخر', 403);
        }

        // معلومات الطالب (لعرضها للدافع)
        $requester = $req->requester;

        return $this->ok([
            'request' => $req,
            'requester' => [
                'name' => trim(($requester->f_name ?? '') . ' ' . ($requester->l_name ?? '')),
                'phone' => $requester->phone,
            ],
            'is_active' => $req->isActive(),
            'is_self' => $request->user()->id === $req->requester_user_id,
        ]);
    }

    public function pay(Request $request, string $code): JsonResponse
    {
        $req = $this->service->findByCode($code);
        if (!$req) return $this->error('NOT_FOUND', 'الطلب غير موجود', 404);

        try {
            $result = $this->service->pay($request->user(), $req);
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->error('PAYMENT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok($result, 'PAYMENT_OK', 'تم الدفع بنجاح');
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $req = PaymentRequest::find($id);
        if (!$req) return $this->error('NOT_FOUND', 'الطلب غير موجود', 404);

        try {
            $cancelled = $this->service->cancel($request->user(), $req);
        } catch (\InvalidArgumentException $e) {
            return $this->error('FORBIDDEN', $e->getMessage(), 403);
        } catch (\RuntimeException $e) {
            return $this->error('CANCEL_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['request' => $cancelled], 'CANCELLED', 'تم الإلغاء');
    }
}
