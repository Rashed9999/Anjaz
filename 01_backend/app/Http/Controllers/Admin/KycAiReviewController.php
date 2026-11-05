<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Kyc\KycAiReviewService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycAiReviewController extends Controller
{
    public function latest(Request $request, int $id, KycAiReviewService $ai): JsonResponse
    {
        $subject = $this->subject($id);
        try {
            return response()->json(['success' => true, 'data' => $ai->latest($subject, $request->user())]);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        }
    }

    public function run(Request $request, int $id, KycAiReviewService $ai): JsonResponse
    {
        $subject = $this->subject($id);
        try {
            return response()->json(['success' => true, 'data' => $ai->run($subject, $request->user()),
                'message' => 'أُنشئ تقرير استشاري؛ قرار التوثيق ما زال بيد المراجع.']);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function subject(int $id): User
    {
        return User::query()
            ->whereIn('type', [CUSTOMER_TYPE, AGENT_TYPE, MERCHANT_TYPE])
            ->findOrFail($id);
    }
}
