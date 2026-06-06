<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\PosUser;
use App\Services\MerchantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-MERCHANT-001 (v1.7) — merchant endpoints
 */
class MerchantController extends Controller
{
    public function __construct(
        private readonly MerchantService $service,
    ) {}

    /** POST /api/v1/amial/merchant/refund */
    public function refund(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'original_transaction_id' => 'required|string|max:64',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'sometimes|nullable|string|max:500',
        ]);
        if ($v->fails()) return $this->validationError($v);

        // لو الطالب POS user، مرر pos_user_id
        $posUserId = $this->resolvePosUserId($request);

        try {
            $result = $this->service->processRefund(
                merchant: $this->resolveMerchant($request),
                originalTransactionId: $request->input('original_transaction_id'),
                refundAmount: (string) $request->input('amount'),
                reason: $request->input('reason'),
                posUserId: $posUserId,
            );
        } catch (\RuntimeException $e) {
            return $this->error('REFUND_FAILED', $e->getMessage(), 422);
        }

        $status = $result['status'] === 'pending_approval' ? 202 : 201;
        return $this->ok($result,
            $result['status'] === 'pending_approval' ? 'PENDING_APPROVAL' : 'REFUNDED',
            $result['message'] ?? 'تم الاسترجاع بنجاح', $status);
    }

    /** GET /api/v1/amial/merchant/ledger */
    public function ledger(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $data = $this->service->getLedger($this->resolveMerchant($request), $page);
        return $this->ok($data);
    }

    /** GET /api/v1/amial/merchant/daily-stats */
    public function dailyStats(Request $request): JsonResponse
    {
        $stats = $this->service->getDailyStats($this->resolveMerchant($request));
        return $this->ok($stats);
    }

    // ============================================================
    private function resolveMerchant(Request $request)
    {
        // لو POS user، التاجر الرئيسي هو merchant_user_id
        $user = $request->user();
        $posUser = PosUser::where('user_id', $user->id)->first();
        if ($posUser) {
            return \App\Models\User::find($posUser->merchant_user_id);
        }
        return $user;
    }

    private function resolvePosUserId(Request $request): ?int
    {
        $posUser = PosUser::where('user_id', $request->user()->id)->first();
        return $posUser?->id;
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
