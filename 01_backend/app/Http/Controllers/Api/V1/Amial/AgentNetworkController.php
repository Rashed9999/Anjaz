<?php

namespace App\Http\Controllers\Api\V1\Amial;

use App\Http\Controllers\Controller;
use App\Models\AgentSettlement;
use App\Services\AgentNetworkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-AGENT-NETWORK-001 (v2.4) — agent network API.
 */
class AgentNetworkController extends Controller
{
    public function __construct(
        private readonly AgentNetworkService $network,
    ) {}

    /** GET /api/v1/amial/agent/float-dashboard */
    public function floatDashboard(Request $request): JsonResponse
    {
        $dashboard = $this->network->getFloatDashboard($request->user()->id);
        return $this->ok($dashboard);
    }

    /** GET /api/v1/amial/agent/float-statement?from=&to= — كشف حركة الرصيد */
    public function floatStatement(Request $request): JsonResponse
    {
        $statement = $this->network->getFloatStatement(
            $request->user()->id,
            $request->query('from'),
            $request->query('to'),
        );
        return $this->ok($statement);
    }

    /** POST /api/v1/amial/agent/topup-request */
    public function requestTopup(Request $request): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'payment_method' => 'sometimes|nullable|in:bank,cash,internal',
            'payment_reference' => 'sometimes|nullable|string|max:100',
        ]);
        if ($v->fails()) return $this->validationError($v);

        try {
            $settlement = $this->network->requestTopup(
                $request->user(),
                (string)$request->input('amount'),
                null,
                $request->input('payment_method', 'cash'),
                $request->input('payment_reference'),
            );
        } catch (\RuntimeException $e) {
            return $this->error('TOPUP_FAILED', $e->getMessage(), 422);
        }

        return $this->ok([
            'settlement_ulid' => $settlement->settlement_ulid,
            'amount' => (string)$settlement->amount,
            'status' => $settlement->status,
        ], 'TOPUP_REQUESTED', 'تم إرسال طلب شراء الرصيد. ينتظر الموافقة.');
    }

    /** GET /api/v1/amial/agent/settlements */
    public function settlements(Request $request): JsonResponse
    {
        $settlements = AgentSettlement::where('agent_user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['settlement_ulid', 'settlement_type', 'amount', 'status', 'created_at', 'completed_at']);

        return $this->ok(['settlements' => $settlements]);
    }

    /** GET /api/v1/amial/agent/distributor-network (للموزّعين فقط) */
    public function distributorNetwork(Request $request): JsonResponse
    {
        try {
            $data = $this->network->getDistributorNetwork($request->user()->id);
        } catch (\RuntimeException $e) {
            return $this->error('NOT_DISTRIBUTOR', $e->getMessage(), 403);
        }
        return $this->ok($data);
    }

    // ============================================================
    private function ok(array $meta, string $code = 'OK', string $message = 'OK'): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => $code, 'message' => $message,
            'errors' => (object)[], 'meta' => $meta,
        ]);
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
