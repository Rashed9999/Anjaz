<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\AgentProfile;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\CustomerWithdrawService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-CUSTOMER-WITHDRAW-001 — السحب المبدوء من العميل.
 *
 *   POST /api/v1/amial/withdraw/request        (العميل ينشئ)
 *   GET  /api/v1/amial/withdraw/mine           (العميل يرى عملياته المعلّقة)
 *   POST /api/v1/amial/withdraw/{id}/cancel    (العميل يلغي)
 *   POST /api/v1/amial/agent/withdraw/lookup   (الوكيل يستعلم برقم العملية)
 *   POST /api/v1/amial/agent/withdraw/execute  (الوكيل ينفّذ)
 */
class CustomerWithdrawController extends Controller
{
    public function __construct(
        private readonly CustomerWithdrawService $service,
    ) {}

    // ---- جهة العميل ----

    public function request(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $req = $this->service->request($request->user(), (string)$request->input('amount'));
        } catch (\App\Exceptions\InsufficientBalanceException $e) {
            return new JsonResponse($e->toApiArray(), 402);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->error('WITHDRAW_REQUEST_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'op_code' => $req->op_code,
            'amount' => (string)$req->amount,
            'fee' => (string)$req->fee,
            'total_debit' => (string)$req->total_debit,
            'status' => $req->status,
            'expires_at' => $req->expires_at->toIso8601String(),
            'request_id' => $req->id,
        ], 'WITHDRAW_PENDING', 'تم إنشاء طلب السحب. أعطِ الوكيل رقم العملية.');
    }

    public function mine(Request $request): JsonResponse
    {
        // AMIAL-WD-HISTORY-001: ?status=all|pending|completed|cancelled|expired
        // (الافتراضي pending — كما تتوقّع شاشة الطلب المعلّق)
        $status = (string) $request->query('status', 'pending');
        $allowed = ['pending', 'completed', 'cancelled', 'expired'];

        $q = WithdrawalRequest::where('customer_user_id', $request->user()->id)
            ->orderByDesc('id');
        if ($status !== 'all') {
            $q->whereIn('status', in_array($status, $allowed, true) ? [$status] : ['pending']);
        }

        $list = $q->limit(100)->get([
            'id', 'op_code', 'amount', 'fee', 'total_debit', 'status',
            'expires_at', 'created_at', 'updated_at',
        ]);

        return $this->ok(['requests' => $list]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        try {
            $req = $this->service->cancel($request->user(), $id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->error('NOT_FOUND', 'العملية غير موجودة', 404);
        } catch (\RuntimeException $e) {
            return $this->error('CANCEL_FAILED', $e->getMessage(), 422);
        }
        return $this->ok(['status' => $req->status], 'WITHDRAW_CANCELLED', 'تم إلغاء العملية وفكّ الحجز');
    }

    // ---- جهة الوكيل ----

    /** استعلام برقم العملية + معرّف العميل → يعرض بطاقة العميل للتأكيد (بلا الرصيد). */
    public function lookup(Request $request): JsonResponse
    {
        if (!$this->isAgent($request->user())) {
            return $this->error('NOT_AN_AGENT', 'متاح للوكلاء فقط', 403);
        }
        $v = Validator::make($request->all(), [
            'op_code' => 'required|string|max:16',
        ]);
        if ($v->fails()) return $this->validationError($v);

        $req = WithdrawalRequest::where('op_code', $request->input('op_code'))->first();
        if (!$req || $req->status !== 'pending') {
            return $this->error('OP_NOT_FOUND', 'رقم العملية غير صحيح أو غير معلّق', 404);
        }
        if ($req->expires_at->isPast()) {
            return $this->error('OP_EXPIRED', 'انتهت صلاحية العملية', 422);
        }

        $customer = User::find($req->customer_user_id);
        return $this->ok([
            'amount' => (string)$req->amount,            // المبلغ المطلوب فقط (لا الرصيد)
            'customer' => [
                'name' => trim(($customer->f_name ?? '') . ' ' . ($customer->l_name ?? '')),
                'phone' => $customer->phone,
                'account_number' => $customer->account_number,
            ],
            'expires_at' => $req->expires_at->toIso8601String(),
        ], 'OP_OK', 'بيانات العملية');
    }

    public function execute(Request $request): JsonResponse
    {
        if (!$this->isAgent($request->user())) {
            return $this->error('NOT_AN_AGENT', 'متاح للوكلاء فقط', 403);
        }
        $v = Validator::make($request->all(), [
            'op_code' => 'required|string|max:16',
            'identifier' => 'required|string|max:32', // هاتف أو رقم حساب العميل
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $result = $this->service->execute(
                $request->user(),
                $request->input('op_code'),
                $request->input('identifier'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('WITHDRAW_EXECUTE_FAILED', $e->getMessage(), 422);
        }

        $req = $result['request'];
        return $this->ok([
            'transaction_id' => $result['transaction_id'],
            'amount' => (string)$req->amount,
            'status' => $req->status,
        ], 'WITHDRAW_COMPLETED', 'تم السحب بنجاح');
    }

    // ---- helpers ----

    private function isAgent(?User $user): bool
    {
        return $user !== null
            && ((int)$user->type === 1 || AgentProfile::where('user_id', $user->id)->exists());
    }

    private function ok(array $meta, string $code = 'OK', string $message = 'OK', int $status = 200): JsonResponse
    {
        return new JsonResponse(['success' => true, 'code' => $code, 'message' => $message, 'errors' => (object)[], 'meta' => $meta], $status);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['success' => false, 'code' => $code, 'message' => $message, 'errors' => (object)[], 'meta' => (object)[]], $status);
    }

    private function validationError($v): JsonResponse
    {
        return new JsonResponse(['success' => false, 'code' => 'VALIDATION_FAILED', 'message' => 'بيانات غير صحيحة', 'errors' => $v->errors(), 'meta' => (object)[]], 422);
    }
}
