<?php

namespace App\Http\Controllers\Api\V1\Partner;

use App\Http\Controllers\Controller;
use App\Models\MerchantSale;
use App\Services\MoneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-API-ACCESS-001 — واجهة الشركاء (مصادقة بمفتاح API).
 * تعرض بيانات التاجر صاحب المفتاح فقط.
 *
 *   GET /api/v1/partner/sales?from=&to=
 */
class PartnerController extends Controller
{
    public function sales(Request $request): JsonResponse
    {
        $merchantId = (int) $request->attributes->get('api_merchant_user_id');

        $q = MerchantSale::where('merchant_user_id', $merchantId)
            ->where('status', 'completed');
        if ($request->filled('from')) $q->whereDate('created_at', '>=', $request->query('from'));
        if ($request->filled('to')) $q->whereDate('created_at', '<=', $request->query('to'));

        $sales = $q->orderByDesc('id')->limit(500)->get();
        $total = '0';
        foreach ($sales as $s) $total = MoneyService::add($total, (string) $s->total_amount);

        return response()->json([
            'success' => true,
            'meta' => [
                'count' => $sales->count(),
                'total_amount' => MoneyService::normalize($total),
                'sales' => $sales->map(fn ($s) => [
                    'ref' => $s->sale_ulid,
                    'total' => (string) $s->total_amount,
                    'payment_method' => $s->payment_method,
                    'created_at' => $s->created_at?->toIso8601String(),
                ]),
            ],
        ]);
    }
}
