<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AmlDashboardService;
use App\Services\AuditService;
use App\Services\FeeProfitReportService;
use App\Services\LedgerReportService;
use App\Services\Reporting\CashLiquidityReportService;
use App\Services\Reporting\FinancialStatementsService;
use App\Services\Reporting\GeneralLedgerReportService;
use App\Services\Reporting\P1BusinessOperationsReportService;
use App\Services\Reporting\P1ControlReportService;
use App\Services\Reporting\ReportCatalogService;
use App\Services\Reporting\TransactionMonitoringReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * AMIAL-REPORTING-CENTER-001/003 — بوابة التقارير المؤسسية.
 *
 * لا يحسب هذا المتحكم المال. يستدعي فقط مصادر الحقيقة القائمة ثم يسجل
 * الاطلاع في AuditService. الوصول نفسه خلف platform.reports.view.
 */
class ReportingCenterController extends Controller
{
    public function __construct(
        private readonly ReportCatalogService $catalog,
        private readonly FinancialStatementsService $statements,
        private readonly CashLiquidityReportService $cashLiquidity,
        private readonly TransactionMonitoringReportService $transactions,
        private readonly GeneralLedgerReportService $generalLedger,
        private readonly P1ControlReportService $p1,
        private readonly P1BusinessOperationsReportService $businessOps,
        private readonly AmlDashboardService $aml,
        private readonly FeeProfitReportService $fees,
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
        $payload = $this->statements->trialBalance($from, $to);
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
        $asOf = $this->asOf($request);
        $payload = $this->statements->balanceSheet($asOf);
        $this->auditRead($request, 'balance_sheet', ['as_of' => $asOf]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function cashFlow(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $payload = $this->cashLiquidity->cashFlow($from, $to);
        $this->auditRead($request, 'cash_flow', ['from' => $from, 'to' => $to]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function liquidity(Request $request): JsonResponse
    {
        $asOf = $this->asOf($request);
        $payload = $this->cashLiquidity->liquidityPosition($asOf);
        $this->auditRead($request, 'liquidity_position', ['as_of' => $asOf]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function safeguardedFunds(Request $request): JsonResponse
    {
        $asOf = $this->asOf($request);
        $payload = $this->cashLiquidity->safeguardedFunds($asOf);
        $this->auditRead($request, 'safeguarded_funds', ['as_of' => $asOf]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function transactionVolume(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $payload = $this->transactions->volume($from, $to);
        $this->auditRead($request, 'transaction_volume', ['from' => $from, 'to' => $to]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function transactionExceptions(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $validator = Validator::make($request->query(), ['limit' => ['nullable', 'integer', 'min:10', 'max:200']]);
        abort_if($validator->fails(), 422, $validator->errors()->first());
        $limit = (int) $request->query('limit', 50);
        $payload = $this->transactions->exceptions($from, $to, $limit);
        $this->auditRead($request, 'failed_reversed_pending', ['from' => $from, 'to' => $to, 'limit' => $limit]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function generalLedger(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'currency' => ['nullable', 'string', 'max:8'],
            'source_type' => ['nullable', 'string', 'max:100'],
            'account_code' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        abort_if($validator->fails(), 422, $validator->errors()->first());
        $filters = $validator->validated();
        $payload = $this->generalLedger->report($filters);
        $this->auditRead($request, 'general_ledger', $filters);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function feesCommissions(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $fromCarbon = Carbon::parse($from ?: now()->startOfMonth()->toDateString())->startOfDay();
        $toCarbon = Carbon::parse($to ?: now()->toDateString())->endOfDay();
        $report = $this->fees->forPeriod($fromCarbon, $toCarbon);
        $report['from'] = $fromCarbon->toDateString();
        $report['to'] = $toCarbon->toDateString();
        $report['report'] = 'fees_commissions';
        $report['basis'] = 'transactions charges + measured platform/agent credits';
        $this->auditRead($request, 'fees_commissions', ['from' => $report['from'], 'to' => $report['to']]);
        return response()->json(['success' => true, 'meta' => $report]);
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $limit = min(500, max(10, (int) $request->query('limit', 100)));
        $payload = $this->ledger->walletReconciliation($limit);
        $this->auditRead($request, 'wallet_reconciliation', ['limit' => $limit]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function merchantPortfolio(Request $request): JsonResponse
    {
        $payload = $this->p1->merchantPortfolio();
        $this->auditRead($request, 'merchant_portfolio', []);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function kycPipeline(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $payload = $this->p1->kycPipeline($from, $to);
        $this->auditRead($request, 'kyc_pipeline', ['from' => $from, 'to' => $to]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function agentLiquidity(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), ['date' => ['nullable', 'date_format:Y-m-d']]);
        abort_if($validator->fails(), 422, $validator->errors()->first());
        $date = (string) ($request->query('date') ?: now()->toDateString());
        $payload = $this->p1->agentLiquidity($date);
        $this->auditRead($request, 'agent_float', ['date' => $date]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function auditSensitiveActions(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $limit = $this->auditLimit($request);
        $payload = $this->p1->auditSensitiveActions($from, $to, $limit);
        $this->auditRead($request, 'audit_sensitive_actions', ['from' => $from, 'to' => $to, 'limit' => $limit]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function rbacChanges(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $limit = $this->auditLimit($request);
        $payload = $this->p1->rbacChanges($from, $to, $limit);
        $this->auditRead($request, 'rbac_changes', ['from' => $from, 'to' => $to, 'limit' => $limit]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function subscriptions(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $payload = $this->businessOps->subscriptions($from, $to);
        $this->auditRead($request, 'subscriptions', ['from' => $from, 'to' => $to]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function customerActivity(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $payload = $this->businessOps->customerActivity($from, $to);
        $this->auditRead($request, 'customer_activity', ['from' => $from, 'to' => $to]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function supportOperations(Request $request): JsonResponse
    {
        [$from, $to] = $this->period($request);
        $payload = $this->businessOps->supportOperations($from, $to);
        $this->auditRead($request, 'support_sla', ['from' => $from, 'to' => $to]);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    public function amlRegulatory(Request $request): JsonResponse
    {
        $payload = $this->aml->metrics();
        $payload['report'] = 'aml_regulatory';
        $payload['basis'] = 'AML rules + evaluations + investigations + regulatory reports';
        $this->auditRead($request, 'aml_regulatory', []);
        return response()->json(['success' => true, 'meta' => $payload]);
    }

    private function auditLimit(Request $request): int
    {
        $validator = Validator::make($request->query(), ['limit' => ['nullable', 'integer', 'min:10', 'max:200']]);
        abort_if($validator->fails(), 422, $validator->errors()->first());
        return (int) $request->query('limit', 50);
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

    private function asOf(Request $request): string
    {
        $validator = Validator::make($request->query(), ['as_of' => ['nullable', 'date_format:Y-m-d']]);
        abort_if($validator->fails(), 422, $validator->errors()->first());
        return (string) ($request->query('as_of') ?: now()->toDateString());
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
