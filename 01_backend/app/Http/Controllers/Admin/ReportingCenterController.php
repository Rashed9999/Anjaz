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
use App\Services\Reporting\P1MerchantOperationsReportService;
use App\Services\Reporting\P1ObservabilityReportService;
use App\Services\Reporting\P1VerticalPerformanceReportService;
use App\Services\Reporting\P2IdentityCommunicationReportService;
use App\Services\Reporting\ReportCatalogService;
use App\Services\Reporting\TransactionMonitoringReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/** AMIAL-REPORTING-CENTER — بوابة موحدة لمصادر الحقيقة، مع Audit لكل قراءة. */
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
        private readonly P1MerchantOperationsReportService $merchantOps,
        private readonly P1ObservabilityReportService $observability,
        private readonly P1VerticalPerformanceReportService $verticals,
        private readonly P2IdentityCommunicationReportService $identityOps,
        private readonly AmlDashboardService $aml,
        private readonly FeeProfitReportService $fees,
        private readonly LedgerReportService $ledger,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request)
    {
        $actor = auth('user')->user();
        $summary = $this->catalog->summary();
        $this->audit->record([
            'actor_type' => 'admin', 'actor_user_id' => $actor?->id,
            'subject_type' => 'reporting_center', 'action' => 'REPORTING_CENTER_VIEWED',
            'decision_code' => 'ALLOW', 'severity' => 'info',
            'context' => ['catalog_total' => $summary['total']],
        ]);

        // AMIAL-REPORTING-UX-003 — V3 هي لوحة القرار الافتراضية.
        // نُبقي V2 والعرض التشغيلي القديم كمسارات رجوع آمنة أثناء التبني.
        $view = $request->boolean('legacy')
            ? 'admin-views.amial.reporting-center.index'
            : ($request->integer('dashboard') === 2
                ? 'admin-views.amial.reporting-center.index-v2'
                : 'admin-views.amial.reporting-center.index-v3');

        return view($view, [
            'catalog' => $this->catalog->catalog(), 'summary' => $summary,
            'canExport' => (bool) $actor?->hasPlatformPermission('platform.reports.export'),
        ]);
    }

    public function trialBalance(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'trial_balance',$this->statements->trialBalance($f,$t),['from'=>$f,'to'=>$t]); }
    public function incomeStatement(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'income_statement',$this->statements->incomeStatement($f,$t),['from'=>$f,'to'=>$t]); }
    public function balanceSheet(Request $r): JsonResponse { $d=$this->asOf($r); return $this->out($r,'balance_sheet',$this->statements->balanceSheet($d),['as_of'=>$d]); }
    public function cashFlow(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'cash_flow',$this->cashLiquidity->cashFlow($f,$t),['from'=>$f,'to'=>$t]); }
    public function liquidity(Request $r): JsonResponse { $d=$this->asOf($r); return $this->out($r,'liquidity_position',$this->cashLiquidity->liquidityPosition($d),['as_of'=>$d]); }
    public function safeguardedFunds(Request $r): JsonResponse { $d=$this->asOf($r); return $this->out($r,'safeguarded_funds',$this->cashLiquidity->safeguardedFunds($d),['as_of'=>$d]); }
    public function transactionVolume(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'transaction_volume',$this->transactions->volume($f,$t),['from'=>$f,'to'=>$t]); }

    public function transactionExceptions(Request $r): JsonResponse
    {
        [$f,$t]=$this->period($r); $limit=$this->limit($r);
        return $this->out($r,'failed_reversed_pending',$this->transactions->exceptions($f,$t,$limit),['from'=>$f,'to'=>$t,'limit'=>$limit]);
    }

    public function generalLedger(Request $r): JsonResponse
    {
        $v=Validator::make($r->query(),[
            'from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d','after_or_equal:from'],
            'currency'=>['nullable','string','max:8'],'source_type'=>['nullable','string','max:100'],
            'account_code'=>['nullable','string','max:100'],'search'=>['nullable','string','max:120'],
            'page'=>['nullable','integer','min:1'],'limit'=>['nullable','integer','min:10','max:100'],
        ]); abort_if($v->fails(),422,$v->errors()->first()); $filters=$v->validated();
        return $this->out($r,'general_ledger',$this->generalLedger->report($filters),$filters);
    }

    public function feesCommissions(Request $r): JsonResponse
    {
        [$f,$t]=$this->period($r); $from=Carbon::parse($f?:now()->startOfMonth()->toDateString())->startOfDay(); $to=Carbon::parse($t?:now()->toDateString())->endOfDay();
        $p=$this->fees->forPeriod($from,$to); $p['from']=$from->toDateString(); $p['to']=$to->toDateString(); $p['report']='fees_commissions'; $p['basis']='transactions charges + measured platform/agent credits';
        return $this->out($r,'fees_commissions',$p,['from'=>$p['from'],'to'=>$p['to']]);
    }

    public function reconciliation(Request $r): JsonResponse
    {
        $limit=min(500,max(10,(int)$r->query('limit',100)));
        return $this->out($r,'wallet_reconciliation',$this->ledger->walletReconciliation($limit),['limit'=>$limit]);
    }

    public function merchantPortfolio(Request $r): JsonResponse { return $this->out($r,'merchant_portfolio',$this->p1->merchantPortfolio(),[]); }
    public function kycPipeline(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'kyc_pipeline',$this->p1->kycPipeline($f,$t),['from'=>$f,'to'=>$t]); }

    public function agentLiquidity(Request $r): JsonResponse
    {
        $v=Validator::make($r->query(),['date'=>['nullable','date_format:Y-m-d']]); abort_if($v->fails(),422,$v->errors()->first()); $d=(string)($r->query('date')?:now()->toDateString());
        return $this->out($r,'agent_float',$this->p1->agentLiquidity($d),['date'=>$d]);
    }

    public function auditSensitiveActions(Request $r): JsonResponse { [$f,$t]=$this->period($r); $l=$this->limit($r); return $this->out($r,'audit_sensitive_actions',$this->p1->auditSensitiveActions($f,$t,$l),['from'=>$f,'to'=>$t,'limit'=>$l]); }
    public function rbacChanges(Request $r): JsonResponse { [$f,$t]=$this->period($r); $l=$this->limit($r); return $this->out($r,'rbac_changes',$this->p1->rbacChanges($f,$t,$l),['from'=>$f,'to'=>$t,'limit'=>$l]); }
    public function subscriptions(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'subscriptions',$this->businessOps->subscriptions($f,$t),['from'=>$f,'to'=>$t]); }
    public function customerActivity(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'customer_activity',$this->businessOps->customerActivity($f,$t),['from'=>$f,'to'=>$t]); }
    public function supportOperations(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'support_sla',$this->businessOps->supportOperations($f,$t),['from'=>$f,'to'=>$t]); }
    public function inventoryControl(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'inventory_valuation',$this->merchantOps->inventoryControl($f,$t),['from'=>$f,'to'=>$t]); }
    public function creditControl(Request $r): JsonResponse { return $this->out($r,'credit_aging',$this->merchantOps->creditControl(),[]); }
    public function verticalPerformance(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'vertical_performance',$this->verticals->report($f,$t),['from'=>$f,'to'=>$t]); }
    public function healthHistory(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'system_health_history',$this->observability->healthHistory($f,$t),['from'=>$f,'to'=>$t]); }
    public function queueOperations(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'jobs_queues',$this->observability->queueOperations($f,$t),['from'=>$f,'to'=>$t]); }
    public function emailOtp(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'email_otp',$this->identityOps->emailOtp($f,$t),['from'=>$f,'to'=>$t]); }
    public function authSecurity(Request $r): JsonResponse { [$f,$t]=$this->period($r); return $this->out($r,'auth_security',$this->identityOps->authenticationSecurity($f,$t),['from'=>$f,'to'=>$t]); }

    public function amlRegulatory(Request $r): JsonResponse
    {
        $p=$this->aml->metrics(); $p['report']='aml_regulatory'; $p['basis']='AML rules + evaluations + investigations + regulatory reports';
        return $this->out($r,'aml_regulatory',$p,[]);
    }

    private function limit(Request $r): int
    {
        $v=Validator::make($r->query(),['limit'=>['nullable','integer','min:10','max:200']]); abort_if($v->fails(),422,$v->errors()->first());
        return (int)$r->query('limit',50);
    }

    private function period(Request $r): array
    {
        $v=Validator::make($r->query(),['from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d','after_or_equal:from']]); abort_if($v->fails(),422,$v->errors()->first());
        return [$r->query('from')?:null,$r->query('to')?:null];
    }

    private function asOf(Request $r): string
    {
        $v=Validator::make($r->query(),['as_of'=>['nullable','date_format:Y-m-d']]); abort_if($v->fails(),422,$v->errors()->first());
        return (string)($r->query('as_of')?:now()->toDateString());
    }

    private function out(Request $r,string $report,array $payload,array $filters): JsonResponse
    {
        $this->auditRead($r,$report,$filters);
        return response()->json(['success'=>true,'meta'=>$payload]);
    }

    private function auditRead(Request $r,string $report,array $filters): void
    {
        $actor=auth('user')->user();
        $this->audit->record([
            'actor_type'=>'admin','actor_user_id'=>$actor?->id,'subject_type'=>'report','subject_id'=>$report,
            'action'=>'REPORT_VIEWED','decision_code'=>'ALLOW','severity'=>'info',
            'context'=>['report'=>$report,'filters'=>$filters,'request_id'=>$r->header('X-Request-ID')],
        ]);
    }
}
