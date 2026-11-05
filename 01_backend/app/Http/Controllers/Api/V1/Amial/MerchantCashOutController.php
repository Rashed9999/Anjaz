<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\WithdrawalRequest;
use App\Services\CustomerWithdrawService;
use App\Services\MoneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-MERCHANT-CASHOUT-001 — مالك المنشأة يسحب رصيده نقداً من أي وكيل.
 *
 *   GET  merchant/cash-out              طلباته (المعلّق يحمل رقم العملية)
 *   POST merchant/cash-out              {amount, pin} ← حجز + رقم عملية
 *   POST merchant/cash-out/{id}/cancel  فكّ الحجز قبل التنفيذ
 */
class MerchantCashOutController extends Controller
{
    public function __construct(private readonly CustomerWithdrawService $service) {}

    public function index(Request $request): JsonResponse
    {
        $rows = WithdrawalRequest::where('customer_user_id', $request->user()->id)->where('requester_type', 'merchant')
            ->latest('id')->limit(30)->get();

        return $this->ok([
            'limits' => [
                'max_per_request' => MoneyService::normalize((string) config('amial.merchant_cash_out.max_per_request', '500000')),
                'daily' => MoneyService::normalize((string) config('amial.merchant_cash_out.daily_limit', '2000000')),
            ],
            'requests' => $rows->map(fn (WithdrawalRequest $r) => [
                'id' => $r->id, 'status' => $r->status, 'amount' => (string) $r->amount, 'fee' => (string) $r->fee,
                'total_debit' => (string) $r->total_debit,
                // الرقم سرّ الصرف: يُعرض ما دام معلّقاً فقط.
                'op_code' => $r->status === 'pending' ? $r->op_code : null,
                'expires_at' => $r->expires_at?->toIso8601String(), 'completed_at' => $r->completed_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => ['required', 'string', 'regex:/^\d{1,15}(\.\d{1,2})?$/'],
            'pin' => ['required', 'string', 'min:4', 'max:8'],
        ]);
        if ($v->fails()) {
            return $this->error('VALIDATION', $v->errors()->first(), 422);
        }
        if (!Helpers::pin_check($request->user()->id, (string) $request->input('pin'))) {
            return $this->error('WRONG_PIN', 'رمز PIN غير صحيح', 403);
        }

        try {
            $req = $this->service->requestAsMerchant($request->user(), (string) $request->input('amount'));
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\App\Exceptions\AmlBlockedException $e) {
            return new JsonResponse($e->toApiArray(), 403);
        } catch (\App\Exceptions\AmlHeldException $e) {
            return new JsonResponse($e->toApiArray(), 202);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->error('CASH_OUT_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'id' => $req->id, 'op_code' => $req->op_code, 'amount' => (string) $req->amount, 'fee' => (string) $req->fee,
            'total_debit' => (string) $req->total_debit, 'expires_at' => $req->expires_at->toIso8601String(),
        ], 'CASH_OUT_PENDING', 'أعطِ الوكيل رقم العملية وهاتفك للتحقق.', 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $owned = WithdrawalRequest::whereKey($id)->where('customer_user_id', $request->user()->id)->where('requester_type', 'merchant')->exists();
        if (!$owned) {
            return $this->error('NOT_FOUND', 'العملية غير موجودة', 404);
        }
        try {
            $req = $this->service->cancel($request->user(), $id);
        } catch (\RuntimeException $e) {
            return $this->error('CANCEL_FAILED', $e->getMessage(), 422);
        }

        return $this->ok(['status' => $req->status], 'CASH_OUT_CANCELLED', 'أُلغيت العملية وفُكّ الحجز');
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse(['success' => true, 'code' => $code, 'message' => $message, 'errors' => (object) [], 'meta' => $meta], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'code' => $code, 'message' => $message, 'errors' => (object) [], 'meta' => (object) []], $status);
    }
}
