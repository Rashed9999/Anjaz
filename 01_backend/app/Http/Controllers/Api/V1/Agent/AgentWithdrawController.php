<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\WithdrawRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentWithdrawController extends Controller
{
    public function __construct(
        private WithdrawRequest $withdrawRequest,
    ){}

    public function list(Request $request): JsonResponse
    {
        $withdrawRequests = $this->withdrawRequest->with('user', 'withdrawal_method')
            ->where(['user_id' => auth()->user()->id])
            ->latest()
            ->get();

        return response()->json(response_formatter(DEFAULT_200, $withdrawRequests, null), 200);
    }
}
