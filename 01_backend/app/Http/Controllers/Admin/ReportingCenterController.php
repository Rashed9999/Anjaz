<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\LedgerReportService;
use App\Services\Reporting\FinancialStatementsService;
use App\Services\Reporting\ReportCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-REPORTING-CENTER-001 — بوابة التقارير المؤسسية.
 *
 * لا يحسب هذا المتحكم المال. يستدعي فقط مصادر الحقيقة القائمة ثم يسجل
 * الاطلاع في AuditService. الوصول نفسه خلف platform.reports.view.
 */
class ReportingCenterController extends Controller
{
    public function __construct(
        private readonly ReportCatalogService $catalog,
        private readonly FinancialStatementsService $statements,
        private readonly LedgerReportService $ledger,
        private readonly AuditService $audit,
    ) {
    }

    public function index(Request $request)
    {
        $actor = auth('user')->user();
        $summary = $this->catalog->summary();

        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor?->id,
            'subject_type' => 'reporting_center',
            'action' => 'REPORTING_CENTER_VIEWED',
            'decision_code' => 'ALLOW',
            'severity' => 'info',
            'context' => ['catalog_total' => $summary['total']],
        ]);

        return view('admin-views.amial.reporting-center.index', [
            'catalog' => $this->catalog->catalog(),
            'summary' => $summary,
            'canExport' => (bool) $actor?->hasPlatformPermission('platform.reports.export'),
        ]);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $payload = $this->ledger->trialBalance($from, $to);
        $this->auditRead($request, 'trial_balance', ['from' => $from, 'to' => $to]);

        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $payload = $this->statements->incomeStatement($from, $to);
        $this->auditRead($request, 'income_statement', ['from' => $from, 'to' => $to]);

        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'as_of' => ['nullable', 'date_format:Y-m-d'],
        ]);
        abort_if($validator->fails(), 422, $validator->errors()->first());

        $asOf = $request->query('as_of') ?: now()->toDateString();
        $payload = $this->statements->balanceSheet($asOf);
        $this->auditRead($request, 'balance_sheet', ['as_of' => $asOf]);

        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $limit = min(500, max(10, (int) $request->query('limit', 100)));
        $payload = $this->ledger->walletReconciliation($limit);
        $this->auditRead($request, 'wallet_reconciliation', ['limit' => $limit]);

        return response()->json(['success' => true, 'meta' => $payload]);
    }

    private function period(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        abort_if($validator->fails(), 422, $validator->errors()->first());

        $from = $request->query('from');
        $to = $request->query('to');

        return [$from ?: null, $to ?: null];
    }

    private function auditRead(Request $request, string $report, array $filters): void
    {
        $actor = auth('user')->user();
        $this->audit->record([
            'actor_type' => 'admin',
            'actor_user_id' => $actor?->id,
            'subject_type' => 'report',
            'subject_id' => $report,
            'action' => 'REPORT_VIEWED',
            'decision_code' => 'ALLOW',
            'severity' => 'info',
            'context' => [
                'report' => $report,
                'filters' => $filters,
                'request_id' => $request->header('X-Request-ID'),
            ],
        ]);
    }
}
