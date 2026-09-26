<?php

namespace App\Http\Controllers\Merchant;

use App\Http\Controllers\Controller;
use App\Models\CustomerCreditAccount;
use App\Services\CreditSourceSettlementService;
use App\Services\LedgerReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Owner-only read views: financial movements remain in their existing services.
 * merchant.web middleware prevents POS and customers entering these routes.
 */
class WebFinanceController extends Controller
{
    public function walletVerification(Request $request, LedgerReportService $ledger): JsonResponse
    {
        $owner = $request->user('merchant_web');

        return response()->json([
            'success' => true, 'code' => 'WALLET_VERIFICATION',
            'message' => 'حالة مطابقة محفظة المنشأة مع الدفتر',
            'errors' => (object) [],
            'meta' => ['verification' => $ledger->walletTruth((int) $owner->id)],
        ]);
    }

    public function debtInvoices(
        Request $request,
        int $id,
        CreditSourceSettlementService $sources
    ): JsonResponse {
        $account = CustomerCreditAccount::query()
            ->where('merchant_user_id', (int) $request->user('merchant_web')->id)
            ->whereKey($id)
            ->first();

        if ($account === null) {
            return response()->json([
                'success' => false, 'code' => 'NOT_FOUND',
                'message' => 'حساب الآجل غير موجود ضمن منشأتك',
                'errors' => (object) [], 'meta' => (object) [],
            ], 404);
        }

        return response()->json([
            'success' => true, 'code' => 'DEBT_INVOICES',
            'message' => 'الفواتير الآجلة المتبقية',
            'errors' => (object) [],
            'meta' => ['account_id' => $account->id] + $sources->balanceBreakdown($account),
        ]);
    }
}
