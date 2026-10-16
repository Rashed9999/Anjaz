<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\MerchantProfile;
use App\Services\Merchant\MerchantSalesDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WebSalesController extends Controller
{
    public function index(Request $request, MerchantSalesDirectoryService $sales): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'from' => 'sometimes|nullable|date',
            'to' => 'sometimes|nullable|date|after_or_equal:from',
            'payment_method' => 'sometimes|nullable|string|max:32',
            'status' => 'sometimes|nullable|string|max:40',
            'employee_id' => 'sometimes|nullable|integer|min:1',
            'search' => 'sometimes|nullable|string|max:120',
        ]);
        if ($v->fails()) {
            return response()->json([
                'success' => false,
                'code' => 'VALIDATION',
                'message' => $v->errors()->first(),
                'errors' => $v->errors(),
                'meta' => (object) [],
            ], 422);
        }

        $owner = $request->user('merchant_web');
        if (! $owner || ! MerchantProfile::where('user_id', $owner->id)->exists()) {
            return response()->json([
                'success' => false,
                'code' => 'OWNER_ONLY',
                'message' => 'سجل المبيعات متاح لمالك المنشأة فقط',
                'errors' => (object) [],
                'meta' => (object) [],
            ], 403);
        }

        return response()->json([
            'success' => true,
            'code' => 'OK',
            'message' => 'سجل المبيعات',
            'errors' => (object) [],
            'meta' => $sales->search($owner, $v->validated()),
        ]);
    }
}
