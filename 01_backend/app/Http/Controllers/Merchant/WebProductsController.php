<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Services\Merchant\MerchantProductDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WebProductsController extends Controller
{
    public function index(Request $request, MerchantProductDirectoryService $products): JsonResponse
    {
        $v = Validator::make($request->query(), [
            'search' => 'sometimes|nullable|string|max:160',
            'status' => 'sometimes|nullable|in:all,active,inactive',
            'low_stock_only' => 'sometimes|boolean',
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

        return response()->json([
            'success' => true,
            'code' => 'OK',
            'message' => 'دليل المنتجات',
            'errors' => (object) [],
            'meta' => $products->search($owner, $v->validated()),
        ]);
    }
}
