<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Services\MerchantFinancialTruthReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Merchant Portal V2 dashboard.
 *
 * This controller is presentation-only: sales/wallet/receivable truth stays in
 * MerchantFinancialTruthReportService. It merely composes the dashboard payload
 * for the authenticated merchant-web owner.
 */
class WebDashboardController extends Controller
{
    public function show(
        Request $request,
        MerchantFinancialTruthReportService $financialTruth,
    ): JsonResponse {
        $validator = Validator::make($request->query(), [
            'days' => 'sometimes|integer|min:7|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'code' => 'VALIDATION',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
                'meta' => (object) [],
            ], 422);
        }

        $owner = $request->user('merchant_web');
        $days = (int) $request->query('days', 14);

        return response()->json([
            'success' => true,
            'code' => 'OK',
            'message' => 'لوحة التاجر',
            'errors' => (object) [],
            'meta' => [
                'financial' => $financialTruth->report($owner),
                'dashboard' => $financialTruth->dashboard($owner, $days),
            ],
        ]);
    }
}
