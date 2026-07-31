<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\LedgerReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AMIAL-LEDGER-CENTER-001 — مركز الدفتر (الفصل ١٧).
 *
 * أُصلح الدفتر بالكامل في هذا المشروع ولم تكن تقرؤه شاشة. وميزان المراجعة
 * أوّل ما يطلبه المدقّق أو المنظّم — ولم يكن يُخرجه النظام إطلاقاً.
 */
class LedgerCenterController extends Controller
{
    public function __construct(
        private readonly LedgerReportService $reports,
    ) {
    }

    public function page()
    {
        return view('admin-views.amial.ledger.index');
    }

    public function trialBalance(Request $request): JsonResponse
    {
        return $this->ok($this->reports->trialBalance(
            $request->query('from'), $request->query('to'),
        ));
    }

    public function accounts(Request $request): JsonResponse
    {
        return $this->ok(['items' => $this->reports->chartOfAccounts($request->query('type'))]);
    }

    public function statement(Request $request, int $id): JsonResponse
    {
        return $this->ok($this->reports->accountStatement(
            $id, $request->query('from'), $request->query('to'),
        ));
    }

    public function reconciliation(): JsonResponse
    {
        return $this->ok($this->reports->walletReconciliation());
    }

    public function entries(Request $request): JsonResponse
    {
        return $this->ok([
            'items' => $this->reports->searchEntries($request->only([
                'ulid', 'source_type', 'from', 'to', 'min_amount',
            ])),
            'source_types' => $this->reports->sourceTypes(),
        ]);
    }

    private function ok(array $meta): JsonResponse
    {
        return new JsonResponse([
            'success' => true, 'code' => 'OK', 'message' => 'OK',
            'errors' => (object) [], 'meta' => $meta,
        ]);
    }
}
